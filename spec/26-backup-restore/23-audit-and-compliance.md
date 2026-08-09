# Audit and Compliance

**Version:** 1.0.0
**Updated:** 2026-07-20
**AI Confidence:** Draft
**Ambiguity:** Low

---

## Keywords

`audit` · `immutable` · `hash-chain` · `gdpr` · `erasure` · `pseudonymisation` · `retention` · `audit-slice` · `compliance`

---

## Scoring

| Criterion | Status |
|-----------|--------|
| `00-overview.md` present in module | ✅ |
| AI Confidence assigned | ✅ |
| Ambiguity assigned | ✅ |
| Keywords present | ✅ |
| Scoring table present | ✅ |

---

## Purpose

Pin the audit trail row schema, immutability guarantees, retention
window, and GDPR handling for the Backup / Restore / Snapshot
module. The 29-code audit event catalogue is closed in
[`22-observability.md`](./22-observability.md), but no file has
governed the row shape, the append-only hash-chain that makes the
trail tamper-evident, the tension between GDPR right-to-erasure and
immutable audit (resolved by pseudonymisation, never row
deletion), or the rule that an Export archive MUST include its own
audit slice up to `producedAt` (so restores replay history with
verifiable continuity). Without these, `INV-BR-A` (auditability)
cannot be tested and step 25's compliance fixtures have no target.

---

## Row Schema

Table: `backup_audit_events`. Append-only; the DB grants withhold
`UPDATE` and `DELETE` from all application roles. Only the
`audit_pseudonymiser` migration role holds `UPDATE` on the
pseudonymisation columns, and only via the vetted procedure
defined below.

| Column                | Type                       | Rules                                                                                       |
|-----------------------|----------------------------|---------------------------------------------------------------------------------------------|
| `id`                  | `uuid v7`                  | Primary key, monotonic per shard.                                                           |
| `occurredAt`          | `timestamptz`              | Server clock at emit.                                                                       |
| `code`                | `text`                     | Closed set from `22-observability.md` audit catalogue (29 codes).                           |
| `actorKind`           | `enum {user,worker,server,pep}` | Matches the catalogue column.                                                          |
| `actorUserId`         | `uuid v7 NULL`             | Present when `actorKind='user'`. Nullable after pseudonymisation.                            |
| `actorRole`           | `text NULL`                | Effective role at emit time.                                                                |
| `requestId`           | `uuid v7`                  | Always present. `INV-BR-OB-1`.                                                              |
| `jobId`               | `uuid v7 NULL`             | Present for jobful codes.                                                                   |
| `errorId`             | `uuid v7 NULL`             | Present for terminal-failure codes.                                                         |
| `snapshotId`          | `uuid v7 NULL`             | Present for snapshot codes.                                                                 |
| `policyVersion`       | `integer NULL`             | Present for `rbac.policy.*`.                                                                |
| `payload`             | `jsonb`                    | Redacted per `redactor.crypto`+`redactor.pii`; bounded 4 KiB.                                |
| `prevHash`            | `bytea(32)`                | SHA-256 of the previous row in this shard's chain; `x'00'*32` for the genesis row.          |
| `rowHash`             | `bytea(32)`                | `SHA-256(concat(id, occurredAt, code, ..., prevHash))` computed inside the insert trigger.  |
| `shardId`             | `text`                     | Shard the event belongs to (for multi-tenant).                                              |
| `schemaVersion`       | `integer`                  | Increment when the hash input changes.                                                      |

Grants (per `spec/25-app-audit/` public-schema rules):

```sql
GRANT SELECT ON public.backup_audit_events TO authenticated;
GRANT INSERT ON public.backup_audit_events TO authenticated;
GRANT ALL    ON public.backup_audit_events TO service_role;
-- No UPDATE, no DELETE to any application role.
```

RLS policies:

1. `SELECT` allowed to `authenticated` iff `has_role(auth.uid(), 'auditor')` OR the row's `shardId` matches the caller's tenant.
2. `INSERT` allowed to `authenticated` iff the payload passes the redactor gate; enforced by a `BEFORE INSERT` trigger that rejects rows with `redactor.violation=true`.
3. No `UPDATE`/`DELETE` policy is created; PostgreSQL treats the absence as deny.

---

## Hash-Chain Guarantees

