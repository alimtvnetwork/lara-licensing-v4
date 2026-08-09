import { describe, expect, it } from "vitest";
import {
  ApiErrorCodeType,
  LaraApiError,
  formatLaraApiError,
  formatRetryAfter,
  getRetryAfterSeconds,
} from "@/lib/lara-api-error";

describe("formatLaraApiError", () => {
  // v0.302.0: formatLaraApiError now renders user-visible copy from
  // src/lib/error-copy.ts instead of the raw `<Code>: <message>` prefix.
  // The request id suffix is still appended verbatim.
  it("renders friendly copy and request id for known codes", () => {
    const err = new LaraApiError("bad", ApiErrorCodeType.ValidationFailed, 400, "req-123");
    expect(formatLaraApiError(err)).toBe("Some fields need attention. (Request req-123)");
  });

  it("interpolates RetryAfterSec into the RateLimited copy when the header is present", () => {
    const err = new LaraApiError("slow down", ApiErrorCodeType.RateLimited, 429, "req-9", {
      retryAfterSeconds: 12,
      bucket: "issue",
    });
    expect(formatLaraApiError(err)).toBe(
      "Too many requests. Wait 12 seconds and try again. (Request req-9)",
    );
  });

  it("falls back to the server message when RateLimited has no Retry-After header", () => {
    // Prevents leaking the raw `{RetryAfterSec}` placeholder to end users
    // when the backend omitted the header.
    const err = new LaraApiError("slow", ApiErrorCodeType.RateLimited, 429, "req-1", {});
    expect(formatLaraApiError(err)).toBe("slow (Request req-1)");
  });

  it("falls back to Error message for non-Lara errors", () => {
    expect(formatLaraApiError(new Error("boom"))).toBe("boom");
    expect(formatLaraApiError("nope")).toBe("Unknown error");
  });
});


describe("getRetryAfterSeconds", () => {
  it("returns undefined for non-RateLimited", () => {
    const err = new LaraApiError("x", ApiErrorCodeType.ValidationFailed, 400, "r", {
      retryAfterSeconds: 5,
    });
    expect(getRetryAfterSeconds(err)).toBeUndefined();
  });

  it("returns undefined when header absent", () => {
    const err = new LaraApiError("x", ApiErrorCodeType.RateLimited, 429, "r", {});
    expect(getRetryAfterSeconds(err)).toBeUndefined();
  });

  it("ceils fractional seconds and never fabricates for negative", () => {
    const err = new LaraApiError("x", ApiErrorCodeType.RateLimited, 429, "r", {
      retryAfterSeconds: 3.2,
    });
    expect(getRetryAfterSeconds(err)).toBe(4);
    const neg = new LaraApiError("x", ApiErrorCodeType.RateLimited, 429, "r", {
      retryAfterSeconds: -1,
    });
    expect(getRetryAfterSeconds(neg)).toBeUndefined();
  });

  // F3 — spec/21-app/14-rate-limiting.md AC-RL-008: AbuseBlocked (403) MUST NOT
  // carry Retry-After. Guard against a regression that widens the errorCode
  // check in getRetryAfterSeconds (src/lib/lara-api-error.ts line 114).
  it("returns undefined for AbuseBlocked even if rateLimit metadata is present (AC-RL-008)", () => {
    const err = new LaraApiError("blocked", ApiErrorCodeType.AbuseBlocked, 403, "req-abuse", {
      retryAfterSeconds: 30,
      bucket: "abuse",
      limit: 0,
    });
    expect(getRetryAfterSeconds(err)).toBeUndefined();
  });

  it("returns undefined for the two newly reserved Auth codes (F1 guard)", () => {
    const race = new LaraApiError("race", ApiErrorCodeType.AuthRefreshRaceLost, 409, "r", {
      retryAfterSeconds: 1,
    });
    const salt = new LaraApiError("salt", ApiErrorCodeType.AuthSaltRotationFailed, 500, "r", {
      retryAfterSeconds: 1,
    });
    expect(getRetryAfterSeconds(race)).toBeUndefined();
    expect(getRetryAfterSeconds(salt)).toBeUndefined();
  });
});

describe("formatRetryAfter", () => {
  it("returns null for undefined", () => {
    expect(formatRetryAfter(undefined)).toBeNull();
  });
  it("returns Retry now for zero", () => {
    expect(formatRetryAfter(0)).toBe("Retry now.");
  });
  it("uses seconds under 60", () => {
    expect(formatRetryAfter(45)).toBe("Retry in 45s.");
  });
  it("uses minutes at or above 60, ceiling", () => {
    expect(formatRetryAfter(60)).toBe("Retry in 1m.");
    expect(formatRetryAfter(61)).toBe("Retry in 2m.");
  });
});
