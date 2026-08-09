# Retry Decision Matrix (Client Callers)

**Version:** 1.0.0
**Updated:** 2026-07-16

---

## Purpose

Complement [`21-error-management-binding.md`](./21-error-management-binding.md) with the caller-side rule set. The server declares log level and retry class per route; this file tells every client (React UI, PowerShell publisher, self-update client, AppBuilder OAuth SDK) exactly what to do when it observes each `ErrorCode` from [`12-error-taxonomy.md`](./12-error-taxonomy.md).

## Normative sources

- [`../03-error-manage/02-error-architecture/`](../03-error-manage/02-error-architecture/): retry class definitions (`NoRetry`, `RetryOnce`, `RetryWithBackoff`, `RetryAfter`, `RefreshThenRetry`, `FatalClear`).
- [`../03-error-manage/03-error-code-registry/`](../03-error-manage/03-error-code-registry/): canonical code list.
- [`21-error-management-binding.md`](./21-error-management-binding.md): per-route retry-class assignment.
- [`14-rate-limiting.md`](./14-rate-limiting.md): `Retry-After` semantics.

## Retry classes

| Class | Client behavior | Max attempts | Backoff |
|-------|-----------------|:------------:|---------|
| `NoRetry` | Surface error, stop. Operator must edit input or state. | 1 | none |
| `RetryOnce` | Transparent single retry after 250 ms jitter. | 2 | 250 ms |
| `RetryWithBackoff` | Exponential backoff, jittered, capped. | 4 | 500 ms, 1 s, 2 s, 4 s (each +/-20% jitter) |
| `RetryAfter` | Wait exactly `Retry-After` seconds from response header, then retry once. | 2 | header-driven |
| `RefreshThenRetry` | Call `POST /Auth/Refresh` once, then retry original request once. On refresh failure escalate to `FatalClear`. | 2 | none |
| `FatalClear` | Discard local auth state, force re-authentication, do NOT retry. | 1 | none |

## Code to class map

| `ErrorCode` | Class | Notes |
|-------------|:-----:|-------|
| `ValidationFailed` | `NoRetry` | Operator fixes input. |
| `NotFound` | `NoRetry` | Includes cross-tenant `NotFound` per `04-roles.md`. |
| `Conflict` | `NoRetry` | Non-idempotency conflicts (unique constraint). |
| `IdempotencyConflict` | `NoRetry` | Caller sent same key with different payload; caller regenerates key. |
| `Forbidden` | `NoRetry` | Role gate; refresh does not help. |
| `AuthzLastAdminProtected` | `NoRetry` | UI disables the button; class documents server refusal. |
| `AuthTokenExpired` | `RefreshThenRetry` | Standard access-token rotation path. |
| `AuthRefreshReused` | `FatalClear` | Detected reuse: clear tokens, force sign-in. |
| `AuthInvalidCredentials` | `NoRetry` | Sign-in only; no retry loop. |
| `RateLimited` | `RetryAfter` | Server sets `Retry-After` + `X-RateLimit-*` per `14-rate-limiting.md`. |
| `UpdateChecksumMismatch` | `NoRetry` | Self-update MUST abort per `17-self-update-endpoint.md`; do not retry download from same URL. |
| `UpdateSizeMismatch` | `NoRetry` | Same as above. |
| `UpdateVersionDowngradeBlocked` | `NoRetry` | Publisher fixes version. |
| `ServerError` | `RetryWithBackoff` | Transient 5xx family. |
| `ServiceUnavailable` | `RetryAfter` if header present, else `RetryWithBackoff`. |
| `NetworkTimeout` (client-synthesized) | `RetryWithBackoff` | Not a server code; client-only classification. |

## Caller bindings

| Caller | Enforcement point | Reference |
|--------|-------------------|-----------|
| React UI | `src/lib/lara-api-client.ts` (refresh dedup), `src/lib/use-retry-after-countdown.ts`, `src/components/retry-after-banner.tsx`. | `tests/lara-api-client.test.ts`, `tests/retry-after-banner.test.tsx` |
| Self-update | `src/lib/lara-self-update.ts` MUST abort on checksum/size mismatch, no retry. | `tests/lara-self-update.test.ts` |
| PowerShell publisher | `scripts/publish-lara.ps1` uses `RetryWithBackoff` for ticket upload, `NoRetry` on manifest submit failures other than transient 5xx. | [`18-publishing-powershell.md`](./18-publishing-powershell.md) |
| AppBuilder OAuth SDK | `RefreshThenRetry` on `AuthTokenExpired`, `FatalClear` on `AuthRefreshReused`. | [`03-authentication-oauth.md`](./03-authentication-oauth.md) |

## Acceptance criteria

- AC-RETRY-001: Every code in [`12-error-taxonomy.md`](./12-error-taxonomy.md) appears in the map above with a single retry class.
- AC-RETRY-002: No caller retries `UpdateChecksumMismatch`, `UpdateSizeMismatch`, `AuthRefreshReused`, `Forbidden`, or `AuthzLastAdminProtected` under any condition.
- AC-RETRY-003: `RetryAfter` callers honor the header value verbatim, never a client-chosen delay.
- AC-RETRY-004: `RefreshThenRetry` deduplicates concurrent refreshes (single in-flight refresh call) per `tests/lara-api-client.test.ts`.
