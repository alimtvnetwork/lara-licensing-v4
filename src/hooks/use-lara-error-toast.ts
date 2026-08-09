/**
 * Plan 11 step 30: canonical toast surface for retryable LaraApiError entries.
 *
 * Subscribes to the shared `error-store` (step 28) and renders a Sonner
 * toast for every NEW entry whose retry policy is `RetryAfter`,
 * `RefreshThenRetry`, or `ExpBackoff`. Fatal / non-retryable entries
 * (`NoRetry`, `FatalClear`) are OWNED by `GlobalErrorModal` (step 29):
 * this hook must not surface them, otherwise the user sees a modal AND
 * a toast for the same failure.
 *
 * Design notes:
 * - Uses a module-local `Set<string>` of already-surfaced entry ids so
 *   that store trims / re-renders never re-fire a toast for an old
 *   entry. Deduplication inside `error-store` protects against
 *   thrash; this hook only decides "is this a NEW id I have not
 *   toasted yet?".
 * - When `Retry-After` seconds are known (rate limits, cooldowns,
 *   ServiceUnavailable), the seconds are appended to the description so
 *   the user knows how long to wait. This is the single spec-approved
 *   place to inline `retry-after` in a toast; banners still own the
 *   larger `<RetryAfterBanner>` surface.
 * - The hook is idempotent and safe to mount once at the root. Do NOT
 *   mount per-route or you will double-toast on route transitions.
 */

import { useEffect } from "react";
import { toast } from "sonner";

import { subscribeErrorStore, type ErrorStoreEntry } from "@/lib/error-store";
import { RetryPolicyType, BANNER_OWNED_ERROR_CODES } from "@/lib/lara-retry";

const TOAST_POLICIES: ReadonlySet<RetryPolicyType> = new Set([
  RetryPolicyType.RetryAfter,
  RetryPolicyType.RefreshThenRetry,
  RetryPolicyType.ExpBackoff,
]);

const surfacedIds = new Set<string>();

function extractRetryAfterSeconds(entry: ErrorStoreEntry): number | undefined {
  const details = entry.details;
  const isFailed = !details;
  if (isFailed) return undefined;
  for (const raw of details) {
    if (raw && typeof raw === "object" && "retryAfterSeconds" in raw) {
      const value = (raw as { retryAfterSeconds?: unknown }).retryAfterSeconds;
      if (typeof value === "number" && Number.isFinite(value) && value > 0) {
        return Math.ceil(value);
      }
    }
  }

  return undefined;
}

function buildDescription(entry: ErrorStoreEntry): string {
  const parts: string[] = [entry.message];
  const seconds = extractRetryAfterSeconds(entry);
  if (seconds !== undefined) {
    parts.push(`Retrying in ${seconds}s.`);
  }
  if (entry.requestId) {
    parts.push(`Request ${entry.requestId}`);
  }

  return parts.join(" ");
}

function titleFor(entry: ErrorStoreEntry): string {
  switch (entry.retryPolicy) {
    case RetryPolicyType.RetryAfter:
      return "Please retry shortly";
    case RetryPolicyType.RefreshThenRetry:
      return "Refreshing your session";
    case RetryPolicyType.ExpBackoff:
      return "Temporary issue, retrying";
    default:
      return "Request failed";
  }
}

function surface(entry: ErrorStoreEntry): void {
  if (surfacedIds.has(entry.id)) return;
  const is5xx = entry.httpStatus >= 500;
  if (!TOAST_POLICIES.has(entry.retryPolicy) && !is5xx) return;
  // Banner-owned codes (RateLimited, AbuseBlocked, MachineRebindCooldownActive)
  // must render as a persistent banner with a countdown, never as a toast
  // (spec 24 §23.4). Marking as surfaced so a later re-fire cannot leak.
  if (BANNER_OWNED_ERROR_CODES.has(entry.errorCode)) {
    surfacedIds.add(entry.id);

    return;
  }
  surfacedIds.add(entry.id);
  const description = buildDescription(entry);
  if (entry.retryPolicy === RetryPolicyType.RetryAfter) {
    toast.warning(titleFor(entry), { description });

    return;
  }
  toast.error(titleFor(entry), { description });
}

/**
 * Mount once (at `RootComponent`). Returns nothing; side-effect only.
 */
export function useLaraErrorToast(): void {
  useEffect(() => {
    const unsubscribe = subscribeErrorStore((entries) => {
      // entries[0] is the newest; iterate newest -> oldest and surface any
      // ids we have not seen yet. This handles bursts arriving in the same
      // tick without dropping any.
      for (const entry of entries) {
        if (surfacedIds.has(entry.id)) break;
        surface(entry);
      }
    });

    return unsubscribe;
  }, []);
}

/** Test-only reset. */
export function __resetLaraErrorToastForTests(): void {
  surfacedIds.clear();
}
