import { describe, expect, it } from "vitest";

import { ApiErrorCodeType, LaraApiError } from "@/lib/lara-api-error";
import { classifyRetryPolicy, isRetryable, RetryPolicyType } from "@/lib/lara-retry";

function make(code: ApiErrorCodeType, status = 500): LaraApiError {
  return new LaraApiError("test", code, status);
}

describe("lara-retry", () => {
  it("classifies RateLimited as RetryAfter", () => {
    expect(classifyRetryPolicy(make(ApiErrorCodeType.RateLimited, 429))).toBe(
      RetryPolicyType.RetryAfter,
    );
  });

  it("classifies AuthTokenExpired as RefreshThenRetry", () => {
    expect(classifyRetryPolicy(make(ApiErrorCodeType.AuthTokenExpired, 401))).toBe(
      RetryPolicyType.RefreshThenRetry,
    );
  });

  it("classifies AuthRefreshReused as FatalClear (do NOT retry)", () => {
    const err = make(ApiErrorCodeType.AuthRefreshReused, 401);
    expect(classifyRetryPolicy(err)).toBe(RetryPolicyType.FatalClear);
    expect(isRetryable(err)).toBe(false);
  });

  it("classifies ServerError as ExpBackoff", () => {
    expect(classifyRetryPolicy(make(ApiErrorCodeType.ServerError, 500))).toBe(
      RetryPolicyType.ExpBackoff,
    );
  });

  it("classifies network failure (httpStatus=0, ServerError) as ExpBackoff", () => {
    expect(classifyRetryPolicy(make(ApiErrorCodeType.ServerError, 0))).toBe(
      RetryPolicyType.ExpBackoff,
    );
  });

  it("classifies validation as NoRetry", () => {
    const err = make(ApiErrorCodeType.ValidationFailed, 422);
    expect(classifyRetryPolicy(err)).toBe(RetryPolicyType.NoRetry);
    expect(isRetryable(err)).toBe(false);
  });

  it("does NOT auto-retry AuthRefreshRaceLost at the caller level", () => {
    // Handled internally by performRefresh; must not be retryable at caller.
    const err = make(ApiErrorCodeType.AuthRefreshRaceLost, 409);
    expect(classifyRetryPolicy(err)).toBe(RetryPolicyType.NoRetry);
    expect(isRetryable(err)).toBe(false);
  });

  it("isRetryable returns true for RateLimited, RefreshThenRetry, ExpBackoff", () => {
    expect(isRetryable(make(ApiErrorCodeType.RateLimited, 429))).toBe(true);
    expect(isRetryable(make(ApiErrorCodeType.AuthTokenExpired, 401))).toBe(true);
    expect(isRetryable(make(ApiErrorCodeType.ServerError, 500))).toBe(true);
    expect(isRetryable(make(ApiErrorCodeType.ServiceUnavailable, 503))).toBe(true);
  });

  it("isRetryable returns false for non-LaraApiError inputs", () => {
    expect(isRetryable(new Error("nope"))).toBe(false);
    expect(isRetryable(undefined)).toBe(false);
    expect(isRetryable("boom")).toBe(false);
  });

  it("falls through to httpStatus for uncatalogued codes (5xx -> ExpBackoff)", () => {
    // FeatureCatalogUnseeded is not in POLICY_BY_CODE; httpStatus=500 -> ExpBackoff.
    expect(classifyRetryPolicy(make(ApiErrorCodeType.FeatureCatalogUnseeded, 500))).toBe(
      RetryPolicyType.ExpBackoff,
    );
    // Same code with 400 status falls through to NoRetry.
    expect(classifyRetryPolicy(make(ApiErrorCodeType.FeatureCatalogUnseeded, 400))).toBe(
      RetryPolicyType.NoRetry,
    );
  });
});
