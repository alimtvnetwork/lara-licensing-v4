/**
 * Plan 16 Step 80: header-triggered rate-limit preview scenario.
 *
 * Root cause guarded: without a per-call `x-preview-scenario` header
 * path, QA/Playwright could not exercise the `Retry-After` submit-lock
 * flow on a specific mutation (e.g. `admin.licenses.create`) without
 * forcing the whole app into `rate-limited` via the admin toggle,
 * which broke unrelated screens and made the countdown untestable.
 *
 * This test locks the contract on `applyPreviewScenario` + api-client:
 *   - Setting `x-preview-scenario: rate-limited` on a single call
 *     produces a `LaraApiError` with `errorCode = RateLimited`,
 *     `httpStatus = 429`, and `retryAfterSeconds = 3` (short window,
 *     `PREVIEW_HEADER_RATE_LIMIT_RETRY_AFTER_S`).
 *   - Absent the header, calls fall through to the normal handler
 *     (no leaked scenario state between requests).
 *   - Unknown header values are logged and ignored, not thrown, so
 *     a typo does not silently rate-limit the app.
 */
import "fake-indexeddb/auto";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { apiClient } from "../src/lib/api-client";
import { ApiErrorCodeType, LaraApiError } from "../src/lib/lara-api-error";
import { resetPreviewScenarioForTest, PREVIEW_HEADER_RATE_LIMIT_RETRY_AFTER_S } from "../src/lib/preview-scenario";
import { resetRuntimeMode } from "../src/lib/runtime-mode";
import { resetAll } from "../src/lib/preview-store";
import { loadDefaultSeed } from "../src/lib/preview-seeds/default";
import { clearPreviewHandlersForTest } from "../src/lib/preview-transport";
import { registerAllPreviewHandlers } from "../src/lib/preview-fixtures";

describe("preview rate-limit scenario header (Plan 16 Step 80)", () => {
  beforeEach(async () => {
    resetPreviewScenarioForTest();
    clearPreviewHandlersForTest();
    registerAllPreviewHandlers();
    await resetAll();
    await loadDefaultSeed();
    resetRuntimeMode();
  });

  const createParams = {
    CustomerName: "Test Customer",
    CustomerEmail: "test@example.com",
    ResellerId: null,
    Features: [],
    MaxActivations: 3,
    ExpiresAt: "2027-01-01T00:00:00Z",
  } as never;

  it("header rate-limited triggers RateLimited with Retry-After 3", async () => {
    let caught: unknown;
    try {
      await apiClient.call("admin.licenses.create", createParams, {
        headers: { "x-preview-scenario": "rate-limited" },
      });
    } catch (e) {
      caught = e;
    }
    if (!(caught instanceof LaraApiError)) {
      console.error("preview-rate-limit-header:not-lara", { ctor: (caught as { constructor?: { name?: string } })?.constructor?.name });
    }
    expect(caught).toBeInstanceOf(LaraApiError);
    const err = caught as LaraApiError;
    expect(err.errorCode).toBe(ApiErrorCodeType.RateLimited);
    expect(err.httpStatus).toBe(429);
    expect((err as { retryAfterSeconds?: number }).retryAfterSeconds).toBe(PREVIEW_HEADER_RATE_LIMIT_RETRY_AFTER_S);
    expect(PREVIEW_HEADER_RATE_LIMIT_RETRY_AFTER_S).toBe(3);
  });

  it("no header falls through to the real handler (no leaked scenario)", async () => {
    const res = await apiClient.call("admin.licenses.create", createParams, {});
    expect(res).toMatchObject({ Id: expect.any(String), Serial: expect.any(String) });
  });

  it("unknown header value is logged and ignored (no throw)", async () => {
    const warn = vi.spyOn(console, "warn").mockImplementation(() => {});
    const res = await apiClient.call("admin.licenses.create", createParams, {
      headers: { "x-preview-scenario": "bogus" },
    });
    expect(res).toMatchObject({ Id: expect.any(String) });
    expect(warn).toHaveBeenCalledWith(
      "preview-scenario: ignoring unknown x-preview-scenario header value",
      { value: "bogus" },
    );
    warn.mockRestore();
  });
});