1. Every row's `rowHash` is computed inside a `BEFORE INSERT`
   trigger; the application cannot supply it.
2. The trigger reads `prevHash` from the last row for the same
   `shardId` under `pg_advisory_xact_lock('audit.chain:'||shardId)`
   so concurrent inserts serialise per shard.
3. A daily background verifier walks each shard's chain and emits
   `backup.audit.chain_break_detected` (added to the closed set in
   this file's cross-reference table below) if any `rowHash`
   disagrees. This code is emitted at level `error`.
4. The chain is not global; it is per-shard so shard-scoped
   Exports can carry a complete verifiable slice.

Extension to the closed-set audit catalogue in
`22-observability.md`:

| Code                                | Actor  | Trigger                                              |
|-------------------------------------|--------|------------------------------------------------------|
| `backup.audit.chain_break_detected` | worker | Verifier found a `rowHash` mismatch or missing row.  |
| `backup.audit.pseudonymised`        | worker | GDPR pseudonymisation procedure applied to a subject.|

The 29-code catalogue thereby grows to 31 codes; this file owns
the two additions above and updates the total.

---

## Retention

| Retention tier                | Window   | Sweeper action                                                                    |
|-------------------------------|----------|-----------------------------------------------------------------------------------|
| Operational (default)         | 90 days  | None. Rows are kept online.                                                       |
| Archived                      | 7 years  | Rows moved to `backup_audit_events_archive` (same schema + hash chain). Chain continuity preserved by inserting a `chain_transition` row with the last `rowHash`. |
| Legal hold                    | Indefinite | On operator flag, retention sweeper skips the shard.                             |

The sweeper is a job kind `audit.retention_sweep` and emits
`backup.audit.retention_swept` on success. Retention MUST NOT
delete rows; it archives them. The chain continues in the archive
table.

---

## GDPR Right-to-Erasure

The audit trail is immutable, so a data-subject erasure request
CANNOT delete audit rows. Instead, the vetted
`fn_pseudonymise_actor(uuid)` SECURITY DEFINER procedure runs
under the `audit_pseudonymiser` role and:

1. Overwrites `actorUserId` with the subject's pseudonymous id
   (deterministic HMAC over a project-scoped secret and the
   original `actorUserId`; the secret is rotated per epoch so
   pseudonyms are not linkable across epochs).
2. Overwrites any PII inside `payload` fields listed in
   `redactor.pii` config with the sentinel string
   `"pseudonymised"`.
3. Recomputes `rowHash` for each touched row AND every subsequent
   row in the same shard's chain up to the current tail; this is
   an audited rewrite performed inside one advisory-locked
   transaction and emits one `backup.audit.pseudonymised` row per
   subject at the tail of the chain.
4. The operator MUST attach a `legalBasis` field to the
   pseudonymisation request; the emitted event records it.

Rationale: pseudonymisation preserves the hash-chain (by re-linking
it) while making the actor unrecoverable. This is the only
supported way to satisfy an erasure request against immutable
audit data. Row deletion is forbidden even for erasure.

Constraints:

1. Pseudonymisation MUST NOT run against rows referenced by an
   Export archive that has not yet been consumed or deleted; the
   procedure refuses with error code `Audit.PseudonymiseBlockedByExport`.
2. Legal-hold shards refuse the procedure with
   `Audit.PseudonymiseBlockedByLegalHold`.

---

## Audit Slice Inside Export

Every Export archive produced by `11-endpoint-export.md` MUST
contain a `scope/audit/*.jsonl.zst` slice with every
`backup_audit_events` row for the scope's shards where
`occurredAt <= manifest.producedAt`. Rules:

1. The slice is scoped: only rows for the shards named in the
   Export scope are included.
2. Each JSON line is one audit row exactly as stored (including
   `rowHash` and `prevHash`); redaction is NOT re-applied because
   redaction ran at insert.
3. The Export manifest MUST include the last `rowHash` per shard
   under `manifest.auditChainTails[shardId] = rowHash`; on Restore
   the verifier compares each tail to the recomputed value and
   fails with `BackupCorrupt` reason `audit_chain_break_on_import`
   on mismatch.
4. A Snapshot references the audit slice by pointer contract from
   `13-endpoint-snapshot.md`; Snapshot Restore MUST also verify
   `auditChainTails`.

---

## Restore Handling

On Restore apply:

1. The audit slice is imported first, under the outer apply tx.
2. Each row is inserted via the standard `BEFORE INSERT` trigger,
   which re-computes `rowHash` from the imported `prevHash`. The
   result MUST match the row's own `rowHash` byte-for-byte; a
   mismatch aborts the outer tx with
   `BackupCorrupt.audit_chain_break_on_import`.
3. After the audit slice is applied, one
   `backup.restore.audit_imported` event is emitted at the tail
   of the target shard's chain, citing the source archive's
   manifest hash. This is a 32nd event code owned by this file:

| Code                              | Actor  | Trigger                                              |
|-----------------------------------|--------|------------------------------------------------------|
| `backup.restore.audit_imported`   | worker | Audit slice imported inside Restore apply tx.        |

The BR audit catalogue total is now 32 codes.

---

## Compliance Notes

1. GDPR Article 17 (right to erasure) is satisfied by
   pseudonymisation per this file, not by row deletion. The DPIA
   MUST document this pattern.
2. Article 30 (records of processing activities) is served by the
   audit trail itself; every mutating BR action produces a row.
3. Article 32 (security of processing) is served jointly by the
   hash chain, redactor gate, and encryption specs (09..10).
4. Data-subject access requests are served by an `Audit.Read`
   view that filters rows by pseudonymised or raw actor id; no
   new endpoint is required.

Non-GDPR jurisdictions (HIPAA, SOX) are not in scope for this
version; a follow-up file MAY extend the retention tiers.

---

## Invariants

| ID              | Rule |
|-----------------|------|
| INV-BR-AU-1     | `backup_audit_events` MUST be append-only; no `UPDATE`/`DELETE` grants exist for application roles. |
| INV-BR-AU-2     | Every row's `rowHash` MUST be computed by the `BEFORE INSERT` trigger; application-supplied values are rejected. |
| INV-BR-AU-3     | Chain continuity is per-shard; the daily verifier MUST emit `backup.audit.chain_break_detected` on any mismatch. |
| INV-BR-AU-4     | GDPR erasure is served by `fn_pseudonymise_actor` under the `audit_pseudonymiser` role; row deletion is forbidden even for erasure. |
| INV-BR-AU-5     | Pseudonymisation re-computes `rowHash` for every affected row AND all subsequent rows in the shard's chain in one advisory-locked transaction. |
| INV-BR-AU-6     | Every Export archive MUST carry a `scope/audit/*.jsonl.zst` slice for the scope's shards up to `producedAt`, and the manifest MUST pin `auditChainTails` per shard. |
| INV-BR-AU-7     | Restore MUST verify `auditChainTails` after applying the audit slice; a mismatch aborts the outer tx with `BackupCorrupt.audit_chain_break_on_import`. |
| INV-BR-AU-8     | Retention MUST archive, never delete; the archive table preserves the chain via a `chain_transition` row. |
| INV-BR-AU-9     | Pseudonymisation refuses on shards referenced by an un-consumed Export or under legal hold, with the two dedicated error codes. |
| INV-BR-AU-10    | The BR audit event catalogue is now 32 codes (29 from `22-observability.md` + 3 owned here: `chain_break_detected`, `pseudonymised`, `restore.audit_imported`); adding a code requires a version bump of either owning file. |

---

## Cross-References

- [`22-observability.md`](./22-observability.md): 29-code base catalogue, `RequestId` propagation, redactor rules.
- [`11-endpoint-export.md`](./11-endpoint-export.md): consumer of the audit-slice packaging rule.
- [`12-endpoint-import.md`](./12-endpoint-import.md), [`14-endpoint-restore.md`](./14-endpoint-restore.md): consumers of the audit-chain verification rule.
- [`13-endpoint-snapshot.md`](./13-endpoint-snapshot.md): SC-H pin semantics and audit-slice pointer.
- [`09-encryption-and-keys.md`](./09-encryption-and-keys.md), [`10-secrets-forward-secrecy.md`](./10-secrets-forward-secrecy.md): key epochs cited when redactor logs `epochId`.
- [`spec/03-error-manage/`](../03-error-manage/00-overview.md): `LaraException` codes `Audit.PseudonymiseBlockedByExport`, `Audit.PseudonymiseBlockedByLegalHold`, `BackupCorrupt.audit_chain_break_on_import`.
