# Error Management Binding

**Version:** 1.0.0
**Updated:** 2026-07-16

---

## Purpose

Bind every endpoint in [`10-endpoints.md`](./10-endpoints.md) to the log
levels, retry policy, and observability rules defined in
[`../03-error-manage/`](../03-error-manage/) and
[`20-observability.md`](./20-observability.md). This file is the single
source of truth for "what does the server do when this call fails".

## Normative sources

- [`../03-error-manage/`](../03-error-manage/): log level ladder,
  retry policy classes, correlation rules.
- [`12-error-taxonomy.md`](./12-error-taxonomy.md): canonical error codes.
- [`14-rate-limiting.md`](./14-rate-limiting.md): bucket policy and
  `Retry-After` semantics.
- [`20-observability.md`](./20-observability.md): `X-Request-Id`,
  structured log fields, redaction rules.
- [`.lovable/coding-guidelines/`](../../.lovable/coding-guidelines/) and
  [`../02-coding-guidelines/`](../02-coding-guidelines/): no swallowed
  errors, PascalCase JSON keys, 15-line function cap.

## Log level ladder

| Level | Trigger | Examples |
|-------|---------|----------|
| `Debug` | Expected control flow, no operator action. | `ValidationFailed`, `LicenseNotFound` on read. |
| `Info` | Successful mutation or state transition. | License issued, role granted, manifest published. |
| `Warn` | Recoverable operator or client fault. | `AuthTokenExpired`, `IdempotencyConflict`, `RateLimited`. |
| `Error` | Server-side fault or invariant violation. | `ServerError`, checksum mismatch on publish, DB constraint hit. |
| `Fatal` | Corrupted state or refresh-family reuse. | `AuthRefreshReused`, `AuthzLastAdminProtected` bypass attempt. |

Every log line MUST include `RequestId`, `Actor`, `Route`, `Method`,
`ErrorCode` (on failure), and `LatencyMs`. Redaction rules in
[`20-observability.md`](./20-observability.md).

## Retry policy classes

| Class | Client behavior | Applies to |
|-------|-----------------|------------|
| `NoRetry` | Never retry. Fix input first. | 400/404/409 validation and conflict codes. |
| `RetryAfter` | Wait for `Retry-After` seconds, then retry. | `RateLimited`, `AbuseBlocked` (with backoff), `ServiceUnavailable`. |
| `RefreshThenRetry` | Refresh token once, retry once. | `AuthTokenExpired`. |
| `ExpBackoff` | Exponential backoff, max 3 attempts. | `ServerError`, transient `ServiceUnavailable`. |
| `FatalClear` | Clear tokens, force re-auth. Do NOT retry. | `AuthRefreshReused`. |

## Endpoint bindings

### Auth

| Route | Failure code | Log level | Retry class |
|-------|--------------|-----------|-------------|
| `POST /Auth/Token` | `AuthInvalidCredentials` | `Warn` | `NoRetry` |
| `POST /Auth/Refresh` | `AuthTokenExpired` | `Warn` | `RefreshThenRetry` |
| `POST /Auth/Refresh` | `AuthRefreshReused` | `Fatal` | `FatalClear` |
| `POST /Auth/Revoke` | `AuthUnauthorized` | `Warn` | `NoRetry` |
| `POST /OAuth/Token` | `OAuthInvalidGrant` | `Warn` | `NoRetry` |

### Licenses and Serials

| Route | Failure code | Log level | Retry class |
|-------|--------------|-----------|-------------|
| `POST /Licenses` | `ValidationFailed`, `PrefixNotFound` | `Debug` | `NoRetry` |
| `GET /Licenses/{Id}` | `LicenseNotFound`, `AuthzRowScopeDenied` | `Debug` | `NoRetry` |
| `PATCH /Licenses/{Id}` | `LicenseConflict` | `Warn` | `NoRetry` |
| `DELETE /Licenses/{Id}` | `AuthzRoleDenied` | `Warn` | `NoRetry` |
| `POST /Licenses/{Id}/Serials` | `IdempotencyConflict` | `Warn` | `NoRetry` |
| `POST /Licenses/{Id}/Serials` | `RateLimited` | `Warn` | `RetryAfter` |
| `GET /Serials/{Value}` | `SerialNotFound`, `SerialRevoked` | `Debug` | `NoRetry` |

