# Jobs and Progress

**Version:** 1.0.0
**Updated:** 2026-07-20
**AI Confidence:** Draft
**Ambiguity:** Low

---

## Keywords

`jobs` · `progress` · `sse` · `state-machine` · `retry` · `backoff` · `cancel` · `sequence-monotonic` · `heartbeat` · `worker-lease`

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

Endpoints 11..14 enqueue jobs but nothing pinned the job row
schema, state machine, per-kind payload/result shape, progress
event contract, retry policy, cancel semantics, or the FE-facing
GET/SSE surface. Without this file, workers cannot enforce
`INV-BR-A` (atomicity) transaction boundaries consistently across
kinds, `dryRun` vs async responses cannot report progress in a
shared format, and `20-frontend-flows.md` has no event shape to
consume.

---

## Job Kinds (Closed Set)

| Kind                        | Producer                                            | Payload keys                                  | Result keys                                    | Outer tx? |
|-----------------------------|-----------------------------------------------------|-----------------------------------------------|------------------------------------------------|-----------|
| `backup.export`             | `11-endpoint-export.md`                             | `archiveId`, `scope`                          | `archiveId`, `bytes`, `merkleRoot`             | yes       |
| `backup.import`             | `12-endpoint-import.md` verifyAndApply              | `archiveId`, `mode`                           | `archiveId`, `state=Verified`                  | yes       |
| `snapshot.create`           | `13-endpoint-snapshot.md`                           | `snapshotId`, `scope`, `retention`            | `snapshotId`, `pinnedFiles`, `bytesReferenced` | yes       |
| `snapshot.retention_sweep`  | Hourly cron (`13-endpoint-snapshot.md` §Retention)  | `shardId`                                     | `retired`, `purged`                            | yes       |
| `backup.restore`            | `14-endpoint-restore.md` (archive source)           | `sourceId`, `scope`, `conflict`, `mode`       | `sourceId`, `state=Restored`, `conflictCount`  | yes       |
| `snapshot.restore`          | `14-endpoint-restore.md` (snapshot source)          | `sourceId`, `scope`, `conflict`, `mode`       | `sourceId`, `state=Restored`, `conflictCount`  | yes       |

The kind enum is closed; adding a kind requires a spec edit and version bump.

---

## Job Row Schema

Table `backup_jobs` on the primary shard (jobs are global, not shard-partitioned, so `restore.singleton` from `14-endpoint-restore.md` can hold system-wide).

| Column             | Type                        | Rules                                                                                   |
|--------------------|-----------------------------|-----------------------------------------------------------------------------------------|
| `id`               | UUIDv7 PK                   | Same value returned as `JobId` in every producer response.                              |
| `kind`             | text                        | One of the closed set above.                                                            |
| `state`            | text                        | One of `{Queued, Running, Succeeded, Failed, Cancelled}` (state machine below).         |
| `payload`          | jsonb                       | Per-kind payload; NEVER contains scope values that carry PII (only ids + flags).        |
| `result`           | jsonb NULL                  | Populated on `Succeeded`; NULL otherwise.                                               |
| `errorId`          | text NULL                   | ULID linking to `lara.exception` log row on `Failed`.                                   |
| `errorCode`        | text NULL                   | Closed-set code from `18-error-codes.md`.                                               |
| `errorReason`      | text NULL                   | Closed-set reason string.                                                               |
| `actorId`          | UUID                        | Sanctum user id from producer request.                                                  |
| `capability`       | text                        | Capability used by producer (`Backup.Export` etc.).                                     |
| `idempotencyKey`   | text                        | Copied from producer; part of the `(actorId, idempotencyKey)` unique index.             |
| `requestId`        | text                        | `X-Request-Id` from producer; propagated into every progress event and audit row.       |
| `workerLeaseUntil` | timestamptz NULL            | Lease held by the current worker; NULL when Queued or terminal.                         |
| `attemptCount`     | int                         | Starts at 0; incremented on each dequeue.                                               |
| `maxAttempts`      | int                         | Per-kind, from the Retry table below.                                                   |
| `progressSequence` | bigint                      | Monotonic per-job; last emitted event's sequence.                                       |
| `createdAt`        | timestamptz                 | Set on insert.                                                                          |
| `startedAt`        | timestamptz NULL            | Set on first `Queued -> Running` transition.                                            |
| `finishedAt`       | timestamptz NULL            | Set on any terminal transition.                                                         |
| `cancelledAt`      | timestamptz NULL            | Set on `Cancelled` transition; distinct from `finishedAt`.                              |

