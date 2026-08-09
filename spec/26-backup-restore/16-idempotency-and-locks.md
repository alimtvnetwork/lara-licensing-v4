# Idempotency and Locks

**Version:** 1.0.0
**Updated:** 2026-07-20
**AI Confidence:** Draft
**Ambiguity:** Low

---

## Keywords

`idempotency-key` · `body-hash` · `replay-hit` · `body-mismatch` · `advisory-lock` · `lock-registry` · `deadlock-avoidance` · `acquisition-order` · `retention`

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

Endpoints 11..15 each cite an `Idempotency-Key` rule and one or
more advisory locks (`restore.singleton`, `snapshot.create:<shardId>`,
row-level `FOR UPDATE SKIP LOCKED` on dequeue), but the key
format, hashing algorithm, replay-hit vs body-mismatch matrix,
lock-name registry, acquisition-order rule, and retention window
were duplicated inconsistently. This file consolidates them so
`INV-BR-A` (atomicity) and `INV-BR-JP-4` (worker lease) have one
lock discipline, and step 25's testing matrix has a single
target to conform to.

---

## Idempotency-Key Format

- Header: `Idempotency-Key` (case-insensitive, exact wire form `Idempotency-Key`).
- Charset regex: `^[A-Za-z0-9._-]+$`.
- Length: 16..128 bytes inclusive.
- Client SHOULD use a UUIDv7, ULID, or a hash of business intent; server does not require any specific structure beyond the regex.
- Server MUST treat the raw key bytes as opaque; no normalisation, no case folding beyond the header name itself.
- Missing or malformed on any producer endpoint (11..14) or on Cancel (15): 400 `Validation.Failed` `reason=idempotency_key_invalid`.

---

## Body Hash

The server derives `bodyHash = SHA-256(canonicalJson(requestBody))` where `canonicalJson` is RFC 8785 JCS (JSON Canonicalization Scheme): sorted object keys, no insignificant whitespace, integers/strings normalised. The 32-byte digest is stored hex-encoded in the idempotency record.

Rationale for JCS over raw byte hashing: two clients sending the same intent with different key order or whitespace must be treated as identical for idempotency purposes; a raw byte hash would fail that requirement.

---

## Idempotency Record

Table `idempotency_records` on the primary shard.

| Column           | Type                | Rules                                                                       |
|------------------|---------------------|-----------------------------------------------------------------------------|
| `id`             | UUIDv7 PK           |                                                                             |
| `actorId`        | UUID                | Sanctum user id.                                                            |
| `capability`     | text                | Capability the request required.                                            |
| `endpoint`       | text                | Closed set: `exports/imports/snapshots/restores/jobsCancel`.                 |
| `key`            | text                | Raw `Idempotency-Key` value.                                                |
| `bodyHash`       | text                | Hex SHA-256 of canonical JSON body.                                         |
| `responseStatus` | int                 | Cached HTTP status (200, 202).                                              |
| `responseBody`   | jsonb               | Cached canonical envelope returned on first successful call.                |
| `responseHeaders`| jsonb               | Cached `Location`, `X-Request-Id`, `Retry-After` if present.                |
| `createdAt`      | timestamptz         |                                                                             |
| `expiresAt`      | timestamptz         | `createdAt + 24h` by default (see Retention below).                         |

Unique index: `(actorId, endpoint, key)`. A key MAY be reused across distinct endpoints by the same actor because the `endpoint` column is part of the unique tuple.

---

## Replay Matrix

For every producer request:

