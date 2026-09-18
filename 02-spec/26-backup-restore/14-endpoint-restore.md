# Endpoint: Restore

**Version:** 1.0.0
**Updated:** 2026-07-20
**AI Confidence:** Draft
**Ambiguity:** Low

---

## Keywords

`endpoint` · `restore` · `apply` · `archive-id` · `snapshot-id` · `reseal` · `atomic-tx` · `dry-run` · `rollback` · `capability`

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

Pin the HTTP contract for the Restore orchestrator, the sole
consumer of `Verified` archives (from
[`12-endpoint-import.md`](./12-endpoint-import.md)) and `Sealed`
snapshots (from [`13-endpoint-snapshot.md`](./13-endpoint-snapshot.md)).
Restore is the only entry point that mutates live tables from a
foreign or historical byte stream, so `INV-BR-A` (atomicity),
`INV-BR-EK-5` (re-seal under Active KEK), `INV-BR-FS-4`
(Purged-epoch failure), and `INV-BR-EP-SN-6` (dangling SC-H
pointer failure) all converge here. Without a pinned contract,
downstream specs 15..19 have no canonical shape to bind to.

---

## Route

```
POST /api/admin/backup/restores
```

- Middleware order (from `02-casbin-integration.md`): `RequestId`, `Sanctum`, `CasbinPepMiddleware`, route.
- Capability required: `Backup.Restore` (`03-permission-matrix.md`); deputy roles denied.
- Idempotency: `Idempotency-Key` REQUIRED, 16..128 chars, `^[A-Za-z0-9._-]+$` (Export/Import/Snapshot mirror).

---

## Request Body

```json
{
  "source": {
    "kind":       "archive",
    "archiveId":  "01J8Z9K5NP7Q8R9S0T1U2V3W4X",
    "snapshotId": null
  },
  "scope": {
    "schema":          true,
    "closedSets":      true,
    "features":        true,
    "licenses":        true,
    "rbac":            true,
    "domain":          ["all"],
    "secretsEnvelope": true,
    "files":           true
  },
  "mode":         "verifyAndApply",
  "conflict":     "abortOnAny",
  "resealPolicy": "activeEpoch"
}
```

| Field                | Type              | Required | Rules                                                                                                                                     |
|----------------------|-------------------|----------|-------------------------------------------------------------------------------------------------------------------------------------------|
| `source.kind`        | enum              | yes      | Closed set `{"archive","snapshot"}`.                                                                                                      |
| `source.archiveId`   | UUIDv7 or null    | cond.    | Required when `kind=archive`; archive MUST be in state `Verified` (from `12-endpoint-import.md`).                                          |
| `source.snapshotId`  | UUIDv7 or null    | cond.    | Required when `kind=snapshot`; snapshot MUST be in state `Sealed` (from `13-endpoint-snapshot.md`).                                        |
| `scope.*`            | (Export mirror)   | yes      | Scope MUST be a subset of the source's scope; superset fails 422 `Validation.Failed` `reason=scope_superset`.                              |
| `mode`               | enum              | yes      | Closed set `{"dryRun","verifyAndApply"}`; `dryRun` returns 200 preview with row counts, `verifyAndApply` returns 202 async.                 |
| `conflict`           | enum              | yes      | Closed set `{"abortOnAny","preserveLive","overwriteFromSource"}`; controls per-row conflict handling inside the apply job.                  |
| `resealPolicy`       | enum              | yes      | Closed set `{"activeEpoch"}`; only Active KEK is accepted (enforces `INV-BR-EK-5` and `INV-BR-FS-1`); reserved for future policies.         |

Scope-vs-source-scope check: reject with 422 `Validation.Failed` `reason=scope_superset` when any scope flag is true here but false in the source manifest. Snapshot source uses `snapshots.scope` column; archive source uses manifest `scope` block.

---

## Preflight Verification (Synchronous, No DB Writes)

Runs before any job is enqueued. Every failure aborts before touching live tables.

1. Casbin PEP check for `Backup.Restore`; deny -> 403 `Rbac.Denied`.
2. Idempotency lookup; body-match replay -> 200/202 with `X-Lara-Idempotency-Replay: hit`; body mismatch -> 409 `Idempotency.KeyReused`.
3. Source lookup: archive by `archiveId` (state MUST equal `Verified`), snapshot by `snapshotId` (state MUST equal `Sealed`); missing or wrong-state -> 409 `RestoreConflict` `reason=source_not_ready`.
4. Scope subset check; superset -> 422 `Validation.Failed` `reason=scope_superset`.
5. Epoch check: source `encryption.epoch` MUST resolve to a KEK in state `Active` or `Retired`; `Purged` -> 422 `BackupCorrupt` `reason=epoch_purged` (mirrors `INV-BR-FS-4`).
6. For snapshot sources, pointer pre-resolve: every SC-H pointer MUST resolve to a byte object with `pinCount >= 1`; miss -> 500 `BackupCorrupt` `reason=snapshot_pointer_dangling` (mirrors `INV-BR-EP-SN-6`).
7. For archive sources, tar seek probe on the first and last chunk (from `08-archive-format.md`); read failure -> 500 `BackupCorrupt` `reason=archive_chunk_unreadable`.
8. Concurrent-restore guard: only one `Backup.Restore` job MAY be in state `Queued` or `Applying` at a time (system-wide), enforced via a named advisory lock `restore.singleton`; contention -> 409 `RestoreConflict` `reason=restore_in_progress`.

