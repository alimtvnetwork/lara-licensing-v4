# Runbooks

**Version:** 1.0.0
**Updated:** 2026-07-20
**AI Confidence:** Draft
**Ambiguity:** Low

---

## Keywords

`runbook` · `operator` · `incident` · `kill-switch` · `chain-break` · `stuck-job` · `corrupt-archive` · `legal-hold` · `on-call`

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

Pin the operator runbooks for the seven closed-set Backup /
Restore incident classes. Files 04..25 declared the module's
schema, endpoints, FE, observability, audit, tests, and rollout,
but no file has told on-call HOW to respond when: an archive
fails preflight with `BackupCorrupt`, a job hangs in `Running`
past its heartbeat, the audit-chain verifier flags a break,
legal serves an erasure request, a KEK epoch must be purged
under a live Export, the kill-switch drill is scheduled, or a
tenant is stuck in `Retry-After` loops. Without runbooks, an S3
canary regression forces improvised recovery on hot traffic, a
direct violation of `INV-BR-MG-6`.

---

## Global Preconditions

Every runbook below assumes the operator has:

1. `Role.OnCall` capability (from `03-permission-matrix.md`) and
   an authenticated `lara-diag` session, so every step emits
   auditable rows with a real `actorUserId`.
2. Access to the `br-ops` command surface (introduced in Plan 14).
3. Confirmed `br.kill-switch` state before mutating anything;
   most runbooks toggle it as their first step per `INV-BR-MG-6`.
4. A paged incident ticket. No runbook step runs outside an
   incident record.

---

## RB-01: Corrupt Archive on Import

**Trigger.** Preflight returns `BackupCorrupt.*` (any reason
from `12-endpoint-import.md`) OR a Restore aborts with
`BackupCorrupt.audit_chain_break_on_import` from
`23-audit-and-compliance.md`.

**Severity.** P2 for a single tenant; P1 if two or more tenants
in one hour.

**Steps.**

1. Capture the `ErrorId` and `RequestId` from the failing
   response; pull the `lara-diag` chain by `RequestId`.
2. Fetch the archive's manifest header (`br-ops archive:header
   <ingest-id>`) and confirm which sub-code fired
   (`bad_magic`, `manifest_signature`, `body_hash`, `truncated`,
   `purged_epoch`, `audit_chain_break_on_import`).
3. If sub-code is `purged_epoch`, follow RB-05 next.
4. If sub-code is `audit_chain_break_on_import`, follow RB-03
   next (the source tenant likely has a live chain break).
5. Move the offending archive to `s3://br-quarantine/<ingest-id>/`
   with `br-ops archive:quarantine`; DO NOT delete.
6. Notify the source tenant with the closed-set copy string
   `BR.INCIDENT.QUARANTINED` and request they re-Export.
7. File a forensic ticket with the manifest header attached.

**Do-not.** Never patch the archive bytes. Never delete the
original.

---

## RB-02: Stuck Job (Running past heartbeat)

**Trigger.** `backup_jobs.state='Running'` AND
`updated_at < now() - interval '10 minutes'` (heartbeat window
from `15-jobs-and-progress.md`).

**Severity.** P2.

**Steps.**

1. `br-ops jobs:show <jobId>`; capture `initiatingRequestId`,
   `kind`, `lockKeys`, and `attempts`.
2. Query the worker's SSE stream (`br-ops jobs:tail <jobId>`)
   for the last 60 events. If sequence is monotonic and recent
   (< heartbeat window) then the DB row is stale, not the job;
   proceed to step 6 only.
3. If the worker is unreachable, confirm via metrics
   (`worker_up{jobId=...}==0`).
4. Fence the job: `br-ops jobs:fence <jobId>`, which acquires
   `br.global` rank-1 lock and transitions the row to
   `Failed(reason=fenced_by_operator)` with a `backup.job.fenced`
   audit row (owned by this file; see catalogue extension below).
5. If the job kind is idempotent (Export, Snapshot list), let
   the client retry with the same `Idempotency-Key`; a new job
   row is created and the old key returns the fenced result.
6. If the job kind is Restore, DO NOT auto-retry: manual review
   of the target scope is required before re-running.

**Catalogue extension.**

| Code                     | Actor  | Trigger                                              |
|--------------------------|--------|------------------------------------------------------|
| `backup.job.fenced`      | user   | Operator fenced a stuck job via RB-02.               |

BR audit catalogue grows to 35 codes with this addition.

**Do-not.** Never `UPDATE backup_jobs SET state='Failed'` by
hand; the fence command emits the audit row and holds the lock.

---

## RB-03: Audit Chain Break Detected

**Trigger.** `backup.audit.chain_break_detected` emitted by the
daily verifier from `23-audit-and-compliance.md`.

**Severity.** P1 always. Chain-break implies either tampering
or a bug in the `BEFORE INSERT` trigger.

