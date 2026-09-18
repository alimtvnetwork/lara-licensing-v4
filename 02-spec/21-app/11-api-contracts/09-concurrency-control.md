# Concurrency Control (ETag and If-Match)

**Version:** 1.0.0
**Added:** 2026-07-22 (v0.181.0)

## Purpose

Fix the wire contract for optimistic concurrency on state-mutating License
routes. Prevents lost updates when two operators (or a human plus a
background job) act on the same `Licenses` row between a read and a write.
Referenced by [`02-license-contracts.md`](./02-license-contracts.md) §License
endpoints, [`12-error-taxonomy.md`](../12-error-taxonomy.md), and
[`15-license-lifecycle.md`](../15-license-lifecycle.md).

## Scope

Applies to the following endpoints only; every other mutation is out of scope
and MUST NOT reject a request for `If-Match` presence or absence:

| Endpoint | If-Match | Reasoning |
|----------|----------|-----------|
| `PATCH /Licenses/{LicenseId}` (renew, edit) | required | State transitions can race with reseller portal edits and admin quota adjustments. |
| `DELETE /Licenses/{LicenseId}` (revoke) | required | Revocation is destructive and MUST NOT clobber a concurrent renew or feature grant. |
| `PUT /Licenses/{LicenseId}/Features/{FeatureKey}` | required | Feature writes race with tier changes; server MUST reject writes against a stale tier. |
| `DELETE /Licenses/{LicenseId}/Features/{FeatureKey}` | required | Same reason as the paired `PUT`. |

`POST /Licenses` (issuance) is out of scope: there is no prior version to
match, and `Idempotency-Key` already covers duplicate-issue protection per
[`08-idempotency-envelope-hardening.md`](./08-idempotency-envelope-hardening.md).

## ETag shape

Every `GET /Licenses/{LicenseId}` response MUST carry a strong `ETag`
header. The value is the LOWERCASE hex SHA-256 of the canonicalized
representation of the row (PascalCase keys, sorted, no whitespace,
UTF-8 without BOM), quoted per RFC 9110 §8.8.3:

```
ETag: "3fa9c1b2e4d5..."
```

The hash covers exactly the fields returned in the response body plus
`UpdatedAt`. Weak ETags (`W/"..."`) are not emitted and MUST be rejected
if supplied by a client.

## Request rules

- The client sends `If-Match: "<etag>"` on every in-scope endpoint. The
  value MUST equal a previously received `ETag` verbatim, including quotes.
- Multiple values (`If-Match: "a", "b"`) are accepted per RFC 9110 §13.1.1
  and match if any listed value equals the current server ETag.
- The wildcard `If-Match: *` is REJECTED with `400 ValidationFailed` and
  `Details = [{ "Field": "If-Match", "Rule": "WildcardForbidden" }]`.
  Wildcard would defeat the concurrency guarantee.
- Missing `If-Match` on an in-scope route returns `428 PreconditionRequired`.
- Mismatched `If-Match` returns `412 PreconditionFailed`.

Both failure envelopes MUST include `Attributes.Error.Details` with
`{ "Field": "If-Match", "Rule": "Missing" | "Stale", "Value": "<currentEtag>" }`.
The current ETag is safe to disclose because the caller can already `GET`
the row.

## Server algorithm

1. Read the row inside the same transaction that will write it.
2. Recompute the ETag from the just-read row.
3. Compare against the caller's `If-Match`. On mismatch, abort the
   transaction and return `412 PreconditionFailed`.
4. Apply the mutation.
5. Compute the new ETag from the post-write row and return it in the
   response `ETag` header. Callers that chain writes MUST use this new
   value for the next `If-Match`.

The `If-Match` check runs AFTER `Idempotency-Key` replay lookup per
[`08-idempotency-envelope-hardening.md`](./08-idempotency-envelope-hardening.md).
A replayed response returns the stored ETag verbatim and MUST NOT
re-evaluate the precondition; this preserves replay-safety for clients
that retry on a network blip.