Every preflight failure logs `lara.exception` with `ErrorId`, `RequestId`, `source.kind`, `source.*Id`, and the JSON pointer that failed; no source scope values are logged.

---

## Response: 200 (dryRun)

```json
{
  "Result": "Preview",
  "Data": {
    "SourceKind":   "archive",
    "SourceId":     "01J8Z9K5NP7Q8R9S0T1U2V3W4X",
    "Epoch":        7,
    "EpochState":   "Retired",
    "Plan": {
      "Schema":          { "MigrationsToApply": 12 },
      "ClosedSets":      { "Rows": 143 },
      "Features":        { "Rows": 88 },
      "Licenses":        { "Rows": 2140 },
      "Rbac":            { "Roles": 6, "Policies": 214 },
      "Domain":          { "Tables": 41, "Rows": 91224 },
      "SecretsEnvelope": { "Rows": 12, "ResealCount": 12 },
      "Files":           { "Pointers": 3182, "Bytes": 8241887233 }
    },
    "Conflicts":  { "Detected": 0, "Policy": "abortOnAny" }
  },
  "Attributes": {
    "RequestId":      "01J8Z9K5NP7Q8R9S0T1U2V3W4X",
    "IdempotencyKey": "restore-2026-07-20-01",
    "Capability":     "Backup.Restore"
  }
}
```

`dryRun` does NOT reserve the `restore.singleton` lock; multiple previews may run in parallel.

---

## Response: 202 (verifyAndApply)

```
HTTP/1.1 202 Accepted
Location: /api/admin/backup/jobs/01J8Z9K5NP7Q8R9S0T1U2V3W4Z
X-Request-Id: 01J8Z9K5NP7Q8R9S0T1U2V3W4X
```

```json
{
  "Result": "Accepted",
  "Data": {
    "JobId":     "01J8Z9K5NP7Q8R9S0T1U2V3W4Z",
    "SourceId":  "01J8Z9K5NP7Q8R9S0T1U2V3W4X",
    "State":     "Queued",
    "CreatedAt": "2026-07-20T15:12:03.771Z"
  },
  "Attributes": {
    "RequestId":      "01J8Z9K5NP7Q8R9S0T1U2V3W4X",
    "IdempotencyKey": "restore-2026-07-20-01",
    "Capability":     "Backup.Restore"
  }
}
```

Job kind is `backup.restore` for archive sources and `snapshot.restore` for snapshot sources (bound in `<spec-placeholder file="15-jobs-and-progress.md" />`).

---

## Apply Sequence (Inside Job, One Outer Tx)

Executed by the worker after the synchronous handler commits the job row. The whole sequence runs inside ONE database transaction; any failure rolls back every step atomically (enforces `INV-BR-A`).

1. Acquire `restore.singleton` advisory lock (timeout 60 s; timeout -> job fails with `RestoreConflict` `reason=restore_in_progress`).
2. Re-run preflight steps 3, 5, 6, 7 (state may have changed between enqueue and dequeue).
3. Apply `SC-A` schema migrations in manifest order; abort on any migration checksum mismatch.
4. Apply `SC-B..F` row sets in the order pinned by `05-scope-catalog.md`; `conflict` policy governs per-row behaviour.
5. Apply `SC-G` secrets envelope: unseal every row under the source epoch KEK (Active or Retired, read-only), re-seal under the current Active KEK, write into `secrets_envelope` table. This is the seven-step re-seal from `10-secrets-forward-secrecy.md` §5.
6. Apply `SC-H` files: for archive sources, extract tar chunks and write to the live storage backend; for snapshot sources, verify pointer resolution and increment `pinCount` on the live objects (no byte copy).
7. Emit audit rows `backup.restore_applied` with `actor`, `jobId`, `sourceKind`, `sourceId`, `epoch`, per-scope row counts, and `conflictCount`.
8. Commit outer tx. Transition source archive state to `Restored` (or snapshot state to `Restored`).

If any step fails, the tx rolls back, the job transitions to `Failed`, an audit row `backup.restore_failed` is emitted (outside the failed tx, in a fresh short tx), and the source's state does NOT change.

---

## Error Envelopes

