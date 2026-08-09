/**
 * Preview scenario overlay tests (Plan 16 Step 54).
 */
import { afterEach, describe, expect, it } from "vitest";
import {
  applyPreviewScenario,
  getPreviewScenario,
  setPreviewScenario,
  resetPreviewScenarioForTest,
  PREVIEW_SLOW_LATENCY_MS,
  PREVIEW_RATE_LIMIT_RETRY_AFTER_S,
} from "@/lib/preview-scenario";
import { ApiErrorCodeType, LaraApiError } from "@/lib/lara-api-error";

const RID = "req-test-1";
const signal = new AbortController().signal;

afterEach(() => resetPreviewScenarioForTest());

describe("preview-scenario", () => {
  it("defaults to null and passes through", async () => {
    expect(getPreviewScenario()).toBeNull();
    const out = await applyPreviewScenario(null, RID, signal, async () => 42);
    expect(out).toBe(42);
  });

  it("rejects invalid scenario", () => {
    expect(() => setPreviewScenario("nope" as never)).toThrow(/invalid scenario/);
  });

  it("offline throws LaraApiError with ServerError code and 0 status", async () => {
    await expect(applyPreviewScenario("offline", RID, signal, async () => 1)).rejects.toMatchObject({
      errorCode: ApiErrorCodeType.ServerError,
      httpStatus: 0,
      requestId: RID,
    });
  });

  it("rate-limited throws LaraApiError with RateLimited and Retry-After", async () => {
    try {
      await applyPreviewScenario("rate-limited", RID, signal, async () => 1);
      throw new Error("expected throw");
    } catch (err) {
      expect(err).toBeInstanceOf(LaraApiError);
      const e = err as LaraApiError & { retryAfterSeconds?: number };
      expect(e.errorCode).toBe(ApiErrorCodeType.RateLimited);
      expect(e.httpStatus).toBe(429);
      expect(e.retryAfterSeconds).toBe(PREVIEW_RATE_LIMIT_RETRY_AFTER_S);
    }
  });

  it("slow injects latency then runs handler", async () => {
    const start = Date.now();
    const out = await applyPreviewScenario("slow", RID, signal, async () => "ok");
    const elapsed = Date.now() - start;
    expect(out).toBe("ok");
    expect(elapsed).toBeGreaterThanOrEqual(PREVIEW_SLOW_LATENCY_MS - 50);
  }, 10_000);

  it("notifies subscribers on change", async () => {
    const events: unknown[] = [];
    const { subscribePreviewScenario } = await import("@/lib/preview-scenario");
    const unsub = subscribePreviewScenario((s) => events.push(s));
    setPreviewScenario("offline");
    setPreviewScenario("offline");
    setPreviewScenario(null);
    unsub();
    expect(events).toEqual(["offline", null]);
  });
});