| Existing record | Same `bodyHash`? | Result                                                                          |
|-----------------|------------------|---------------------------------------------------------------------------------|
| None            | n/a              | Proceed; write the record on success (inside the endpoint's outer tx).          |
| Match           | yes              | Return cached `responseStatus` + `responseBody` + `responseHeaders`, plus header `X-Lara-Idempotency-Replay: hit`. Do NOT re-execute. |
| Match           | no               | Reject 409 `Idempotency.KeyReused` `reason=body_mismatch`; include `Attributes.Error.Details.originalRequestId` from cached headers. |
| Expired         | n/a              | Treat as `None`; the expired row is deleted lazily by the retention sweeper.    |

Cache write timing: the record row is inserted inside the SAME transaction that enqueues the job, so a rolled-back producer tx leaves NO idempotency record. This closes the "client retries after a 500, we return a stale success" hole.

For 202 async responses, the cached body reflects the state at enqueue time (`State: "Queued"`); replay does NOT re-read job state. Clients must GET `/api/admin/backup/jobs/{id}` for current state.

---

## Lock Registry

Closed set of named locks used across backup/restore/snapshot code paths.

| Lock name                             | Kind                            | Scope         | Timeout | Acquired by                                             | Held during                                        |
|---------------------------------------|---------------------------------|---------------|---------|---------------------------------------------------------|----------------------------------------------------|
| `restore.singleton`                   | `pg_advisory_xact_lock`         | Global        | 60 s    | `14-endpoint-restore.md` apply job (steps 1..8)         | Entire Restore apply tx.                           |
| `snapshot.create:<shardId>`           | `pg_advisory_xact_lock`         | Per-shard     | 30 s    | `13-endpoint-snapshot.md` synchronous handler + job     | Snapshot enqueue and apply tx.                     |
| `backup_jobs.row FOR UPDATE SKIP LOCKED` | Row-level                    | Row           | (none)  | Worker dequeue query in `15-jobs-and-progress.md`       | Duration of the dequeue statement only.            |
| `snapshots.label:<shardId>:<label>`   | `pg_advisory_xact_lock`         | Per-label     | 5 s     | Snapshot label uniqueness check                         | Label reservation inside enqueue tx.               |
| `kek.rotate`                          | `pg_advisory_xact_lock`         | Global        | 300 s   | `10-secrets-forward-secrecy.md` rotation trigger         | Full KEK mint + activate + smoke round-trip.       |
| `storage.pin:<sha256>`                | `pg_advisory_xact_lock`         | Per-SC-H-obj  | 10 s    | Snapshot pin/unpin, live SC-H delete                    | `pinCount` read-modify-write.                      |

All advisory locks are transaction-scoped (`pg_advisory_xact_lock`, NOT `pg_advisory_lock`) so a crashed worker never leaks a held lock; the tx aborts and the lock is released.

Timeout enforcement: each acquisition site sets `SET LOCAL lock_timeout = '<value>'` before the lock statement; on timeout, Postgres raises `lock_not_available` (SQLSTATE 55P03) which maps to closed-set error codes per the registry consumers.

---

## Acquisition Order (Deadlock Avoidance)

When a code path acquires more than one lock, it MUST acquire them in this global order (smallest scope last, so shorter holds nest inside longer holds):

1. `kek.rotate` (global, longest)
2. `restore.singleton` (global)
3. `snapshot.create:<shardId>` (per-shard)
4. `snapshots.label:<shardId>:<label>` (per-label)
5. `storage.pin:<sha256>` (per-object)
6. `backup_jobs.row FOR UPDATE SKIP LOCKED` (per-row, always innermost)

Rule: NEVER acquire a lock from a lower rank while holding a lock from a higher rank. Static analysis check (spec-only for now): each apply-tx code path lists the locks it acquires, in order; reviewers reject changes that reorder them.

Known safe combinations (documented so reviewers can verify):

- Restore apply: `restore.singleton` -> `storage.pin:<sha256>` (per SC-H row).
- Snapshot create: `snapshot.create:<shardId>` -> `snapshots.label:<shardId>:<label>` -> `storage.pin:<sha256>` (per SC-H row) -> `backup_jobs.row` (dequeue by worker later).
- KEK rotation: `kek.rotate` alone; a rotation running while a Restore holds `restore.singleton` is impossible because rotation MUST be waited out by Restore preflight (`14-endpoint-restore.md` preflight step 8's singleton acquisition serialises after rotation).

Deadlock detection: Postgres will detect a cycle and abort one transaction. That case is a spec violation, not a valid runtime state; the aborted job MUST log `lara.exception` with `errorReason=deadlock_detected` and the acquisition order that was violated, and MUST be treated as a code bug (fail non-retryable).

---

## Retention

Idempotency records are retained for 24 h by default (`config('backup.idempotency.retentionHours') = 24`). A sweeper runs every 15 minutes and deletes rows where `expiresAt < now()`. Retention window rationale:

- Long enough that a client retry after a 30 s network timeout, or a queue backlog of a few minutes, still hits the cache.
- Short enough that key collisions across unrelated business intents (different requests happen to reuse a short key) do not accumulate.

For Restore records specifically, retention is 7 days (`retentionHoursRestore = 168`) because Restore is rare and operators frequently retry after triage; the 24 h default would silently allow a re-execution 25 h after failure with the same key and a modified body, which is exactly the hole idempotency exists to close.

Sweeper failures MUST NOT block requests; the sweeper emits `backup.idempotency_sweep_failed` audit rows and continues on the next tick.

---

## Error Envelopes

| HTTP | Error code                | Trigger                                                                          |
|------|---------------------------|-----------------------------------------------------------------------------------|
| 400  | `Validation.Failed`       | Missing or malformed `Idempotency-Key` (`reason=idempotency_key_invalid`).        |
| 409  | `Idempotency.KeyReused`   | Same key, different body (`reason=body_mismatch`).                                |
| 409  | `RestoreConflict`         | Lock timeout on `restore.singleton` or `snapshot.create:<shardId>`.               |
| 500  | `Internal.Unexpected`     | Deadlock detected (`errorReason=deadlock_detected`); non-retryable, code bug.     |

Every failure logs `lara.exception` with `ErrorId`, `RequestId`, `endpoint`, `actorId`, and the lock name involved when applicable. Neither the raw `Idempotency-Key` nor the body hash is logged at INFO level; both appear only at DEBUG in non-production.

---

## Invariants (`INV-BR-IL-1..7`)

Promoted into [`04-invariants.md`](./04-invariants.md) on the next edit.

| ID              | Statement                                                                                                                                       |
|-----------------|-------------------------------------------------------------------------------------------------------------------------------------------------|
| `INV-BR-IL-1`   | `bodyHash = SHA-256(RFC 8785 canonical JSON)`; key-order and whitespace differences MUST NOT produce a body mismatch.                            |
| `INV-BR-IL-2`   | The idempotency record is inserted inside the SAME transaction as the endpoint's outer effect; a rolled-back tx leaves NO cached record.        |
| `INV-BR-IL-3`   | The lock registry is closed; adding a lock requires a spec edit, a version bump, and a new rank in the acquisition-order list.                   |
| `INV-BR-IL-4`   | All advisory locks are transaction-scoped (`pg_advisory_xact_lock`); NEVER `pg_advisory_lock`.                                                   |
| `INV-BR-IL-5`   | Code MUST acquire locks in ascending rank order; acquiring a higher-rank lock while holding a lower-rank lock is a deadlock bug.                 |
| `INV-BR-IL-6`   | Default idempotency retention is 24 h; Restore endpoint retention is 7 d; the sweeper failure MUST NOT block requests.                            |
| `INV-BR-IL-7`   | Postgres deadlock detection aborting a tx is treated as a code bug, logged with the violated acquisition order, and MUST NOT be silently retried.|

---

## Cross-References

- Parent: [`11-endpoint-export.md`](./11-endpoint-export.md), [`12-endpoint-import.md`](./12-endpoint-import.md), [`13-endpoint-snapshot.md`](./13-endpoint-snapshot.md), [`14-endpoint-restore.md`](./14-endpoint-restore.md), [`15-jobs-and-progress.md`](./15-jobs-and-progress.md) (each cites this file for the canonical key + lock rules); [`10-secrets-forward-secrecy.md`](./10-secrets-forward-secrecy.md) (`kek.rotate` lock).
- Consumed by: `<spec-placeholder file="17-audit-and-observability.md" />` (`backup.idempotency_hit`, `backup.idempotency_sweep_failed`, `backup.lock_timeout` audit rows), `<spec-placeholder file="18-error-codes.md" />` (`idempotency_key_invalid`, `body_mismatch`, `deadlock_detected`), `<spec-placeholder file="19-state-machines.md" />` (lock-guarded transitions annotated), `<spec-placeholder file="21-cli-parity.md" />` (CLI `--idempotency-key` flag validation), `<spec-placeholder file="24-testing-matrix.md" />` (canonical-JSON hash conformance, body-mismatch replay, lock-timeout, deadlock-order fixtures), `<spec-placeholder file="26-operator-runbook.md" />` (stuck-lock triage using registry names).
- Companion: [`04-invariants.md`](./04-invariants.md) next edit promotes `INV-BR-IL-1..7`.