Unique indexes: `(actorId, idempotencyKey)`, `(id)`. Non-unique: `(state, kind, createdAt)` for the dequeue query.

---

## State Machine

```
                cancel*
Queued ─────────────────► Cancelled
   │                          ▲
   │ dequeue                  │ cancel*
   ▼                          │
Running ──────────────────────┘
   │        succeed
   ├─────────────────► Succeeded
   │
   │        fail (terminal)
   └─────────────────► Failed

Running -> Queued on lease expiry when attemptCount < maxAttempts.
```

Legal transitions (all others reject with 409 `Job.InvalidTransition`):

| From      | To          | Trigger                                                |
|-----------|-------------|--------------------------------------------------------|
| Queued    | Running     | Worker dequeue + lease acquire.                        |
| Queued    | Cancelled   | Cancel request.                                        |
| Running   | Queued      | Lease expiry (`workerLeaseUntil < now()`), retryable.  |
| Running   | Succeeded   | Job completes and outer tx commits.                    |
| Running   | Failed      | Non-retryable error OR `attemptCount >= maxAttempts`.  |
| Running   | Cancelled   | Cooperative cancel checkpoint hit.                     |

`Succeeded`, `Failed`, `Cancelled` are terminal; no transitions out.

---

## Worker Lease and Dequeue

- Lease duration: 60 s (all kinds); worker heartbeats every 15 s by extending `workerLeaseUntil` under a row-level `FOR UPDATE SKIP LOCKED` query.
- Dequeue query filters `state = 'Queued' AND (workerLeaseUntil IS NULL OR workerLeaseUntil < now())` ordered by `createdAt ASC`, `FOR UPDATE SKIP LOCKED`, LIMIT 1.
- On lease acquire, `attemptCount += 1`, `state = 'Running'`, `startedAt = coalesce(startedAt, now())`.
- On heartbeat miss (worker crashed), a reaper (runs every 30 s) flips `Running -> Queued` when `workerLeaseUntil < now() - interval '15 s'` and `attemptCount < maxAttempts`, else `-> Failed` with `errorReason=lease_expired_exhausted`.

The reaper transition emits a `job.retry_scheduled` or `job.failed_exhausted` audit row (bound in `17-audit-and-observability.md`).

---

## Retry Policy

| Kind                        | maxAttempts | Backoff (seconds)                          | Retryable errors                                       |
|-----------------------------|-------------|--------------------------------------------|--------------------------------------------------------|
| `backup.export`             | 3           | 5, 30, 120                                 | Transient storage IO, transient DB serialization.      |
| `backup.import`             | 2           | 10, 60                                     | Transient storage IO only; verify failures NON-retry.  |
| `snapshot.create`           | 3           | 5, 30, 120                                 | Advisory lock timeout, transient DB serialization.     |
| `snapshot.retention_sweep`  | 1           | (none)                                     | (none; next hourly tick is the retry)                  |
| `backup.restore`            | 1           | (none)                                     | (none; Restore is atomic single-shot per `INV-BR-A`)   |
| `snapshot.restore`          | 1           | (none)                                     | (none; same rationale)                                 |

Backoff uses `startedAt + backoff[attemptCount-1]` as the next visible-at time; `Running -> Queued` sets `workerLeaseUntil = startedAt + backoff[attemptCount-1]`.

Restore kinds have `maxAttempts=1` because a rolled-back apply tx leaves live state unchanged; a client retry with a fresh `Idempotency-Key` is the correct path, so silent worker-side retry would violate operator intent.

---

## Progress Events

