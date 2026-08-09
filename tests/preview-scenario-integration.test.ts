/**
 * api-client + preview-scenario integration test (Plan 16 Step 55).
 *
 * Proves scenario overlays fire on the real dispatch path used at runtime:
 *   apiClient.call -> callPreview -> applyPreviewScenario -> dispatchPreview
 *
 * If a future edit hard-codes `Scenario: null` again (the exact bug fixed
 * at v0.558.0), this test fails immediately.
 */
import "fake-indexeddb/auto";
import { afterEach, beforeEach, describe, expect, it } from "vitest";
import { apiClient } from "@/lib/api-client";
import {
  clearPreviewHandlersForTest,
  registerPreviewHandler,
} from "@/lib/preview-transport";
import {
  resetPreviewScenarioForTest,
  setPreviewScenario,
  PREVIEW_SLOW_LATENCY_MS,
} from "@/lib/preview-scenario";
import { ApiErrorCodeType, LaraApiError } from "@/lib/lara-api-error";
import { registerAllPreviewHandlers } from "@/lib/preview-fixtures";
import { freezeRuntimeMode, resetRuntimeMode } from "@/lib/runtime-mode";

const OP = "admin.metrics.kpis" as const;

beforeEach(() => {
  resetRuntimeMode();
  freezeRuntimeMode({ Mode: "preview", ApiBaseUrl: null, PreviewSeed: "default" });
  clearPreviewHandlersForTest();
  registerAllPreviewHandlers();
});

afterEach(() => {
  resetPreviewScenarioForTest();
  resetRuntimeMode();
});

describe("api-client preview scenario integration", () => {
  it("null scenario returns the seeded response", async () => {
    setPreviewScenario(null);
    const res = await apiClient.call(OP, {});
    expect(res).toBeTruthy();
  });

  it("offline scenario surfaces LaraApiError(ServerError, 0) through apiClient", async () => {
    setPreviewScenario("offline");
    await expect(apiClient.call(OP, {})).rejects.toMatchObject({
      errorCode: ApiErrorCodeType.ServerError,
      httpStatus: 0,
    });
  });

  it("rate-limited scenario surfaces LaraApiError(RateLimited, 429) with retryAfterSeconds", async () => {
    setPreviewScenario("rate-limited");
    try {
      await apiClient.call(OP, {});
      throw new Error("expected throw");
    } catch (err) {
      expect(err).toBeInstanceOf(LaraApiError);
      const e = err as LaraApiError & { retryAfterSeconds?: number };
      expect(e.errorCode).toBe(ApiErrorCodeType.RateLimited);
      expect(e.httpStatus).toBe(429);
      expect(e.retryAfterSeconds).toBe(30);
    }
  });

  it("slow scenario injects >= 1.8s latency before resolving", async () => {
    setPreviewScenario("slow");
    const started = Date.now();
    await apiClient.call(OP, {});
    expect(Date.now() - started).toBeGreaterThanOrEqual(PREVIEW_SLOW_LATENCY_MS - 200);
  }, 10_000);
});