**Steps.**

1. Trip `br.kill-switch=on` immediately per `INV-BR-MG-6`.
2. `br-ops audit:verify --shard <shardId> --from <rowId>` to
   locate the first mismatched row; capture its `id`,
   `occurredAt`, `code`, `rowHash`, and expected hash.
3. Freeze `fn_pseudonymise_actor` (`br-ops audit:freeze-pseudo`)
   so no legitimate rewrite races the investigation.
4. Snapshot the shard's audit table with `pg_dump --data-only
   --table=backup_audit_events` under a serialisable tx; store
   the dump in `s3://br-forensics/<incident-id>/`.
5. Determine whether the break is (a) tampering (rows exist
   but hashes don't match), (b) missing row (a `prevHash` refers
   to an id that doesn't exist), or (c) trigger regression (all
   rows after a deploy have wrong hashes).
6. For (c), roll forward with a compensating migration that
   recomputes hashes AND emits one `backup.audit.chain_repaired`
   row per shard with the operator's `actorUserId` and the
   deploy id as `payload.deployId`. Never for (a) or (b): those
   escalate to security.
7. Un-freeze pseudonymisation; flip `br.kill-switch=off` only
   after chain verifier is green.

**Catalogue extension.**

| Code                          | Actor  | Trigger                                              |
|-------------------------------|--------|------------------------------------------------------|
| `backup.audit.chain_repaired` | user   | Operator ran a compensating recompute per RB-03.     |

BR audit catalogue grows to 36 codes.

---

## RB-04: Legal Hold / GDPR Erasure

**Trigger.** Legal issues an erasure request for a data subject
OR a legal-hold flag arrives for a shard.

**Severity.** P3, but SLA-bound (30 days for GDPR).

**Steps.**

1. Confirm the request has legal signoff (`legalBasis` field
   populated) per `23-audit-and-compliance.md`.
2. For legal-hold: set `br_shard_flags.legal_hold=true`; the
   retention sweeper skips the shard; pseudonymiser refuses
   with `Audit.PseudonymiseBlockedByLegalHold`.
3. For erasure: confirm no un-consumed Export references the
   subject (`br-ops audit:pseudo-preflight --user <uuid>`);
   pseudonymiser refuses with
   `Audit.PseudonymiseBlockedByExport` otherwise.
4. Invoke `br-ops audit:pseudonymise --user <uuid> --basis
   <case-id>`; the procedure cascades `rowHash` recompute per
   `INV-BR-AU-5`.
5. Verify chain integrity post-run with `br-ops audit:verify
   --shard <shardId>`; MUST return green.
6. File a legal-response artifact including the resulting
   `backup.audit.pseudonymised` row id.

**Do-not.** Never delete audit rows to satisfy erasure. Ever.

---

## RB-05: KEK Epoch Purge Under Live Export

**Trigger.** Operator requests a KEK purge but an in-flight
Export is signing with that epoch.

**Severity.** P2.

**Steps.**

1. `br-ops kek:epochs` to list live and pending-purge epochs.
2. `br-ops jobs:list --state=Running --uses-epoch <epochId>` to
   enumerate blockers.
3. If any exist, either (a) drain: wait for jobs to complete
   (they carry `retryAfterSeconds` to clients so nothing hangs);
   or (b) fence per RB-02 if drain time exceeds SLA.
4. Once no live jobs use the epoch, mark it `pending_purge`;
   the purger job (`br.crypto.purge_epoch`) runs on the next
   scheduler tick and emits `backup.crypto.epoch_purged`.
5. Confirm no archive in warm storage still references the
   epoch (`br-ops archives:by-epoch <epochId>`). If any remain,
   they become unreadable post-purge by design (forward
   secrecy from `10-secrets-forward-secrecy.md`); notify
   affected tenants using `BR.NOTICE.EPOCH_PURGED`.

**Do-not.** Never purge an epoch while a Snapshot has
`Sealed=false` (not-yet-sealed snapshots are still writing).

---

## RB-06: Kill-Switch Drill

**Trigger.** Scheduled quarterly drill (Plan 14 schedules it) or
ad-hoc regression response.

**Severity.** N/A (drill) or matches the incident (ad-hoc).

**Steps.**

1. Announce in the operator channel with the drill ticket id.
2. Flip `br.kill-switch=on`; verify `503 ModuleDisabled` with
   `Retry-After` on all seven endpoint families.
3. Confirm in-flight jobs finish or cancel per
   `15-jobs-and-progress.md`; no new jobs accepted.
4. Verify audit rows land as usual (`br.kill-switch` MUST NOT
   silence the audit trigger; violation escalates to P1).
5. After 15 minutes, flip `br.kill-switch=off`; verify traffic
   returns and no residual `503` after one minute.
6. File the drill artifact; success requires zero corrupt-chain
   rows and zero orphaned `backup_jobs`.

