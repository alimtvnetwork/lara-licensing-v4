# Error Code Test Coverage Matrix

**Version:** 1.0.0
**Updated:** 2026-07-16

---

## Purpose

Bind every canonical `ErrorCode` from [`12-error-taxonomy.md`](./12-error-taxonomy.md) to the automated test that proves the code, retry class, and log level all fire correctly. Without this matrix, `25-retry-decision-matrix.md` claims are unverifiable and regressions land silently.

## Normative sources

- [`12-error-taxonomy.md`](./12-error-taxonomy.md): canonical codes.
- [`25-retry-decision-matrix.md`](./25-retry-decision-matrix.md): caller retry class per code.
- [`21-error-management-binding.md`](./21-error-management-binding.md): server log level and retry class.
- `tests/*.test.ts(x)`: current suites (6 files, run via `vitest run` gate in `package.json`).

## Matrix

| `ErrorCode` | Caller class | Test file | Test id | Status |
|-------------|:------------:|-----------|---------|:------:|
| `ValidationFailed` | `NoRetry` | `tests/lara-api-error.test.ts` | `formats ValidationFailed with field details` | covered |
| `NotFound` | `NoRetry` | `tests/lara-api-error.test.ts` | `formats NotFound` | covered |
| `Conflict` | `NoRetry` | `tests/lara-api-error.test.ts` | `formats Conflict` | covered |
| `IdempotencyConflict` | `NoRetry` | `tests/idempotency-conflict.test.ts` | `formats IdempotencyConflict with RequestId` | covered |
| `IdempotencyKeyRequired` | `NoRetry` | `tests/idempotency-conflict.test.ts` | `formats IdempotencyKeyRequired with RequestId` | covered |
| `Forbidden` | `NoRetry` | `tests/lara-api-error.test.ts` | `formats Forbidden` | covered |
| `AuthzLastAdminProtected` | `NoRetry` | `tests/last-admin-guard.test.tsx` | `blocks revoke on last active Admin` | covered |
| `AuthTokenExpired` | `RefreshThenRetry` | `tests/lara-api-client.test.ts` | `refreshes on 401 AuthTokenExpired` | covered |
| `AuthRefreshReused` | `FatalClear` | `tests/lara-api-client.test.ts` | `clears tokens on AuthRefreshReused` | covered |
| `AuthInvalidCredentials` | `NoRetry` | pending: `tests/auth-invalid-credentials.test.ts` | AC-EC-TEST-004 | gap |
| `RateLimited` | `RetryAfter` | `tests/retry-after-banner.test.tsx` + `tests/lara-api-response.test.ts` | multiple | covered |
| `RequestIdMissing` | `NoRetry` | `tests/lara-api-response.test.ts` | `parses X-Request-Id precedence` | partial |
| `UpdateChecksumMismatch` | `NoRetry` | `tests/lara-self-update.test.ts` | `aborts on SHA-256 mismatch` | covered |
| `UpdateSizeMismatch` | `NoRetry` | `tests/lara-self-update.test.ts` | `aborts on size mismatch` | covered |
| `UpdateVersionDowngradeBlocked` | `NoRetry` | pending: `tests/publish-lara-downgrade.test.ts` | AC-EC-TEST-005 | gap |
| `ServerError` | `RetryWithBackoff` | `tests/lara-api-response.test.ts` | `wraps invalid JSON as ServerError` | covered |
| `ServiceUnavailable` | `RetryAfter` or `RetryWithBackoff` | pending: `tests/service-unavailable.test.ts` | AC-EC-TEST-006 | gap |

## Gap register

Three codes remain declared normative but lack a proving test. Each gap MUST land as a listed pending file before the next audit; see [`../25-app-audit/98-findings-index.md`](../25-app-audit/98-findings-index.md) for the running tally.

| Gap id | Owner | Blocker? |
|--------|-------|:--------:|
| `AC-EC-TEST-004` (AuthInvalidCredentials) | frontend | no |
| `AC-EC-TEST-005` (UpdateVersionDowngradeBlocked) | scripts | yes for publisher dry-run |
| `AC-EC-TEST-006` (ServiceUnavailable) | frontend | no |

## Acceptance

- AC-EC-TEST-000: Every code in [`12-error-taxonomy.md`](./12-error-taxonomy.md) appears here once with a caller class matching [`25-retry-decision-matrix.md`](./25-retry-decision-matrix.md).
- AC-EC-TEST-001..006: Each gap resolves by adding the named test file and flipping `Status` to `covered`; no gap may be silently dropped.
- AC-EC-TEST-007: `vitest run` remains part of the `build` and `build:dev` scripts (see `package.json`), so any newly added test file becomes a build gate automatically.
