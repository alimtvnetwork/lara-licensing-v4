/**
 * Plan 11 step 31: root-scoped RetryAfter banner surface.
 *
 * The inline `<RetryAfterBanner>` (src/components/retry-after-banner.tsx)
 * covers form mutations that hold their own `error` state. But requests
 * fired outside a form (dashboard fetches, sidebar counts, background
 * polling, prefetch) also raise `RateLimited` / `AbuseBlocked` /
 * `MachineRebindCooldownActive`; without a global surface those failures
 * are silent because step 30 explicitly excludes banner-owned codes
 * from the toast layer.
 *
 * This component subscribes to `error-store` and renders the newest
 * banner-owned entry at the top of the app until the user dismisses
 * it or the countdown completes and the user retries. It reuses the
 * existing `RetryAfterBanner` renderer so the visual + Retry-After
 * timing rules stay in one place (single source of truth for
 * spec/21-app/14-rate-limiting.md).
 *
 * Ownership contract:
 * - Form-scoped surfaces still render their inline `<RetryAfterBanner>`
 *   next to the submit button; that renderer is idempotent and both
 *   surfaces can coexist without side-effects (each derives its state
 *   from its own `error` prop).
 * - This global surface is for requests that no form owns; it never
 *   fires network retries by itself. `Dismiss` clears the entry from
 *   the store so the banner disappears; the failing call must be
 *   re-triggered from the origin surface.
 */

import { useSyncExternalStore } from "react";

import { LaraApiError } from "@/lib/lara-api-error";
import {
  clearErrorStore,
  getErrorStoreSnapshot,
  subscribeErrorStore,
  type ErrorStoreEntry,
} from "@/lib/error-store";
import { BANNER_OWNED_ERROR_CODES } from "@/lib/lara-retry";
import { RetryAfterBanner } from "@/components/retry-after-banner";

const EMPTY_SNAPSHOT: ReadonlyArray<ErrorStoreEntry> = Object.freeze([]);
function serverEmptySnapshot(): ReadonlyArray<ErrorStoreEntry> {
  return EMPTY_SNAPSHOT;
}

function findBannerEntry(entries: ReadonlyArray<ErrorStoreEntry>): ErrorStoreEntry | undefined {
  for (const entry of entries) {
    if (BANNER_OWNED_ERROR_CODES.has(entry.errorCode)) return entry;
  }

  return undefined;
}

function entryToLaraApiError(entry: ErrorStoreEntry): LaraApiError {
  // Prefer the persisted `rateLimit` (Plan 11 step 35: stored verbatim
  // on `ErrorStoreEntry`); fall back to legacy `details` parsing so
  // existing detail-shaped payloads still drive the countdown.
  const rateLimit = entry.rateLimit ?? extractRateLimit(entry);

  return new LaraApiError(
    entry.message,
    entry.errorCode,
    entry.httpStatus,
    entry.requestId,
    rateLimit,
    entry.errorId,
    entry.details,
  );
}

function extractRateLimit(entry: ErrorStoreEntry) {
  const isFailed = !entry.details;
  if (isFailed) return undefined;
  for (const raw of entry.details) {
    if (raw && typeof raw === "object" && "retryAfterSeconds" in raw) {
      const record = raw as {
        retryAfterSeconds?: unknown;
        bucket?: unknown;
      };
      const seconds =
        typeof record.retryAfterSeconds === "number" ? record.retryAfterSeconds : undefined;
      const bucket = typeof record.bucket === "string" ? record.bucket : undefined;
      if (seconds !== undefined || bucket !== undefined) {
        return { retryAfterSeconds: seconds, bucket };
      }
    }
  }

  return undefined;
}

export function GlobalRateLimitBanner() {
  const entries = useSyncExternalStore(
    subscribeErrorStore,
    getErrorStoreSnapshot,
    serverEmptySnapshot,
  );
  const entry = findBannerEntry(entries);
  const isFailed = !entry;
  if (isFailed) return null;

  return (
    <div className="fixed inset-x-0 top-0 z-50 mx-auto max-w-2xl p-3">
      <RetryAfterBanner error={entryToLaraApiError(entry)} onRetry={() => clearErrorStore()} />
    </div>
  );
}
