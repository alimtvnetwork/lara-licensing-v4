# Endpoint: Snapshot

**Version:** 1.0.0
**Updated:** 2026-07-20
**AI Confidence:** Draft
**Ambiguity:** Low

---

## Keywords

`endpoint` · `snapshot` · `in-place` · `pointer` · `sc-h-reference` · `retention` · `pin-count` · `shard-lock` · `capability`

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

Pin the HTTP contract for the Snapshot entry point. A Snapshot is
an in-place archive: `SC-A..G` bodies are copied into the snapshot
row set (fast, small), while `SC-H` file bodies are referenced by
content-addressed pointer, never duplicated. This is the delta
already promised in
[`05-scope-catalog.md`](./05-scope-catalog.md) `SC-H` and the
Snapshot vs Export table. Without a defined pointer contract,
retention window, and per-shard mutex, a concurrent SC-H purge
would leave a snapshot with dangling pointers and violate
`INV-BR-A`.

---

## Route

```
POST /api/admin/snapshots
```

- Middleware order (from `02-casbin-integration.md`): `RequestId`, `Sanctum`, `CasbinPepMiddleware`, route.
- Capability required: `Snapshot.Create` (`03-permission-matrix.md`).
- Idempotency: `Idempotency-Key` REQUIRED, same rules as Export/Import (16..128 chars, `^[A-Za-z0-9._-]+$`).

---

## Request Body

```json
{
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
  "retention": {
    "policy":     "keepDays",
    "keepDays":   30,
    "keepCount":  null
  },
  "label": "pre-migration-2026-07-20",
  "note":  "Before schema v42 rollout"
}
```

| Field                | Type                | Required | Rules                                                                                                       |
|----------------------|---------------------|----------|-------------------------------------------------------------------------------------------------------------|
| `scope.*`            | (see Export)        | yes      | Same shape and FK-dependency rules as `11-endpoint-export.md`; `files=true` implies SC-H pointer creation.  |
| `retention.policy`   | enum                | yes      | Closed set `{"keepDays","keepCount","keepUntilExplicitDelete"}`.                                             |
| `retention.keepDays` | integer or null     | cond.    | Required when `policy=keepDays`; `1..3650`.                                                                 |
| `retention.keepCount`| integer or null     | cond.    | Required when `policy=keepCount`; `1..1000`; oldest snapshots pruned when count is exceeded.                |
| `label`              | string              | yes      | 1..80 chars, `^[A-Za-z0-9._-]+$`; unique per shard within active snapshots (409 `Validation.Failed` on collision). |
| `note`               | string              | no       | 1..280 chars.                                                                                               |

`retention.keepUntilExplicitDelete` disables auto-prune; snapshots persist until deleted via `DELETE /api/admin/snapshots/{id}` (out of scope for this file, defined in `<spec-placeholder file="19-state-machines.md" />`).

---

## Delta from Export

| Concern              | Export (`11-endpoint-export.md`)                | Snapshot (this file)                                                     |
|----------------------|-------------------------------------------------|--------------------------------------------------------------------------|
| `SC-A..G` bodies     | Copied into tar chunks under archive-DEK        | Copied into snapshot row set under Active KEK (no tar wrapper needed)    |
| `SC-H` file bodies   | Full bytes copied into `scope/files/*.bin.zst`  | Pointer only: `{ sha256, bucket, path, bytes }` rows, bodies untouched   |
| Manifest             | Serialised tar-first entry                      | Row in `snapshots` table with same field set                             |
| Transport            | Streaming tar+zstd archive at rest              | No archive file; live DB + pointer set                                   |
| Restore path         | Read tar, unseal, apply                         | Read snapshot rows + follow pointers to live SC-H storage                |
| Retention            | Client-controlled deletion                      | Server-enforced `policy` from this request                               |

Because SC-H bodies are shared with live data, deleting a live SC-H row while a snapshot still points at it is forbidden: SC-H storage backends enforce a `pinCount` invariant, decremented only when every referencing snapshot is deleted.

---

## Pointer Contract (`SC-H` Pin Semantics)

For every SC-H row a snapshot references:

1. Snapshot creation increments `pinCount` on the pointed-to SC-H storage object.
2. Live-data DELETE against a pinned SC-H row is a soft-delete only: the row's `deletedAt` is set, but the underlying bytes stay in the bucket until `pinCount == 0`.
3. Snapshot deletion decrements `pinCount`. When `pinCount == 0` AND `deletedAt IS NOT NULL`, a background sweeper reclaims bytes.
4. A Restore reading a snapshot MUST resolve every pointer under the current backend; a resolve-miss (bytes gone) is `BackupCorrupt` with `reason=snapshot_pointer_dangling` and rolls back the outer tx.

