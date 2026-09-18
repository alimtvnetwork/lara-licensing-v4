# Envelope Attributes: Error, Pagination, RateLimit

**Version:** 1.0.0
**Updated:** 2026-07-16

---

## Purpose

Fix the exact shape of the three optional attribute blocks inside the
universal envelope defined in [`05-envelope-schema.md`](./05-envelope-schema.md).
Referenced by [`../12-error-taxonomy.md`](../12-error-taxonomy.md),
[`../14-rate-limiting.md`](../14-rate-limiting.md),
[`../21-error-management-binding.md`](../21-error-management-binding.md), and
[`../22-log-line-contract.md`](../22-log-line-contract.md).

## `Attributes.Error`

Emitted on every non-2xx response. Absent on success.

```json
{
  "ErrorCode": "LicenseConflict",
  "ErrorMessage": "License is already revoked.",
  "Details": [
    { "Field": "State", "Rule": "TransitionAllowed", "Actual": "Revoked" }
  ]
}
```

| Field | Type | Required | Notes |
|-------|------|----------|-------|
| `ErrorCode` | string | yes | Value from [`../12-error-taxonomy.md`](../12-error-taxonomy.md). |
| `ErrorMessage` | string | yes | Human message, no PII, no stack, no secret. |
| `Details` | array | optional | Field-level validation or conflict details. |

`Details[]` items MUST use PascalCase keys: `Field`, `Rule`, `Expected`,
`Actual`. Never include token, hash, or serial values in full; apply the
redaction rule from [`../22-log-line-contract.md`](../22-log-line-contract.md).

## `Attributes.Pagination`

Emitted on every list endpoint. Absent on single-item, mutation, and
verify endpoints.

```json
{
  "Page": 1,
  "PageSize": 25,
  "TotalItems": 137,
  "TotalPages": 6,
  "HasNext": true,
  "HasPrevious": false
}
```

| Field | Type | Required | Notes |
|-------|------|----------|-------|
| `Page` | int >= 1 | yes | 1-indexed. |
| `PageSize` | int 1..100 | yes | Server clamps beyond `MaxPageSize=100`. |
| `TotalItems` | int >= 0 | yes | Total across all pages. |
| `TotalPages` | int >= 0 | yes | `ceil(TotalItems / PageSize)`. |
| `HasNext` | bool | yes | Derived. |
| `HasPrevious` | bool | yes | Derived. |

Cursor-based pagination is out of scope for v1. Adding it is a breaking
envelope change.

## `Attributes.RateLimit`

Emitted on 429 responses AND on any 2xx response that consumed a rate
bucket at more than 80% capacity (so clients can back off proactively).

```json
{
  "BucketKey": "Verify:ClientId:cli_123",
  "Limit": 60,
  "Remaining": 3,
  "WindowSeconds": 60,
  "ResetAt": "2026-07-16T10:01:00Z",
  "RetryAfterSeconds": 42
}
```

| Field | Type | Required | Notes |
|-------|------|----------|-------|
| `BucketKey` | string | yes | Stable key from [`../14-rate-limiting.md`](../14-rate-limiting.md). |
| `Limit` | int | yes | Max requests per window. |
| `Remaining` | int | yes | Requests left in current window. |
| `WindowSeconds` | int | yes | Window length. |
| `ResetAt` | ISO 8601 UTC | yes | When the window resets. |
| `RetryAfterSeconds` | int | 429 only | Seconds until retry is admissible. |

Response headers mirror these values: `X-RateLimit-Bucket`,
`X-RateLimit-Limit`, `X-RateLimit-Remaining`, `X-RateLimit-Window`,
`X-RateLimit-Reset`, `Retry-After`. The envelope block is authoritative;
headers exist for HTTP-level middleware only.

## Ordering

Inside `Attributes`, the key order MUST be: `RequestId`, `RequestedAt`,
`Error` (if any), `Pagination` (if any), `RateLimit` (if any). Preserved
key order aids grep and log-based debugging.

## Acceptance

- AC-EAT-001: Every failure response includes `Attributes.Error` with `ErrorCode` and `ErrorMessage`.
- AC-EAT-002: Every list endpoint includes `Attributes.Pagination` with all six fields.
- AC-EAT-003: Every 429 response includes `Attributes.RateLimit` with `RetryAfterSeconds` matching the `Retry-After` header.
- AC-EAT-004: `Details[]` items never contain token, hash, or serial values in full.
