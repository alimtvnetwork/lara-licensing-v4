/**
 * Canonical retry classifier for Plan 11 step 27.
 *
 * Binds every `LaraApiError` to one of the five retry classes defined
 * in `spec/21-app/21-error-management-binding.md` §"Retry policy
 * classes" (lines 43-52):
 *
 *   - `NoRetry`          Never retry. Fix input first.
 *   - `RetryAfter`       Wait for Retry-After seconds, then retry.
 *   - `RefreshThenRetry` Refresh token once, retry once.
 *   - `ExpBackoff`       Exponential backoff, max 3 attempts.
 *   - `FatalClear`       Clear tokens, force re-auth. Do NOT retry.
 *
 * Downstream consumers (step 28 error store, step 29 Global Error
 * Modal, step 30 toast, step 40 retry-budget) read the classifier
 * output instead of hard-coding per-code decisions, so a new backend
 * code cannot silently drift retry semantics without touching this
 * one table.
 *
 * The wrapper `laraFetch` (`src/lib/lara-fetch.ts`) already stamps
 * network failures as `LaraApiError(ServerError, httpStatus=0)`, so
 * transport errors funnel into `ExpBackoff` here without a special
 * case at the call site.
 */

import { ApiErrorCodeType, LaraApiError } from "./lara-api-error";

export enum RetryPolicyType {
  NoRetry = "NoRetry",
  RetryAfter = "RetryAfter",
  RefreshThenRetry = "RefreshThenRetry",
  ExpBackoff = "ExpBackoff",
  FatalClear = "FatalClear",
}

/**
 * Direct code -> policy bindings. Rows omitted from this table fall
 * through to `classifyByHttpStatus` (429 -> RetryAfter, 5xx / 0 ->
 * ExpBackoff, everything else -> NoRetry). Keep this map in lockstep
 * with the endpoint tables in spec/21-app/21-error-management-binding.md.
 */
const POLICY_BY_CODE: ReadonlyMap<ApiErrorCodeType, RetryPolicyType> = new Map([
  // RetryAfter class (spec line 48).
  [ApiErrorCodeType.RateLimited, RetryPolicyType.RetryAfter],
  [ApiErrorCodeType.AbuseBlocked, RetryPolicyType.RetryAfter],
  [ApiErrorCodeType.ServiceUnavailable, RetryPolicyType.RetryAfter],
  [ApiErrorCodeType.MachineRebindCooldownActive, RetryPolicyType.RetryAfter],

  // RefreshThenRetry class (spec line 49). Matches the existing
  // `shouldRetryAfterRefresh` branch in `lara-api-client.ts` line 207.
  [ApiErrorCodeType.AuthTokenExpired, RetryPolicyType.RefreshThenRetry],

  // Retry-with-new-challenge is semantically RetryAfter (immediate).
  // The client MUST fetch a fresh CAPTCHA before resubmitting.
  [ApiErrorCodeType.LoginCaptchaRequired, RetryPolicyType.RetryAfter],
  [ApiErrorCodeType.LoginCaptchaInvalid, RetryPolicyType.RetryAfter],

  // NOTE: `AuthRefreshRaceLost` is deliberately absent. It is handled
  // exclusively inside `performRefresh` (`lara-api-client.ts` line 155)
  // by re-reading the rotated token from storage; it never reaches
  // caller-level retry logic and MUST NOT trigger a second refresh.

  // ExpBackoff class (spec line 50). ServerError from a network wrap
  // in `laraFetch` (httpStatus=0) also lands here.
  [ApiErrorCodeType.ServerError, RetryPolicyType.ExpBackoff],
  [ApiErrorCodeType.UnknownServerError, RetryPolicyType.ExpBackoff],

  // FatalClear class (spec line 51). Refresh reuse means the family
  // is compromised; do NOT retry, clear tokens.
  [ApiErrorCodeType.AuthRefreshReused, RetryPolicyType.FatalClear],
]);

function classifyByHttpStatus(status: number): RetryPolicyType {
  if (status === 0) return RetryPolicyType.ExpBackoff;
  if (status === 429) return RetryPolicyType.RetryAfter;
  if (status >= 500 && status < 600) return RetryPolicyType.ExpBackoff;

  return RetryPolicyType.NoRetry;
}

export function classifyRetryPolicy(error: LaraApiError): RetryPolicyType {
  const explicit = POLICY_BY_CODE.get(error.errorCode);
  if (explicit !== undefined) return explicit;

  return classifyByHttpStatus(error.httpStatus);
}

/**
 * True when the caller should offer a retry surface (toast retry
 * button, modal retry, or automatic backoff). False for terminal
 * failures (validation, forbidden, conflict) and for `FatalClear`
 * because that path clears the session instead of retrying.
 */
export function isRetryable(error: unknown): boolean {
  if (!(error instanceof LaraApiError)) return false;
  const policy = classifyRetryPolicy(error);

  return policy !== RetryPolicyType.NoRetry && policy !== RetryPolicyType.FatalClear;
}

/**
 * Plan 11 step 31: error codes that MUST render as a persistent banner
 * (spec 24 §23.4, spec/21-app/14-rate-limiting.md) and MUST NOT surface
 * as a transient toast. `useLaraErrorToast` filters these out so the
 * inline `<RetryAfterBanner>` (form-scoped) plus the global
 * `<GlobalRateLimitBanner>` (root-scoped, for calls outside a form)
 * own the surface exclusively. Keep this set narrow: only add codes
 * that carry a live Retry-After countdown that the user must see.
 */
export const BANNER_OWNED_ERROR_CODES: ReadonlySet<ApiErrorCodeType> = new Set([
  ApiErrorCodeType.RateLimited,
  ApiErrorCodeType.AbuseBlocked,
  ApiErrorCodeType.MachineRebindCooldownActive,
]);

export function isBannerOwned(error: LaraApiError | { errorCode: ApiErrorCodeType }): boolean {
  return BANNER_OWNED_ERROR_CODES.has(error.errorCode);
}
