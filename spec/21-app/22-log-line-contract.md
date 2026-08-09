# Log Line Contract

**Version:** 1.0.0
**Updated:** 2026-07-16

---

## Purpose

Lock the exact structured field set for every server and client log line
emitted by LaraLicensingV1. Extends [`20-observability.md`](./20-observability.md)
(which fixes `X-Request-Id`) with the field-level schema referenced by
[`21-error-management-binding.md`](./21-error-management-binding.md)
(`AC-EMB-003`).

## Normative sources

- [`20-observability.md`](./20-observability.md): correlation and minting.
- [`21-error-management-binding.md`](./21-error-management-binding.md): log level and retry class per route.
- [`../03-error-manage/02-error-architecture/07-logging-and-diagnostics/`](../03-error-manage/02-error-architecture/07-logging-and-diagnostics/): master log architecture.
- [`../03-error-manage/03-error-code-registry/`](../03-error-manage/03-error-code-registry/): error code catalog.

## Required fields

Every server log line (ingress, success, failure) MUST include every field
in the "Required" column. `null` is never allowed for a required field:
emit the sentinel string listed instead of dropping the key.

| Field | Type | Required | Sentinel | Source |
|-------|------|----------|----------|--------|
| `Ts` | ISO 8601 UTC | yes | | server clock |
| `Level` | `Debug` \| `Info` \| `Warn` \| `Error` \| `Fatal` | yes | | binding table |
| `Msg` | short human string, no PII | yes | | handler |
| `RequestId` | ULID or UUIDv4 | yes | | `X-Request-Id` |
| `Route` | route template (e.g. `POST /Licenses/{LicenseId}`) | yes | | router |
| `Method` | HTTP verb | yes | | request |
| `Actor` | `UserId` or `ClientId` | yes | `"anonymous"` | auth |
| `LatencyMs` | integer | ingress+response | | timer |
| `HttpStatus` | integer | response+error | | handler |
| `ErrorCode` | value from taxonomy | error only | | handler |
| `RetryClass` | value from binding table | error only | | binding |
| `RateLimitBucket` | `BucketKey` | 429 only | | rate limiter |
| `RetryAfterSeconds` | integer | 429 or 503 only | | rate limiter |

Optional but recommended: `Ip`, `UserAgent` (redacted), `DbLatencyMs`,
`CacheHit`.

## Redaction

- Never log request bodies verbatim. Log field names plus counts.
- Never log tokens, secrets, hashes, `X-Sha256`, or serial values in full.
  Log the first four chars followed by `...`.
- Never log email, phone, or address contents. Log `UserId` only.
- Redaction failures are a `Fatal` category defect per
  [`../03-error-manage/02-error-architecture/07-logging-and-diagnostics/`](../03-error-manage/02-error-architecture/07-logging-and-diagnostics/).

## Line format

Structured JSON is authoritative. Human-readable formatter for stdout
follows this shape and MUST preserve field order for grep-ability:

```
level=<Level> ts=<Ts> msg="<Msg>" method=<Method> route=<Route> requestId=<RequestId> actor=<Actor> latencyMs=<LatencyMs> httpStatus=<HttpStatus> errorCode=<ErrorCode> retryClass=<RetryClass>
```

## Client log lines

Browser (`src/lib/lara-api-client.ts`) and PowerShell scripts MUST emit at
least these fields on failure:

```
console.error("Lara API error", { path, method, requestId, errorCode, httpStatus, retryClass })
```

PowerShell:

```
ERROR [<ErrorCode>]: <Msg> requestId=<RequestId> retryClass=<RetryClass>
```

## Acceptance

- AC-LOG-001: Every server log line for a request within scope includes every "Required" field.
- AC-LOG-002: Every failure log line carries `ErrorCode` and `RetryClass` matching [`21-error-management-binding.md`](./21-error-management-binding.md).
- AC-LOG-003: No log line contains a token, secret, hash, or serial value in full. Redaction rule is enforced by unit tests on the logger.
