import { beforeEach, afterEach, describe, expect, it } from "vitest";
import { render, screen, cleanup, act } from "@testing-library/react";

import { ApiErrorCodeType, LaraApiError } from "@/lib/lara-api-error";
import {
  __resetErrorStoreForTests,
  pushLaraApiError,
} from "@/lib/error-store";
import { GlobalRateLimitBanner } from "@/components/global/GlobalRateLimitBanner";

beforeEach(() => {
  __resetErrorStoreForTests();
});

afterEach(() => {
  cleanup();
  __resetErrorStoreForTests();
});

function push(code: ApiErrorCodeType, httpStatus: number, details?: ReadonlyArray<unknown>) {
  act(() => {
    pushLaraApiError(
      new LaraApiError(`${code} failed`, code, httpStatus, "req-x", undefined, undefined, details),
    );
  });
}

describe("GlobalRateLimitBanner (Plan 11 step 31)", () => {
  it("renders nothing when the store is empty", () => {
    render(<GlobalRateLimitBanner />);
    expect(screen.queryByText(/Rate limit hit/i)).toBeNull();
  });

  it("renders when the newest entry is RateLimited (with bucket)", () => {
    render(<GlobalRateLimitBanner />);
    push(ApiErrorCodeType.RateLimited, 429, [{ retryAfterSeconds: 5, bucket: "login" }]);
    expect(screen.queryByText(/Rate limit hit/i)).not.toBeNull();
    expect(screen.queryByText(/login/i)).not.toBeNull();
  });

  it("does not render for non-banner-owned codes (ServerError)", () => {
    render(<GlobalRateLimitBanner />);
    push(ApiErrorCodeType.ServerError, 500);
    expect(screen.queryByText(/Rate limit hit/i)).toBeNull();
  });

  it("renders for AbuseBlocked", () => {
    render(<GlobalRateLimitBanner />);
    push(ApiErrorCodeType.AbuseBlocked, 429);
    expect(screen.queryByText(/Rate limit hit/i)).not.toBeNull();
  });

  it("renders for MachineRebindCooldownActive", () => {
    render(<GlobalRateLimitBanner />);
    push(ApiErrorCodeType.MachineRebindCooldownActive, 409);
    expect(screen.queryByText(/Rate limit hit/i)).not.toBeNull();
  });
});
