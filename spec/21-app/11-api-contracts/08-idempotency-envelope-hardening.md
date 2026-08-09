# Idempotency Envelope Hardening

**Version:** 1.2.0
**Updated:** 2026-07-16

## Casing and correlation invariants (Plan 03 step 13)

- Request-body canonicalization for `RequestHashSha256` operates on the PascalCase JSON keys defined in [`05-envelope-schema.md`](./05-envelope-schema.md) §JSON casing. Keys are sorted lexicographically, whitespace stripped, UTF-8 without BOM. A request that violates PascalCase is rejected with `400 ValidationFailed` BEFORE the hash is computed and BEFORE any `IdempotencyRecords` row is written. Rejected requests do not occupy the key.
- The server MUST echo `Idempotency-Key` in the response header on both fresh and replayed responses so clients can confirm the row that answered them. The header value equals `Attributes.Idempotency.Key`.
- `Attributes.RequestId` on a replayed response is a fresh ULID for the current call; `Attributes.Idempotency.OriginalRequestId` is the request id from the first successful call. Client log lines MUST carry the current `RequestId`; UI surfaces on conflict MUST render the current `RequestId` per [`05-envelope-schema.md`](./05-envelope-schema.md) §Request-Id propagation.

---

## Purpose

Extend the idempotency contract fixed for serial creation in
[`02-license-contracts.md`](./02-license-contracts.md) §Idempotency to every
mutation that requires replay-safety, and fix the exact envelope shape for
replay hits and key conflicts. The server-side lifecycle (decision tree,
advisory lock, canonicalization algorithm, crash recovery, and pruning) is
normatively fixed in [`../29-idempotency-lifecycle.md`](../29-idempotency-lifecycle.md);
this file governs the wire shape only. Referenced by
[`../12-error-taxonomy.md`](../12-error-taxonomy.md),
[`../14-rate-limiting.md`](../14-rate-limiting.md),
[`../13-audit-logging.md`](../13-audit-logging.md), and
[`../21-error-management-binding.md`](../21-error-management-binding.md).

## Header

Clients supply `Idempotency-Key` (ULID or opaque, 16-128 chars). The server
never generates one on the caller's behalf. Missing header on an endpoint
in the required list below returns `400 IdempotencyKeyRequired`.

## Scope

Endpoints that MUST honour `Idempotency-Key`:

| Endpoint | Required? | Rationale |
|----------|-----------|-----------|
| `POST /Licenses/{LicenseId}/Serials` | required | Serial issuance is externally visible and irreversible. |
| `POST /Licenses` | required | License issuance charges quota; retries must not double-issue. |
| `PATCH /Licenses/{LicenseId}` (renew) | required | State transitions must not double-apply on client retry. |
| `DELETE /Licenses/{LicenseId}` (revoke) | required | Revocation is destructive; retries must be idempotent. |
| `POST /Resellers`, `POST /Users`, `POST /Users/{UserId}/Roles` | optional | Uniqueness constraints already prevent duplicates; key accepted if supplied. |

All other mutations ignore the header if supplied and MUST NOT reject the
request for its presence.

## Storage

Every accepted key writes one row to `IdempotencyRecords`:

```
{ Key, ActorId, Endpoint, RequestHashSha256, ResponseSnapshotJson, StatusCode, CreatedAt, ExpiresAt }
```

- `Endpoint` is the route template (e.g. `POST /Licenses/{LicenseId}/Serials`), not the concrete path.
- `RequestHashSha256` is the SHA-256 of the canonicalized request body (PascalCase keys, sorted, no whitespace, UTF-8 without BOM).
- `ExpiresAt` = `CreatedAt + 24h`. Pruning shares the job that prunes `RateLimitBuckets` (see [`../14-rate-limiting.md`](../14-rate-limiting.md)).

Uniqueness constraint: `UNIQUE(Key, ActorId, Endpoint)`. The same key from
two different actors or endpoints is not a collision.

## Replay envelope (hit, same hash)

When `Key` matches and `RequestHashSha256` matches, the server MUST return
the stored `ResponseSnapshotJson` verbatim with `StatusCode` and add the
following to `Attributes`:

```json
"Idempotency": {
  "Key": "01HXYZ...",
  "Replayed": true,
  "OriginalRequestId": "01HXPP...",
  "OriginalCreatedAt": "2026-07-16T00:00:00Z"
}
```

