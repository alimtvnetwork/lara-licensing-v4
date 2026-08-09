// Plan 11 step 32: form-level submit lock while a banner-owned countdown
// is active.
//
// Root cause: after step 31 the `<RetryAfterBanner>` renders and its own
// Retry button is disabled during the countdown, but the surrounding
// form's submit button is not. Users can re-click Submit during
// Retry-After and drive the rate-limit bucket back into 429 immediately.
// AC-ERR-012 (no double-fire during Retry-After, spec 24 §23.4 and
// spec/21-app/14-rate-limiting.md) fails without this hook.
//
// Contract: the hook accepts the LOCAL error the form is already
// tracking (same object the form passes to `<RetryAfterBanner>`), so the
// lock and the banner always agree on remaining seconds. It reuses
// `isRateLimited` (broadened to `BANNER_OWNED_ERROR_CODES`) and
// `useRetryAfterCountdown` verbatim, so there is a single source of
// truth for both which codes lock the form and how long the lock lasts.

import { isRateLimited, useRetryAfterCountdown } from "./use-retry-after-countdown";

export interface SubmitLockState {
  readonly locked: boolean;
  readonly remainingSeconds: number | undefined;
}

export function useSubmitLock(error: unknown): SubmitLockState {
  const remainingSeconds = useRetryAfterCountdown(error);
  if (isRateLimited(error) === false) {
    return { locked: false, remainingSeconds: undefined };
  }
  // No Retry-After header: we still show the banner but do NOT lock the
  // form. Otherwise a server that forgets the header would strand the
  // user permanently. The banner already flags the missing header.
  if (remainingSeconds === undefined) {
    return { locked: false, remainingSeconds: undefined };
  }

  return { locked: remainingSeconds > 0, remainingSeconds };
}
