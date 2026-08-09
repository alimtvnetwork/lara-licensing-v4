// Plan 11 step 32: `useSubmitLock` disables form submission while a
// banner-owned Retry-After countdown is active.

import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { renderHook, act, cleanup } from "@testing-library/react";

import { useSubmitLock } from "@/lib/use-submit-lock";
import { ApiErrorCodeType, LaraApiError } from "@/lib/lara-api-error";

function make(code: ApiErrorCodeType, retryAfterSeconds?: number): LaraApiError {
  return new LaraApiError(
    `${code} failed`,
    code,
    429,
    "req-x",
    retryAfterSeconds !== undefined ? { retryAfterSeconds, bucket: "login" } : undefined,
  );
}

beforeEach(() => {
  vi.useFakeTimers();
});

afterEach(() => {
  vi.useRealTimers();
  cleanup();
});

describe("useSubmitLock (Plan 11 step 32)", () => {
  it("returns unlocked when there is no error", () => {
    const { result } = renderHook(() => useSubmitLock(null));
    expect(result.current.locked).toBe(false);
    expect(result.current.remainingSeconds).toBeUndefined();
  });

  it("returns unlocked for non-banner-owned codes", () => {
    const { result } = renderHook(() => useSubmitLock(make(ApiErrorCodeType.ServerError, 30)));
    expect(result.current.locked).toBe(false);
  });

  it("locks for RateLimited with a positive Retry-After", () => {
    const { result } = renderHook(() => useSubmitLock(make(ApiErrorCodeType.RateLimited, 5)));
    expect(result.current.locked).toBe(true);
    expect(result.current.remainingSeconds).toBe(5);
  });

  it("does NOT lock for AbuseBlocked even with rateLimit metadata (AC-RL-008)", () => {
    // spec/21-app/14-rate-limiting.md: Retry-After is authoritative only for
    // RateLimited. AbuseBlocked renders the banner but never drives a lock.
    const { result } = renderHook(() => useSubmitLock(make(ApiErrorCodeType.AbuseBlocked, 3)));
    expect(result.current.locked).toBe(false);
    expect(result.current.remainingSeconds).toBeUndefined();
  });

  it("does NOT lock for MachineRebindCooldownActive (AC-RL-008)", () => {
    const { result } = renderHook(() =>
      useSubmitLock(make(ApiErrorCodeType.MachineRebindCooldownActive, 7)),
    );
    expect(result.current.locked).toBe(false);
    expect(result.current.remainingSeconds).toBeUndefined();
  });

  it("stays unlocked when Retry-After is missing (server bug, do not strand user)", () => {
    const { result } = renderHook(() => useSubmitLock(make(ApiErrorCodeType.RateLimited)));
    expect(result.current.locked).toBe(false);
    expect(result.current.remainingSeconds).toBeUndefined();
  });

  // Countdown tick behavior is owned by `useRetryAfterCountdown` (see
  // spec/21-app/14-rate-limiting.md); `useSubmitLock` is a thin adapter
  // that trusts that hook's `remainingSeconds` output. The unit contract
  // above is sufficient; end-to-end tick release is covered by the
  // Playwright rate-limit spec (Plan 11 step 40).
});
