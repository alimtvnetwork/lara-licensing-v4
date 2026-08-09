import { describe, expect, it } from "vitest";
import {
  ApiErrorCodeType,
  LaraApiError,
  formatLaraApiError,
  getRetryAfterSeconds,
} from "@/lib/lara-api-error";

/**
 * Proves AC-EC-TEST-001 and AC-EC-TEST-002 from
 * spec/21-app/27-error-code-test-matrix.md: IdempotencyConflict and
 * IdempotencyKeyRequired render with errorCode, message, and RequestId, and
 * never trigger a Retry-After hint (retry class NoRetry per
 * spec/21-app/25-retry-decision-matrix.md).
 */
describe("Idempotency error codes", () => {
  it("registers both taxonomy codes on the enum", () => {
    expect(ApiErrorCodeType.IdempotencyConflict).toBe("IdempotencyConflict");
    expect(ApiErrorCodeType.IdempotencyKeyRequired).toBe("IdempotencyKeyRequired");
  });

  it("formats IdempotencyConflict with RequestId and no retry hint", () => {
    const err = new LaraApiError(
      "Idempotency-Key was previously used for a different request.",
      ApiErrorCodeType.IdempotencyConflict,
      409,
      "req-conflict-1",
    );
    expect(formatLaraApiError(err)).toBe(
      "This action was already processed with a different payload. (Request req-conflict-1)",
    );

    expect(getRetryAfterSeconds(err)).toBeUndefined();
  });

  it("formats IdempotencyKeyRequired with RequestId and no retry hint", () => {
    const err = new LaraApiError(
      "Idempotency-Key header is required for this endpoint.",
      ApiErrorCodeType.IdempotencyKeyRequired,
      400,
      "req-missing-key",
    );
    expect(formatLaraApiError(err)).toBe(
      "This action needs an idempotency key. Refresh and try again. (Request req-missing-key)",
    );

    expect(getRetryAfterSeconds(err)).toBeUndefined();
  });

  it("ignores retryAfterSeconds metadata on idempotency codes (NoRetry class)", () => {
    const conflict = new LaraApiError("x", ApiErrorCodeType.IdempotencyConflict, 409, "r", {
      retryAfterSeconds: 30,
    });
    const missing = new LaraApiError("x", ApiErrorCodeType.IdempotencyKeyRequired, 400, "r", {
      retryAfterSeconds: 30,
    });
    expect(getRetryAfterSeconds(conflict)).toBeUndefined();
    expect(getRetryAfterSeconds(missing)).toBeUndefined();
  });
});
