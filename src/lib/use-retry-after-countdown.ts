import { useEffect, useState } from "react";

import { LaraApiError } from "./lara-api-error";
import { getRetryAfterSeconds } from "./lara-api-error";
import { BANNER_OWNED_ERROR_CODES } from "./lara-retry";

/**
 * Countdown for a RateLimited LaraApiError. Ticks once per second while the
 * remainder is positive, then reports 0 so the caller can re-enable retry.
 * Contract for spec/21-app/14-rate-limiting.md: `Retry-After` is the source of
 * truth; when the header is absent we return undefined and never fabricate a
 * countdown, because doing so would hide a spec violation.
 */
export function useRetryAfterCountdown(error: unknown): number | undefined {
  const initial = getRetryAfterSeconds(error);
  const [remaining, setRemaining] = useState<number | undefined>(initial);

  useEffect(() => {
    const next = getRetryAfterSeconds(error);
    setRemaining(next);
    if (next === undefined || next <= 0) return;
    const startedAt = Date.now();
    const interval = window.setInterval(() => {
      const elapsed = Math.floor((Date.now() - startedAt) / 1000);
      const left = next - elapsed;
      if (left <= 0) {
        setRemaining(0);
        window.clearInterval(interval);

        return;
      }
      setRemaining(left);
    }, 1000);

    return () => window.clearInterval(interval);
  }, [error]);

  return remaining;
}

export function isRateLimited(error: unknown): boolean {
  // Plan 11 step 31: broadened to every banner-owned code (RateLimited,
  // AbuseBlocked, MachineRebindCooldownActive) so `<RetryAfterBanner>`
  // can render for the whole banner class, not just the 429 path.
  return error instanceof LaraApiError && BANNER_OWNED_ERROR_CODES.has(error.errorCode);
}