### Verify

| Route | Failure code | Log level | Retry class |
|-------|--------------|-----------|-------------|
| `POST /Verify/Serial` | `SerialInvalid`, `SerialNotFound` | `Debug` | `NoRetry` |
| `POST /Verify/Serial` | `RateLimited`, `AbuseBlocked` | `Warn` | `RetryAfter` |
| `POST /Verify/Hash` | `VerifyHashInvalid` | `Warn` | `NoRetry` |
| `POST /Verify/Final` | `VerifyKeyExpired`, `VerifyKeyMismatch` | `Warn` | `NoRetry` |

### Admin: Resellers, Prefixes, Users

| Route | Failure code | Log level | Retry class |
|-------|--------------|-----------|-------------|
| `POST /Resellers` | `ResellerConflict` | `Warn` | `NoRetry` |
| `DELETE /Resellers/{Id}` | `ResellerInUse` | `Warn` | `NoRetry` |
| `POST /Resellers/{Id}/Prefixes` | `PrefixConflict` | `Warn` | `NoRetry` |
| `DELETE /Prefixes/{Id}` | `PrefixInUse` | `Warn` | `NoRetry` |
| `POST /Users/{Id}/Roles` | `ResourceRoleAlreadyAssigned` | `Warn` | `NoRetry` |
| `DELETE /Users/{Id}/Roles` | `AuthzLastAdminProtected` | `Fatal` | `NoRetry` |
| `DELETE /Users/{Id}/Roles` | `ResourceRoleNotAssigned` | `Debug` | `NoRetry` |

### Self-Update

| Route | Failure code | Log level | Retry class |
|-------|--------------|-----------|-------------|
| `GET /App/UpdateManifest` | `UpdateManifestUnavailable` | `Info` | `NoRetry` |
| `GET /App/UpdateManifest` | `UpdateChannelForbidden` | `Warn` | `NoRetry` |
| `GET /App/UpdateAsset/*` | `UpdateAssetNotFound` | `Error` | `NoRetry` |
| `POST /Admin/AppUpdates/UploadTicket` | `AuthzRoleDenied` | `Warn` | `NoRetry` |
| `POST /Admin/AppUpdates` | `UpdateChecksumMismatch` | `Error` | `NoRetry` |
| `POST /Admin/AppUpdates` | `UpdateVersionDowngradeBlocked` | `Warn` | `NoRetry` |

### Cross-cutting

| Trigger | Code | Log level | Retry class |
|---------|------|-----------|-------------|
| Missing `X-Request-Id` on strict-list route | `RequestIdMissing` | `Warn` | `NoRetry` |
| Unhandled exception | `ServerError` | `Error` | `ExpBackoff` |
| Dependency outage | `ServiceUnavailable` | `Error` | `RetryAfter` |

## Enforcement

- Handlers MUST NOT catch and discard: every caught error either
  re-throws or logs at `Warn` or higher with `RequestId`.
- The retry class is authoritative for client SDKs. UI surfaces
  (see [`16-ui-surfaces.md`](./16-ui-surfaces.md)) MUST reflect it
  (for example, `RetryAfterBanner` on any `RetryAfter` class).
- Tests: every code in [`12-error-taxonomy.md`](./12-error-taxonomy.md)
  MUST have at least one route in this table.

## Acceptance

- AC-EMB-001: Every route in [`10-endpoints.md`](./10-endpoints.md)
  appears in at least one binding table above.
- AC-EMB-002: Every error code in
  [`12-error-taxonomy.md`](./12-error-taxonomy.md) is bound to a log
  level and retry class.
- AC-EMB-003: Server logs for a failed call include `RequestId`,
  `Route`, `ErrorCode`, `LogLevel`, `RetryClass`.
