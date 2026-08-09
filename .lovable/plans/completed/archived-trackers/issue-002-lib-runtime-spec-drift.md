# Issue 002: `src/lib/lara-*.ts` runtime drift from amended specs

Status: resolved (F1-F4 landed; Issue 03 verify-path decision still outstanding)
Created: 2026-07-16 (v0.102.0)
Plan: `.lovable/plans/pending/03-runtime-alignment.md`

## Inventory (M5 step 1)

| File | Lines | Spec sections that bind it |
|---|---|---|
| `lara-api-client.ts` | 172 | 11-api-contracts, 14-rate-limiting v1.2.0, 20-observability, 31-auth-session-family v1.0.0 |
| `lara-api-response.ts` | 103 | 11-api-contracts (envelope), 14-rate-limiting v1.2.0, 22-log-line-contract |
| `lara-api-error.ts` | 126 | 12-error-taxonomy v1.1.0, 14-rate-limiting v1.2.0 |
| `lara-api-contract.ts` | 37 | 11-api-contracts (envelope shapes) |
| `lara-api-session.ts` | 30 | 31-auth-session-family (client-side token cache only) |
| `lara-auth.ts` | 28 | 02-authentication-jwt, 31-auth-session-family |
| `lara-self-update.ts` | 241 | 17-self-update-endpoint v1.1.0 |
| `lara-license.ts` | 95 | 15-license-lifecycle, 11-api-contracts |
| `lara-serial.ts` | 50 | 15-license-lifecycle, 29-idempotency-lifecycle |
| `lara-prefix.ts` | 56 | 11-api-contracts |
| `lara-reseller.ts` | 68 | 11-api-contracts |
| `lara-user-role.ts` | 74 | 11-api-contracts, user-roles |
| `lara-shell-role.ts` | 20 | 24-vocabulary-normalization |

## Diff findings (M5 step 2)

### F1: `ApiErrorCodeType` missing the two codes reserved in v1.1.0

- `spec/21-app/12-error-taxonomy.md` v1.1.0 lines 63-64 reserve `AuthRefreshRaceLost` (409) and `AuthSaltRotationFailed` (500).
- `src/lib/lara-api-error.ts` enum (lines 1-57) did not contain either code. A 409 refresh response fell through to `UnknownServerError` via the lenient path (F4), losing the "re-read and retry" hint the spec pins.
- **Resolved in v0.111.0.** Added both enum members with JSDoc citing spec line 67 (`AuthRefreshRaceLost`, 409, retryable, MUST NOT invalidate session) and line 68 (`AuthSaltRotationFailed`, 500, internal-only, reserved-not-emitted). Regression test in `tests/lara-api-error.test.ts` "returns undefined for the two newly reserved Auth codes (F1 guard)" asserts `getRetryAfterSeconds` treats both as non-rate-limited.

### F2: `AuthRefreshRaceLost` re-read + single retry orchestration

- `src/lib/lara-api-client.ts` (v0.112.0) documents non-membership in `REFRESH_FATAL_CODES` (lines 32-47) and implements the spec's "re-read rotated token, retry once" flow in `performRefresh` (lines 127-160) via `attemptRefresh` helper.
- Behaviour: on `AuthRefreshRaceLost`, re-reads `getLaraRefreshToken()`; if a rotated value is present and differs, retries refresh exactly once and warn-logs with `requestId`; if storage is unchanged, warn-logs and returns `false` so the caller propagates the original triggering error with session preserved.
- **Resolved in v0.112.0.** Regression tests in `tests/lara-api-client.test.ts`: "F2: on AuthRefreshRaceLost, re-reads rotated token from storage and retries refresh once" and "F2: on AuthRefreshRaceLost with no rotated token in storage, preserves session and propagates".


### F3: `getRetryAfterSeconds` filter is spec-exact but has no test for `AbuseBlocked` exclusion

- `spec/21-app/14-rate-limiting.md` AC-RL-008 line 192: `AbuseBlocked` (403) MUST NOT carry `Retry-After`.
- `src/lib/lara-api-error.ts` line 114 filters to `RateLimited` only. Behaviour was correct; negative test was missing.
- **Resolved in v0.111.0.** Added `tests/lara-api-error.test.ts` case "returns undefined for AbuseBlocked even if rateLimit metadata is present (AC-RL-008)" that constructs a 403 `AbuseBlocked` `LaraApiError` with populated `retryAfterSeconds` and asserts `getRetryAfterSeconds` returns `undefined`.

### F4: `parseFailure` does not preserve unknown error codes for future-reserved slots

- `src/lib/lara-api-contract.ts` currently constrains `ErrorCode` to the enum. Any new code added server-side (e.g. `AuthRefreshRaceLost` before F1 fix lands) causes envelope-mismatch fallthrough at `lara-api-response.ts` line 78-84, downgrading a real error to a generic `ServerError` and losing the requestId chain.
- **Resolved in v0.110.0.** Added `apiFailureLenientSchema` (`lara-api-contract.ts`) and `createUnknownCodeFailure` (`lara-api-response.ts`); on strict-parse failure the lenient schema runs and, when it succeeds, warn-logs `Lara API unknown error code` with `{path, status, requestId, unknownCode}` and returns a new `ApiErrorCodeType.UnknownServerError` (registered in `12-error-taxonomy.md` v1.4.0) with the original `requestId` preserved. Test coverage: `tests/lara-api-response.test.ts` "returns UnknownServerError and warns with raw code for unrecognised ErrorCode (F4)".

## Not-drift findings

- `X-Request-Id` header is emitted on every request (`lara-api-client.ts` lines 30, 65). AC-OBS-* satisfied at transport layer.
- `Retry-After`/`X-RateLimit-*` are captured into `RateLimitMetadata` when `ErrorCode === RateLimited` (`lara-api-response.ts` line 93-94). Spec-exact.
- Refresh single-flight guard (`lara-api-client.ts` lines 114, 144-151) matches the reuse-detection intent of `31-auth-session-family.md` at the client side.
- Self-update SHA-256 verification lives in `lara-self-update.ts` and is exercised by an existing Vitest suite; deeper 3-step state-machine parity is deferred to step 4 of Plan 03.

## Next step preview

Step 3 will land F1 + F2 as the minimum correct change: add the two enum members with JSDoc citing the spec lines, and keep `AuthRefreshRaceLost` out of `REFRESH_FATAL_CODES` (documented, not just implicit). F3 test gap and F4 unknown-code preservation land in later Plan 03 steps.