`Attributes.RequestId` reflects the CURRENT request (fresh ULID);
`OriginalRequestId` is the request id from the first successful call. The
response body is otherwise byte-identical to the original.

## Conflict envelope (hit, different hash)

When `Key` matches but `RequestHashSha256` differs, the server MUST return
`409 IdempotencyConflict` with:

```json
{
  "Status": { "IsSuccess": false, "Code": 409, "Message": "Idempotency-Key reused with a different request body." },
  "Attributes": {
    "RequestId": "01HXYZ...",
    "RequestedAt": "2026-07-16T00:00:00Z",
    "Error": {
      "ErrorCode": "IdempotencyConflict",
      "ErrorMessage": "Idempotency-Key was previously used for a different request.",
      "Details": [
        { "Field": "Idempotency-Key", "Rule": "HashMatch", "Expected": "<sha256 of original>", "Actual": "<sha256 of current>" }
      ]
    },
    "Idempotency": {
      "Key": "01HXYZ...",
      "Replayed": false,
      "OriginalRequestId": "01HXPP...",
      "OriginalCreatedAt": "2026-07-16T00:00:00Z"
    }
  },
  "Results": []
}
```

The conflict MUST emit one `IdempotencyConflict` row per
[`../13-audit-logging.md`](../13-audit-logging.md) and MUST consume one
token from the `actor:User:{UserId}` `IdempotencyConflict` bucket (`60:20`,
see [`../14-rate-limiting.md`](../14-rate-limiting.md)).

## In-flight duplicate

If a second request arrives with the same `Key` while the first is still
executing, the server MUST serialize the second on the row's advisory lock
until the first commits, then treat it as a replay (same hash) or conflict
(different hash) per the rules above. Concurrent in-flight duplicates MUST
NOT double-execute the handler.

## Ordering

Inside `Attributes`, `Idempotency` (if present) is emitted after `Error`
and before `Pagination`, preserving the key order rule from
[`06-envelope-attributes.md`](./06-envelope-attributes.md) §Ordering:
`RequestId`, `RequestedAt`, `Error`, `Idempotency`, `Pagination`, `RateLimit`.

## Acceptance

- AC-IEH-001: Every endpoint in the required list returns `400 IdempotencyKeyRequired` when the header is missing.
- AC-IEH-002: Replay hits return the original `ResponseSnapshotJson` byte-identically, plus `Attributes.Idempotency` with `Replayed: true`.
- AC-IEH-003: Key reuse with a different `RequestHashSha256` returns `409 IdempotencyConflict` with `Attributes.Idempotency.Replayed: false` AND emits one audit row AND consumes one `IdempotencyConflict` bucket token.
- AC-IEH-004: `IdempotencyRecords` rows expire exactly 24h after `CreatedAt` and are pruned by the shared `RateLimitBuckets` job.
- AC-IEH-005: Concurrent in-flight duplicates serialize on the row's advisory lock and never double-execute the handler.
- AC-IEH-006: The same key from a different `ActorId` or `Endpoint` is not a collision.
- AC-IEH-007: `ResponseSnapshotJson` is capped at 64 KiB per [`../29-idempotency-lifecycle.md`](../29-idempotency-lifecycle.md) §"Response snapshot"; endpoints that would exceed the cap are excluded from the required list above.
- AC-IEH-008: Access tokens, refresh tokens, `HashKey`, and `VerifyKey` values MUST NOT appear in `ResponseSnapshotJson`; endpoints emitting those values are outside the idempotency-required scope in v1.
- AC-IEH-009: `IdempotencyKeyRequired` and `IdempotencyConflict` both map to retry class `NoRetry` per [`../25-retry-decision-matrix.md`](../25-retry-decision-matrix.md); UI surfaces MUST NOT auto-retry either code.
- AC-IEH-010: `RequestHashSha256` is computed over PascalCase, lexicographically-sorted, whitespace-stripped, UTF-8-without-BOM canonical form; a non-PascalCase request key returns `400 ValidationFailed` and does not occupy the key.
- AC-IEH-011: The response echoes `Idempotency-Key` in the response header on both fresh and replayed responses, equal to `Attributes.Idempotency.Key`.
- AC-IEH-012: A replayed response carries a fresh `Attributes.RequestId` (the current call) while `Attributes.Idempotency.OriginalRequestId` preserves the original.