Emitted from inside the worker via `insert into backup_job_events(...)` inside SHORT transactions separate from the outer apply tx, so events land even when the outer tx aborts.

| Field         | Type              | Rules                                                                                     |
|---------------|-------------------|-------------------------------------------------------------------------------------------|
| `jobId`       | UUIDv7            | FK to `backup_jobs.id`.                                                                   |
| `sequence`    | bigint            | Monotonic per job; MUST equal previous `progressSequence + 1`; gap or duplicate rejected. |
| `at`          | timestamptz       | Server clock; monotonic within a job (enforced by check constraint).                      |
| `phase`       | text              | Closed set: `{prepare, scope_a, scope_b, scope_c, scope_d, scope_e, scope_f, scope_g, scope_h, finalize, heartbeat}`. |
| `percent`     | int               | 0..100, non-decreasing within a job.                                                      |
| `message`     | text NULL         | 1..280 chars; NEVER contains scope values.                                                |
| `counters`    | jsonb NULL        | Per-phase counters (e.g. `{"rowsApplied": 4200}`).                                        |

Heartbeat events (`phase=heartbeat`) fire every 15 s while `Running` so FE can distinguish stall from silence.

---

## GET `/api/admin/backup/jobs/{id}`

- Middleware order: `RequestId`, `Sanctum`, `CasbinPepMiddleware`, route.
- Capability: `Backup.Read` (read-only projection of a job the caller enqueued OR any job when caller has `Backup.Audit`).
- 200 body: canonical envelope with `Data = { JobId, Kind, State, Payload, Result, ErrorId, ErrorCode, ErrorReason, AttemptCount, MaxAttempts, Progress: { Sequence, Phase, Percent, Message, Counters, At }, CreatedAt, StartedAt, FinishedAt, CancelledAt }`.
- 404 `Job.NotFound` when id does not exist or caller lacks visibility.
- 403 `Rbac.Denied` when caller has neither ownership nor `Backup.Audit`.

---

## GET `/api/admin/backup/jobs/{id}/events` (SSE)

- Same middleware and capability rules as the singleton GET.
- Response: `Content-Type: text/event-stream; charset=utf-8`, `Cache-Control: no-cache, no-transform`, `X-Accel-Buffering: no`.
- Query param `?sinceSequence=N` starts from event `N+1`; default is the full stream from sequence 1.
- Each event: `id: <sequence>\nevent: progress\ndata: <event json>\n\n`; terminal state closes the stream with a final `event: state\ndata: {"state":"Succeeded|Failed|Cancelled", ...}` frame.
- Server sends `:heartbeat` comment lines every 15 s to keep intermediaries from closing idle connections.
- Reconnect: FE reads `Last-Event-ID` header from the previous connection and sends it as `sinceSequence` on reconnect; server MUST return every event with `sequence > last-event-id` (no loss).

Backpressure: server closes with `event: error\ndata: {"code":"RateLimit.Exceeded","reason":"sse_slow_consumer"}` when the FE consumer falls more than 200 events behind.

---

## POST `/api/admin/backup/jobs/{id}/cancel`

- Capability: `Backup.Cancel` (kept separate from `Backup.Read` because Cancel is destructive).
- Idempotency-Key REQUIRED (same regex as other endpoints).
- Cooperative: sets `cancelRequestedAt` on the row; the worker checks this flag at every phase boundary and transitions `Running -> Cancelled` at the next checkpoint. Between checkpoints, cancel is queued, not immediate.
- Cannot cancel terminal jobs: 409 `Job.InvalidTransition` with `Attributes.Error.Details.currentState`.
- `backup.restore` and `snapshot.restore` special case: cancel is honored ONLY during preflight steps 1..2; once step 3 begins (schema apply), cancel is REJECTED with 409 `Job.InvalidTransition` `reason=restore_past_no_return_point` because a mid-apply rollback happens automatically via the outer tx, not via cancel semantics.

---

## Error Envelopes