**Do-not.** Never test the kill-switch in production without a
drill ticket. Never leave it `on` past the drill window; page
on-call after 30 min stuck-on.

---

## RB-07: Retry-After Storm

**Trigger.** A tenant's FE hits repeated `Retry-After` responses
for over 15 minutes on Export or Restore (identified by
`retryAfterSeconds` in `lara-diag` FE rows from
`22-observability.md`).

**Severity.** P3.

**Steps.**

1. `br-ops locks:holders --tenant <id>` to identify who holds
   the rank-1 `br.global` lock for that tenant's shard.
2. If the holder is a legitimate job with progress, communicate
   the ETA to the tenant via `BR.NOTICE.QUEUE_ETA`.
3. If the holder is a fenced or zombie lock (holder job is
   `Failed` but lock not released), invoke `br-ops
   locks:release --key <key> --reason zombie`; MUST emit
   `backup.lock.zombie_released` (owned by this file).
4. If the storm is caused by client retry-loop misbehaviour
   (repeated identical Idempotency-Keys), coordinate with
   Support to update the client SDK; DO NOT bump server
   `Retry-After` to mask a client bug.

**Catalogue extension.**

| Code                             | Actor  | Trigger                                              |
|----------------------------------|--------|------------------------------------------------------|
| `backup.lock.zombie_released`    | user   | Operator released a lock whose holder was already `Failed`. |

BR audit catalogue grows to 37 codes.

---

## Runbook Closed Set

The seven runbooks above are the closed set. Adding a runbook
requires a version bump of this file AND a corresponding row
in the catalogue extensions table.

| Runbook | Trigger source                          | Owning spec files |
|---------|-----------------------------------------|-------------------|
| RB-01   | `BackupCorrupt.*` from Preflight/Restore | 12, 14, 23        |
| RB-02   | Heartbeat window exceeded                | 15                |
| RB-03   | `backup.audit.chain_break_detected`      | 22, 23            |
| RB-04   | Legal request                            | 23                |
| RB-05   | KEK epoch purge with live Export         | 09, 10            |
| RB-06   | Quarterly drill or ad-hoc                | 25                |
| RB-07   | `Retry-After` loop > 15 min              | 15, 16, 22        |

---

## Invariants

| ID              | Rule |
|-----------------|------|
| INV-BR-RB-1     | Every runbook step MUST run under an incident ticket and an authenticated `Role.OnCall` session. |
| INV-BR-RB-2     | Runbooks MUST trip `br.kill-switch=on` before mutating state when the incident risks data integrity (RB-03 always, RB-01 on multi-tenant scope, RB-05 when drain fails). |
| INV-BR-RB-3     | Direct `UPDATE`/`DELETE` on `backup_jobs`, `backup_audit_events`, or `backup_snapshots` is forbidden; all mutations go through `br-ops` commands that emit audit rows. |
| INV-BR-RB-4     | RB-04 MUST NOT delete audit rows to satisfy erasure; pseudonymisation is the only supported path. |
| INV-BR-RB-5     | RB-05 MUST refuse to purge a KEK epoch while any Snapshot with `Sealed=false` still references it. |
| INV-BR-RB-6     | RB-06 kill-switch drills MUST verify the audit trigger still fires; a silent audit trigger during a drill escalates to P1. |
| INV-BR-RB-7     | RB-02 fences use the `br.global` rank-1 lock and emit `backup.job.fenced`; hand-edits are forbidden. |
| INV-BR-RB-8     | The BR audit catalogue grows from 34 to 37 codes with `backup.job.fenced`, `backup.audit.chain_repaired`, and `backup.lock.zombie_released` owned by this file. |
| INV-BR-RB-9     | The runbook set is the seven above; adding an RB requires a version bump of this file. |
| INV-BR-RB-10    | Client retry-loop bugs (RB-07) MUST be fixed on the client; the server MUST NOT bump `Retry-After` to mask them. |

---

## Cross-References

- [`12-endpoint-import.md`](./12-endpoint-import.md), [`14-endpoint-restore.md`](./14-endpoint-restore.md): `BackupCorrupt.*` producers for RB-01.
- [`15-jobs-and-progress.md`](./15-jobs-and-progress.md): heartbeat window and job state machine for RB-02.
- [`16-idempotency-and-locks.md`](./16-idempotency-and-locks.md): rank-1 `br.global` lock referenced by RB-02, RB-05, RB-07.
- [`22-observability.md`](./22-observability.md), [`23-audit-and-compliance.md`](./23-audit-and-compliance.md): audit catalogue extended by three codes owned here.
- [`25-migration-and-rollout.md`](./25-migration-and-rollout.md): kill-switch semantics used by RB-06 and RB-03.
- [`spec/03-error-manage/`](../03-error-manage/00-overview.md): envelope shape returned by `503 ModuleDisabled`.
