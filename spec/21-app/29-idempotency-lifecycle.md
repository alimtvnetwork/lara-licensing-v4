# Idempotency Lifecycle

**Version:** 1.0.0
**Updated:** 2026-07-16
**Status:** Normative for LaraLicensingV1.

---

## Purpose

`11-api-contracts/08-idempotency-envelope-hardening.md` fixes the wire shape of replay hits and 409 conflicts. This file fixes the server-side lifecycle behind that wire: how the row is created, locked, snapshotted, replayed, and pruned; what happens on crash or partial commit; and the exact canonicalization used to compute `RequestHashSha256`. Without this file, two conforming implementations could return the same envelope for different underlying behaviour.

## Normative sources

- [`11-api-contracts/08-idempotency-envelope-hardening.md`](./11-api-contracts/08-idempotency-envelope-hardening.md): wire contract.
- [`12-error-taxonomy.md`](./12-error-taxonomy.md): `IdempotencyKeyRequired`, `IdempotencyConflict`.
- [`13-audit-logging.md`](./13-audit-logging.md): `IdempotencyConflict` audit action (id 43).
- [`14-rate-limiting.md`](./14-rate-limiting.md): `admin:idempotency-conflict` bucket (§8).
- [`21-error-management-binding.md`](./21-error-management-binding.md): log levels and retry classes.
- [`25-retry-decision-matrix.md`](./25-retry-decision-matrix.md): `NoRetry` for `IdempotencyKeyRequired`, `NoRetry` for `IdempotencyConflict`.
- [`../23-app-db/01-schema.md`](../23-app-db/01-schema.md): `IdempotencyRecords`.

## Decision tree

Every mutation on an in-scope endpoint (per `08-idempotency-envelope-hardening.md` §Scope) follows this decision tree, in order. Skipping any step is a spec violation.

```text
1. Read `Idempotency-Key` header.
   - missing AND endpoint requires it -> 400 IdempotencyKeyRequired (NoRetry). STOP.
   - missing AND endpoint accepts it optionally -> execute normally, no row written. STOP.
   - present, length not in [16, 128] OR non-ASCII -> 400 ValidationFailed on `Idempotency-Key`. STOP.

2. Compute `RequestHashSha256` per §Canonicalization below.

3. Acquire advisory lock keyed by (Endpoint, ActorId, Key). See §Advisory lock key.

4. SELECT the row (Key, ActorId, Endpoint).
   - not found -> proceed to step 5 (fresh execution).
   - found AND ExpiresAt <= now -> DELETE the row, then proceed to step 5.
   - found AND RequestHashSha256 matches AND StatusCode IS NOT NULL -> return stored replay (§Replay). Release lock. STOP.
   - found AND RequestHashSha256 matches AND StatusCode IS NULL -> in-flight duplicate; another worker holds the lock. Wait, then re-read; on wake, jump to step 4.
   - found AND RequestHashSha256 differs -> return 409 IdempotencyConflict (§Conflict). Release lock. STOP.

5. INSERT the row with StatusCode = NULL, ResponseSnapshotJson = NULL, ExpiresAt = CreatedAt + 24h.

6. Execute the handler inside the SAME database transaction as the domain change AND the audit write (per `13-audit-logging.md` §Record shape "Every audit write is transactional with the domain change").

7. On handler success:
   - UPDATE the row: set StatusCode, ResponseSnapshotJson (the exact bytes about to be returned).
   - COMMIT the transaction.
   - Release the advisory lock.
   - Return the response.

8. On handler failure BEFORE commit:
   - ROLLBACK. The INSERT from step 5 is discarded with the domain change and audit row (all-or-nothing).
   - Release the advisory lock.
   - Return the error envelope. The next retry sees no row and re-executes from step 1.
```

## Advisory lock key

PostgreSQL: `pg_advisory_xact_lock(hashtext(Endpoint || ':' || ActorId::text || ':' || Key))`. The lock is transaction-scoped so it auto-releases on COMMIT or ROLLBACK. `hashtext` collision is acceptable because collisions serialize unrelated requests but never mis-order same-key requests.

MySQL: `GET_LOCK(CONCAT('idem:', Endpoint, ':', ActorId, ':', Key), 30)` with an explicit `RELEASE_LOCK` in a `finally` block. A 30-second acquisition timeout maps to `504 UpstreamTimeout` per `21-error-management-binding.md`.

Locks MUST be acquired BEFORE reading the row and released AFTER the response is buffered. Locking after the read is a TOCTOU bug that lets two workers both take the "not found" branch.

## Canonicalization for `RequestHashSha256`

The wire spec says "PascalCase keys, sorted, no whitespace, UTF-8 without BOM". This section fixes the algorithm so two implementations produce identical hashes.