The pin-count column lives on the SC-H storage index table (already in scope per `INV-BR-SC-1`); enforcement is a DB-level `CHECK` + application-level guard, both required so a bug in either layer does not lose data silently.

---

## Per-Shard Mutex

Snapshot creation acquires a shard-scoped advisory lock (`pg_advisory_xact_lock(hashtext('snapshot.create:' || shard_id))`) inside the outer tx. Concurrent snapshot POSTs on the same shard serialise on this lock. Rationale: two snapshots racing over the same SC-H row would both increment `pinCount` atomically at the row level, but a concurrent live DELETE running between the read-set collection and the pin write is the actual race, and the advisory lock closes it.

Lock timeout: 30 s. On timeout, 409 `RestoreConflict` (closed-set code reused for Snapshot-vs-Snapshot) with `reason=shard_lock_timeout`, `Attributes.Error.Details.shardId`.

---

## Response: 202 Accepted

```
HTTP/1.1 202 Accepted
Location: /api/admin/backup/jobs/01J8Z9K5NP7Q8R9S0T1U2V3W4X
Content-Type: application/json; charset=utf-8
X-Request-Id: 01J8Z9K5NP7Q8R9S0T1U2V3W4X
```

```json
{
  "Result": "Accepted",
  "Data": {
    "JobId":       "01J8Z9K5NP7Q8R9S0T1U2V3W4X",
    "SnapshotId":  "01J8Z9K5NP7Q8R9S0T1U2V3W4Y",
    "State":       "Queued",
    "Label":       "pre-migration-2026-07-20",
    "Retention":   { "policy": "keepDays", "keepDays": 30 },
    "CreatedAt":   "2026-07-20T15:02:44.301Z"
  },
  "Attributes": {
    "RequestId":       "01J8Z9K5NP7Q8R9S0T1U2V3W4X",
    "IdempotencyKey":  "pre-migration-2026-07-20",
    "Capability":      "Snapshot.Create"
  }
}
```

`SnapshotId` is UUIDv7. The snapshot row starts in state `Draft` and transitions to `Sealed` when SC-A..G copy and SC-H pinning both complete; state machine pinned in `<spec-placeholder file="19-state-machines.md" />`.

---

## Idempotency Replay

Same rules as Export/Import: same key + same body returns 202 with `X-Lara-Idempotency-Replay: hit`; different body under the same key fails 409 `Idempotency.KeyReused` with `Attributes.Error.Details.reason = "body_mismatch"`.

Label uniqueness collision is orthogonal to idempotency: a re-POST with the same idempotency key and identical body returns the original snapshot, but a distinct idempotency key targeting the same label fails 409 `Validation.Failed` with `reason=label_taken`.

---

## Error Envelopes

| HTTP | Error code                | Trigger                                                                          |
|------|---------------------------|-----------------------------------------------------------------------------------|
| 400  | `Validation.Failed`       | Missing field, invalid retention policy, `Idempotency-Key` malformed.             |
| 401  | `Auth.Unauthenticated`    | No Sanctum session.                                                               |
| 403  | `Rbac.Denied`             | Caller lacks `Snapshot.Create`.                                                   |
| 409  | `Idempotency.KeyReused`   | Same key, different body.                                                         |
| 409  | `Validation.Failed`       | Label taken by another active snapshot on the same shard.                         |
| 409  | `RestoreConflict`         | Shard advisory lock timeout (`reason=shard_lock_timeout`).                        |
| 422  | `Validation.Failed`       | FK-dependency violation across scope flags (mirror of Export).                    |
| 422  | `BackupCorrupt`           | `encryption.epoch` explicit non-null non-Active value (mirror of `INV-BR-FS-1`).  |
| 429  | `RateLimit.Exceeded`      | Per-actor Snapshot budget from `27-performance-budget.md`.                        |
| 500  | `BackupCorrupt`           | SC-H storage backend unreachable during pointer pin write.                        |

Every failure logs `lara.exception` with `ErrorId`, `RequestId`, JSON pointer, and NO scope or label values that could carry PII beyond what audit rows already contain.

---

## Async Job Handoff

Synchronous handler, inside one DB tx protected by the shard advisory lock:

1. Validate scope + FK-dependencies.
2. Casbin PEP check (`Snapshot.Create`).
3. Idempotency lookup (200 replay or 409 body mismatch).
4. Label uniqueness check.
5. Acquire shard advisory lock (30 s timeout).
6. Resolve Active epoch KEK.
7. Insert `snapshot` row in state `Draft` with `retention` block.
8. Insert `job` row of kind `snapshot.create` referencing `SnapshotId`.
9. Emit audit row `snapshot.enqueued` with `actor`, `snapshotId`, `jobId`, `label`, `scope`, `retention`.
10. Commit tx, dispatch job, return 202.

The actual SC-A..G copy and SC-H pin writes happen inside the job (per-chunk progress via `<spec-placeholder file="15-jobs-and-progress.md" />`); the synchronous handler only reserves the label and locks the shard so a concurrent SC-H delete cannot race the pin phase.

---

## Retention Enforcement

Retention is checked by a background job (`snapshot.retention_sweep`, defined in `15-jobs-and-progress.md`) that runs hourly:

- `keepDays`: snapshots older than `keepDays` from `createdAt` are transitioned to `Retiring`, pin counts decremented, then transitioned to `Purged`.
- `keepCount`: on each new snapshot creation, if active-snapshot count for the shard exceeds `keepCount`, the oldest is retired.
- `keepUntilExplicitDelete`: sweeper skips.

Retention transitions emit audit rows `snapshot.retired` and `snapshot.purged`.

---

## Invariants (`INV-BR-EP-SN-1..7`)

Promoted into [`04-invariants.md`](./04-invariants.md) on the next edit of that file.

| ID                | Statement                                                                                                                                    |
|-------------------|----------------------------------------------------------------------------------------------------------------------------------------------|
| `INV-BR-EP-SN-1`  | Snapshots reference `SC-H` bodies by content-addressed pointer; no SC-H bytes are copied at snapshot creation.                               |
| `INV-BR-EP-SN-2`  | Every SC-H object referenced by an active snapshot carries `pinCount >= 1`; live DELETE against a pinned row is a soft-delete only.          |
| `INV-BR-EP-SN-3`  | Snapshot creation acquires a per-shard advisory lock inside the outer DB tx; timeout is 30 s and fails 409 `RestoreConflict`.                |
| `INV-BR-EP-SN-4`  | Label is unique across active snapshots on one shard; collision fails 409 `Validation.Failed` with `reason=label_taken`.                     |
| `INV-BR-EP-SN-5`  | Retention policy is enforced by the `snapshot.retention_sweep` job; expired snapshots transition `Sealed -> Retiring -> Purged` atomically.  |
| `INV-BR-EP-SN-6`  | A Restore from a snapshot with any dangling SC-H pointer fails `BackupCorrupt` with `reason=snapshot_pointer_dangling` and rolls back.       |
| `INV-BR-EP-SN-7`  | Every response body conforms to the canonical envelope from `spec/03-error-manage/`; `Attributes.Capability` is always `Snapshot.Create`.    |

---

## Cross-References

- Parent: [`05-scope-catalog.md`](./05-scope-catalog.md) (`SC-H` pointer delta), [`03-permission-matrix.md`](./03-permission-matrix.md) (`Snapshot.Create`), [`10-secrets-forward-secrecy.md`](./10-secrets-forward-secrecy.md) (`INV-BR-FS-1` Active-only seal), [`11-endpoint-export.md`](./11-endpoint-export.md) (scope shape mirror), [`12-endpoint-import.md`](./12-endpoint-import.md) (idempotency convention).
- Consumed by: `<spec-placeholder file="14-endpoint-restore.md" />` (accepts `snapshotId` alongside `archiveId`), `<spec-placeholder file="15-jobs-and-progress.md" />` (`snapshot.create`, `snapshot.retention_sweep` job kinds), `<spec-placeholder file="16-idempotency-and-locks.md" />` (shard advisory lock semantics), `<spec-placeholder file="17-audit-and-observability.md" />` (`snapshot.enqueued`, `snapshot.retired`, `snapshot.purged`), `<spec-placeholder file="18-error-codes.md" />` (`shard_lock_timeout`, `label_taken`, `snapshot_pointer_dangling`), `<spec-placeholder file="19-state-machines.md" />` (`Draft -> Sealed -> Retiring -> Purged` transitions), `<spec-placeholder file="20-frontend-flows.md" />` (label + retention picker UX), `<spec-placeholder file="21-cli-parity.md" />` (`lara:snapshot:create`), `<spec-placeholder file="22-storage-backends.md" />` (pin-count column contract), `<spec-placeholder file="24-testing-matrix.md" />` (concurrent-DELETE race fixtures).
- Companion: [`04-invariants.md`](./04-invariants.md) next edit promotes `INV-BR-EP-SN-1..7`.