## Interaction with idempotency

When both `Idempotency-Key` and `If-Match` are present on a fresh call,
the server evaluates in this order:

1. `Idempotency-Key` lookup. Hit with same hash returns the stored
   response verbatim.
2. `Idempotency-Key` lookup. Hit with different hash returns
   `409 IdempotencyConflict`.
3. `If-Match` evaluation on the current row.
4. Mutation and new idempotency record write.

## Error codes

Two new codes are reserved in [`12-error-taxonomy.md`](../12-error-taxonomy.md)
§Canonical codes:

| ErrorCode | HTTP | Category | Emitted by | Meaning |
|-----------|------|----------|------------|---------|
| `PreconditionRequired` | 428 | Concurrency | in-scope routes above | `If-Match` header absent on a route that requires it. Never emitted by routes outside the scope table. |
| `PreconditionFailed` | 412 | Concurrency | in-scope routes above | `If-Match` value does not match the current row ETag. `Attributes.Error.Details[0].Value` carries the current ETag so the client can refresh state without a follow-up `GET`. |

Both codes carry `Surface X-Request-Id = yes` and log level `Info`
(not `Warn`) per [`21-error-management-binding.md`](../21-error-management-binding.md)
§Log level ladder; a stale ETag is an expected optimistic-concurrency
outcome, not an anomaly.

## Client contract

`src/lib/lara-license.ts` MUST:

1. Capture the `ETag` header on every `GET /Licenses/{LicenseId}` response
   and cache it alongside the row in TanStack Query keyed by `LicenseId`.
2. Send the cached ETag as `If-Match` on the next `PATCH` or `DELETE`
   for that row.
3. On `412 PreconditionFailed`, invalidate the cached ETag and the
   row query, then surface the conflict to the operator with the
   current `X-Request-Id`; no automatic retry.
4. On `428 PreconditionRequired`, treat as a client bug and log at
   `Error` level. Users MUST NOT see a raw 428.

## Acceptance criteria

- AC-CONCUR-001: A `GET /Licenses/{id}` response includes an `ETag` header
  whose value is the quoted lowercase SHA-256 of the canonicalized row plus
  `UpdatedAt`. Recomputing the hash from the response body plus `UpdatedAt`
  reproduces the header value byte-for-byte.
- AC-CONCUR-002: A `PATCH /Licenses/{id}` without `If-Match` returns
  `428 PreconditionRequired` with `Details[0] = { "Field": "If-Match", "Rule": "Missing" }`
  and MUST NOT open a write transaction.
- AC-CONCUR-003: A `PATCH /Licenses/{id}` whose `If-Match` does not match
  the current row ETag returns `412 PreconditionFailed`, the row is
  unchanged, and `Details[0].Value` equals the current server ETag verbatim.
- AC-CONCUR-004: `If-Match: *` on any in-scope route returns
  `400 ValidationFailed` with `Details[0] = { "Field": "If-Match", "Rule": "WildcardForbidden" }`.
- AC-CONCUR-005: A replayed `PATCH` via `Idempotency-Key` returns the
  stored response and stored ETag verbatim without re-evaluating
  `If-Match`, even if the current row ETag has since changed.
- AC-CONCUR-006: `POST /Licenses` (issuance) accepts a request without
  `If-Match` and MUST NOT reject one that supplies it; the header is
  ignored on out-of-scope routes.

## References

- [`02-license-contracts.md`](./02-license-contracts.md) §License endpoints
- [`08-idempotency-envelope-hardening.md`](./08-idempotency-envelope-hardening.md)
- [`../12-error-taxonomy.md`](../12-error-taxonomy.md) §Canonical codes
- [`../15-license-lifecycle.md`](../15-license-lifecycle.md)
- [`../21-error-management-binding.md`](../21-error-management-binding.md)
- RFC 9110 §8.8.3 (ETag), §13.1.1 (If-Match)