1. Parse the request body as JSON. Reject `InvalidJson` before hashing.
2. Recursively rewrite:
   - Objects: sort keys by Unicode code-point ascending. Keys are already PascalCase per `05-envelope-schema.md`. Do NOT lowercase.
   - Arrays: preserve order. Arrays are semantically ordered in this API.
   - Numbers: emit the shortest round-trip form; integers as `123`, floats via ECMA-262 `Number.prototype.toString` (no trailing zeros, no `+` on exponent).
   - Strings: emit as JSON with `\uXXXX` escapes only for control chars and `"`, `\`. No `\/` escape.
   - Booleans and `null`: emit literal `true`, `false`, `null`.
   - `null`-valued keys are RETAINED (they carry intent). Do not drop them.
3. Serialize with no whitespace, UTF-8, no BOM.
4. SHA-256 the resulting bytes. Store lowercase hex.

Query-string, path parameters, and headers are NOT part of the hash. `Endpoint` (route template) and `ActorId` are part of the row key, not the hash.

## Response snapshot

- Snapshot is the exact bytes the server sent, including `Attributes.RequestId`, `RequestedAt`, and any `Pagination` or `RateLimit` blocks. The replay MUST match byte-for-byte with the sole addition of `Attributes.Idempotency` per §Replay.
- Max snapshot size: 64 KiB. Handlers returning larger bodies MUST NOT be marked idempotent-key-required in `08-idempotency-envelope-hardening.md` §Scope. This is why bulk endpoints are outside scope in v1.
- Snapshots MUST NOT contain access tokens, refresh tokens, or `HashKey`/`VerifyKey` values. Endpoints that emit those values are outside scope in v1.

## Replay

Return the stored snapshot with these mutations to `Attributes`:

- `Idempotency.Key`, `Idempotency.Replayed = true`, `Idempotency.OriginalRequestId`, `Idempotency.OriginalCreatedAt` (from the stored row).
- `RequestId` is refreshed to the current request; the original is preserved as `OriginalRequestId`.
- No other change. In particular, `RateLimit.Remaining` is NOT re-computed; the snapshot value is authoritative to keep replays byte-stable.

Replays DO count against the endpoint's rate-limit buckets (§14) even though they short-circuit the handler; this preserves the cost model against replay floods.

## Conflict

- Row exists with a different hash -> `409 IdempotencyConflict` per `08-idempotency-envelope-hardening.md` §Conflict envelope.
- Emit one `IdempotencyConflict` audit row (action id 43 in `28-audit-action-enum.md`).
- Consume one token from `admin:idempotency-conflict` (`14-rate-limiting.md` §8).
- Retry class is `NoRetry` (`25-retry-decision-matrix.md`). The client must either change the key or fix the body; retrying with the same key and body will NOT resolve the conflict.

## Expiration and pruning

- `ExpiresAt = CreatedAt + 24h`. This is a wall-clock deadline, NOT a sliding TTL. Replays inside the window do not extend the expiry.
- A scheduled job runs every 5 minutes: `DELETE FROM IdempotencyRecords WHERE ExpiresAt < NOW() - INTERVAL '1 minute' LIMIT 5000`. The 1-minute grace and 5000-row batch cap prevent the job from blocking writers.
- The job MUST NOT delete rows with `StatusCode IS NULL` (in-flight, no snapshot yet); those are covered by transaction rollback on crash, not by the pruner.
- Job emits one `INFO` log per run with `RowsDeleted` and `DurationMs`. Zero-row runs still log; silent pruners hide breakage.

## Crash recovery

- Worker crash before COMMIT: the transaction rolls back automatically; no orphan `IdempotencyRecords` row survives (step 5 is inside the same txn).
- Worker crash after COMMIT but before response is sent to the client: the client retries with the same key and body, sees a hash match, and gets a byte-identical replay from step 4. This is the primary correctness guarantee of the whole feature.
- Database restart during a request: the advisory lock is released with the connection; the retry re-acquires it and follows the normal decision tree.

## Observability

- Every decision-tree branch emits one log line at level `INFO` (replay, fresh) or `WARN` (conflict, missing-required) with `RequestId`, `Endpoint`, `Key`, `Outcome ∈ {"Fresh","Replay","Conflict","Missing","Invalid"}`, and `DurationMs`.
- Metrics: counter `laralicensing_idempotency_outcomes_total{Endpoint, Outcome}`; histogram `laralicensing_idempotency_lock_wait_ms{Endpoint}`.

## Acceptance

- AC-IDL-001: The decision tree in §"Decision tree" is followed step-by-step; skipping the advisory lock, hashing after execution, or committing the snapshot in a separate transaction from the domain change is a violation.
- AC-IDL-002: `RequestHashSha256` for a given canonical body is identical across implementations that follow §Canonicalization. Reference vectors ship with the test suite.
- AC-IDL-003: A worker crash between step 5 and step 7 leaves NO orphan row (proved by rollback of the shared transaction).
- AC-IDL-004: A worker crash between step 7 commit and the network flush produces a byte-identical replay on retry.
- AC-IDL-005: The pruner never deletes a row with `StatusCode IS NULL`.
- AC-IDL-006: Replay responses count against `14-rate-limiting.md` buckets exactly like fresh calls.
- AC-IDL-007: Snapshot size exceeds 64 KiB -> the endpoint is not eligible for idempotency-key-required scope; a runtime overflow is a `500 InternalError` with an audit row.
- AC-IDL-008: `IdempotencyKeyRequired`, `IdempotencyConflict`, and `ValidationFailed` on `Idempotency-Key` all map to retry class `NoRetry` in `25-retry-decision-matrix.md`.
