# API Contracts

**Version:** 1.1.0
**Updated:** 2026-07-16
**AI Confidence:** Medium
**Ambiguity:** Low

## Vocabulary sources

Role strings in request/response bodies cite [`../04-roles.md`](../04-roles.md) §Canonical set. `LicenseCategory` values cite [`../05-license-categories.md`](../05-license-categories.md) §Canonical set. `LicenseVariation` parameter names cite [`../06-license-variations.md`](../06-license-variations.md) §Canonical set. Contracts MUST NOT introduce synonyms.

**Ambiguity:** Low

## Universal envelope

The canonical envelope schema is fixed in [`05-envelope-schema.md`](./05-envelope-schema.md);
optional attribute blocks (`Error`, `Pagination`, `RateLimit`) are fixed in
[`06-envelope-attributes.md`](./06-envelope-attributes.md). The example below
is illustrative only:

```json
{
  "Status": { "IsSuccess": true, "Code": 200, "Message": "OK" },
  "Attributes": { "RequestId": "01HXYZ", "RequestedAt": "2026-07-16T00:00:00Z" },
  "Results": []
}
```

On failure, `Results` is empty and `Attributes.Error` carries `ErrorCode`, `ErrorMessage`, and optional `Details`. `Status` never carries `ErrorCode`. Every response echoes `X-Request-Id` equal to `Attributes.RequestId`.

## Files

| File | Scope |
|------|-------|
| [`01-auth-contracts.md`](./01-auth-contracts.md) | JWT and OAuth request and response contracts. |
| [`02-license-contracts.md`](./02-license-contracts.md) | License and serial contracts. |
| [`03-verification-contracts.md`](./03-verification-contracts.md) | Serial, hash, and final verification contracts. |
| [`04-admin-contracts.md`](./04-admin-contracts.md) | Reseller, prefix, user, and role contracts. |
| [`05-envelope-schema.md`](./05-envelope-schema.md) | Canonical `Status`/`Attributes`/`Results` shape and ordering. |
| [`06-envelope-attributes.md`](./06-envelope-attributes.md) | `Error`, `Pagination`, `RateLimit` attribute schemas. |
| [`07-admin-list-envelope-hardening.md`](./07-admin-list-envelope-hardening.md) | Binds Admin list endpoints to `Attributes.Pagination`; forbids cursor pagination in v1. |
| [`08-idempotency-envelope-hardening.md`](./08-idempotency-envelope-hardening.md) | `Idempotency-Key` scope, replay envelope, and `409 IdempotencyConflict` envelope. |

## Shared rules

- JSON keys and enum values use PascalCase.
- Timestamps use ISO 8601 UTC. Identifiers are positive integers unless specified otherwise.
- Unknown JSON properties return `400 ValidationFailed`.
- Malformed JSON returns `400 InvalidJson`. Unsupported media types return `415 UnsupportedMediaType`.
- Protected endpoints return `401 AuthUnauthorized` for missing or invalid authentication and `403 AuthForbidden` for insufficient permission.
- Mutations accept `X-Request-Id`; the server generates a ULID when absent.
- Logs include request id, route template, actor type, actor id, outcome, duration, and error code. Secrets, raw tokens, passwords, hashes, and fingerprints are excluded.

## Acceptance

- AC-API-001: Every endpoint in [`../10-endpoints.md`](../10-endpoints.md) has a request, result, and response-code contract in this folder.
- AC-API-002: Success and failure examples validate against the universal envelope.
- AC-API-003: Every documented error code has one HTTP status and no secret-bearing attributes.
## Error codes

The closed set of `ErrorCode` values, HTTP status mapping, and category rules live in [`../12-error-taxonomy.md`](../12-error-taxonomy.md). No contract in this folder may emit a code not listed there.