| HTTP | Error code                | Trigger                                                                          |
|------|---------------------------|-----------------------------------------------------------------------------------|
| 400  | `Validation.Failed`       | Malformed body, unknown enum, `Idempotency-Key` regex fail.                       |
| 401  | `Auth.Unauthenticated`    | No Sanctum session.                                                               |
| 403  | `Rbac.Denied`             | Caller lacks `Backup.Restore` (deputies included).                                |
| 409  | `Idempotency.KeyReused`   | Same key, different body.                                                         |
| 409  | `RestoreConflict`         | `source_not_ready`, `restore_in_progress`.                                        |
| 422  | `Validation.Failed`       | `scope_superset`.                                                                 |
| 422  | `BackupCorrupt`           | `epoch_purged`, manifest schema drift, migration checksum mismatch.               |
| 429  | `RateLimit.Exceeded`      | Per-actor Restore budget from `27-performance-budget.md`.                         |
| 500  | `BackupCorrupt`           | `snapshot_pointer_dangling`, `archive_chunk_unreadable`, storage backend fault.   |

All failures log `lara.exception` with `ErrorId`, `RequestId`, source kind + id, JSON pointer or step name, and NO source data values.

---

## Idempotency Replay

Same rules as Export/Import/Snapshot: same key + identical body returns the original response with `X-Lara-Idempotency-Replay: hit`; different body under the same key fails 409 `Idempotency.KeyReused` `reason=body_mismatch`. For `dryRun` requests the replay returns the cached preview `Data.Plan` (frozen at first computation).

---

## Invariants (`INV-BR-EP-RE-1..7`)

Promoted into [`04-invariants.md`](./04-invariants.md) on the next edit of that file.

| ID                | Statement                                                                                                                                     |
|-------------------|-----------------------------------------------------------------------------------------------------------------------------------------------|
| `INV-BR-EP-RE-1`  | The apply sequence runs inside ONE database transaction; any step failure rolls back every step atomically.                                    |
| `INV-BR-EP-RE-2`  | Only one Restore job (archive or snapshot) MAY be in `Queued` or `Applying` state at a time, enforced by the `restore.singleton` advisory lock.|
| `INV-BR-EP-RE-3`  | Source archive MUST be in state `Verified`; source snapshot MUST be in state `Sealed`; any other state fails 409 `RestoreConflict`.            |
| `INV-BR-EP-RE-4`  | Request `scope` MUST be a subset of source scope; superset fails 422 `Validation.Failed` `reason=scope_superset` before any DB write.          |
| `INV-BR-EP-RE-5`  | Source epoch MUST resolve to `Active` or `Retired`; `Purged` fails 422 `BackupCorrupt` `reason=epoch_purged` (mirrors `INV-BR-FS-4`).           |
| `INV-BR-EP-RE-6`  | SC-G rows are unsealed under the source epoch KEK and re-sealed under the current Active KEK inside the outer tx (mirrors `INV-BR-EK-5`).      |
| `INV-BR-EP-RE-7`  | `dryRun` MUST NOT reserve the singleton lock, MUST NOT write to any table, and MUST return frozen preview data on idempotency replay.          |

---

## Cross-References

- Parent: [`11-endpoint-export.md`](./11-endpoint-export.md), [`12-endpoint-import.md`](./12-endpoint-import.md), [`13-endpoint-snapshot.md`](./13-endpoint-snapshot.md) (source producers); [`10-secrets-forward-secrecy.md`](./10-secrets-forward-secrecy.md) §5 (seven-step re-seal); [`09-encryption-and-keys.md`](./09-encryption-and-keys.md) (KEK/DEK unseal); [`05-scope-catalog.md`](./05-scope-catalog.md) (apply order); [`03-permission-matrix.md`](./03-permission-matrix.md) (`Backup.Restore`).
- Consumed by: `<spec-placeholder file="15-jobs-and-progress.md" />` (`backup.restore`, `snapshot.restore` job kinds), `<spec-placeholder file="16-idempotency-and-locks.md" />` (`restore.singleton` lock), `<spec-placeholder file="17-audit-and-observability.md" />` (`backup.restore_applied`, `backup.restore_failed`), `<spec-placeholder file="18-error-codes.md" />` (`source_not_ready`, `restore_in_progress`, `scope_superset`, `archive_chunk_unreadable`), `<spec-placeholder file="19-state-machines.md" />` (`Verified -> Applying -> Restored/Failed`, `Sealed -> Applying -> Restored/Failed`), `<spec-placeholder file="20-frontend-flows.md" />` (Restore wizard with dryRun preview), `<spec-placeholder file="21-cli-parity.md" />` (`lara:backup:restore`), `<spec-placeholder file="24-testing-matrix.md" />` (rollback + concurrent-restore fixtures), `<spec-placeholder file="26-operator-runbook.md" />` (disaster recovery runbook).
- Companion: [`04-invariants.md`](./04-invariants.md) next edit promotes `INV-BR-EP-RE-1..7`.