| HTTP | Error code                | Trigger                                                                          |
|------|---------------------------|-----------------------------------------------------------------------------------|
| 401  | `Auth.Unauthenticated`    | No Sanctum session on any endpoint.                                               |
| 403  | `Rbac.Denied`             | Caller lacks required capability.                                                 |
| 404  | `Job.NotFound`            | Unknown id OR caller lacks visibility.                                            |
| 409  | `Job.InvalidTransition`   | Cancel on terminal state OR cancel past restore no-return point.                  |
| 409  | `Idempotency.KeyReused`   | Cancel with reused key + different body.                                          |
| 429  | `RateLimit.Exceeded`      | SSE slow consumer OR per-actor GET budget.                                        |

Every failure logs `lara.exception` with `ErrorId`, `RequestId`, `jobId`, and the attempted transition.

---

## Invariants (`INV-BR-JP-1..8`)

Promoted into [`04-invariants.md`](./04-invariants.md) on the next edit.

| ID              | Statement                                                                                                                                     |
|-----------------|-----------------------------------------------------------------------------------------------------------------------------------------------|
| `INV-BR-JP-1`   | Job `kind` is a closed set; adding a kind requires a spec edit and version bump.                                                              |
| `INV-BR-JP-2`   | Progress event `sequence` is strictly monotonic per job; gaps and duplicates are rejected by a check + unique constraint.                     |
| `INV-BR-JP-3`   | Progress events are written in short transactions separate from the outer apply tx, so events survive an apply-tx rollback.                    |
| `INV-BR-JP-4`   | Worker leases (60 s) are protected by row-level `FOR UPDATE SKIP LOCKED`; a crashed worker's job returns to `Queued` via the reaper.          |
| `INV-BR-JP-5`   | `backup.restore` and `snapshot.restore` have `maxAttempts=1`; silent worker retry of a Restore is forbidden.                                  |
| `INV-BR-JP-6`   | Cancel is cooperative; Restore cancel past preflight step 3 is rejected with 409 `Job.InvalidTransition` `reason=restore_past_no_return_point`.|
| `INV-BR-JP-7`   | SSE reconnect with `Last-Event-ID` MUST replay every event with `sequence > last-event-id`; no event loss.                                    |
| `INV-BR-JP-8`   | `payload`, `result`, and event `message`/`counters` NEVER carry scope values that could contain PII; only ids, flags, and integer counters.   |

---

## Cross-References

- Parent: [`11-endpoint-export.md`](./11-endpoint-export.md), [`12-endpoint-import.md`](./12-endpoint-import.md), [`13-endpoint-snapshot.md`](./13-endpoint-snapshot.md), [`14-endpoint-restore.md`](./14-endpoint-restore.md) (job producers); [`03-permission-matrix.md`](./03-permission-matrix.md) (`Backup.Read`, `Backup.Audit`, `Backup.Cancel`); [`04-invariants.md`](./04-invariants.md) (`INV-BR-A`).
- Consumed by: `<spec-placeholder file="16-idempotency-and-locks.md" />` (`(actorId, idempotencyKey)` uniqueness, worker-lease lock discipline), `<spec-placeholder file="17-audit-and-observability.md" />` (`job.retry_scheduled`, `job.failed_exhausted`, `job.cancelled` audit rows), `<spec-placeholder file="18-error-codes.md" />` (`Job.NotFound`, `Job.InvalidTransition`, `Backup.Cancel` reasons), `<spec-placeholder file="19-state-machines.md" />` (canonical job state diagram), `<spec-placeholder file="20-frontend-flows.md" />` (SSE consumer with `Last-Event-ID` reconnect), `<spec-placeholder file="21-cli-parity.md" />` (`lara:backup:jobs:tail`, `lara:backup:jobs:cancel`), `<spec-placeholder file="24-testing-matrix.md" />` (retry/backoff, lease-expiry, SSE-replay fixtures), `<spec-placeholder file="26-operator-runbook.md" />` (stuck-job triage), `<spec-placeholder file="27-performance-budget.md" />` (SSE slow-consumer threshold).
- Companion: [`04-invariants.md`](./04-invariants.md) next edit promotes `INV-BR-JP-1..8`.
