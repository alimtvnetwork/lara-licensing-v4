/**
 * Plan 16 Step 82: slow-network preview scenario verification.
 *
 * Proves `applyPreviewScenario("slow", ...)` blocks for exactly
 * PREVIEW_SLOW_LATENCY_MS before calling `run()`, aborts cleanly when
 * the signal is aborted mid-sleep, and remains a no-op when scenario
 * is null. This is the only degraded path where offline (instant
 * throw) and rate-limited (Step 80 header test) don't already cover
 * the latency contract.
 */
import { describe, it, expect, vi, afterEach } from "vitest";
import {
  applyPreviewScenario,
  PREVIEW_SLOW_LATENCY_MS,
} from "../src/lib/preview-scenario";

afterEach(() => {
  vi.useRealTimers();
  vi.restoreAllMocks();
});

describe("preview slow-network scenario (Plan 16 Step 82)", () => {
  it("delays run() by exactly PREVIEW_SLOW_LATENCY_MS", async () => {
    vi.useFakeTimers();
    const ctl = new AbortController();
    const run = vi.fn(async () => "ok");
    const p = applyPreviewScenario("slow", "req-slow-1", ctl.signal, run);

    await vi.advanceTimersByTimeAsync(PREVIEW_SLOW_LATENCY_MS - 1);
    expect(run).not.toHaveBeenCalled();

    await vi.advanceTimersByTimeAsync(1);
    await expect(p).resolves.toBe("ok");
    expect(run).toHaveBeenCalledTimes(1);
  });

  it("rejects with AbortError when signal aborts mid-sleep", async () => {
    vi.useFakeTimers();
    const ctl = new AbortController();
    const run = vi.fn(async () => "unreached");
    const p = applyPreviewScenario("slow", "req-slow-2", ctl.signal, run);

    await vi.advanceTimersByTimeAsync(100);
    ctl.abort();
    await expect(p).rejects.toMatchObject({ name: "AbortError" });
    expect(run).not.toHaveBeenCalled();
  });

  it("null scenario passes through with no delay", async () => {
    const run = vi.fn(async () => 42);
    const ctl = new AbortController();
    const started = Date.now();
    const out = await applyPreviewScenario(null, "req-null", ctl.signal, run);
    expect(out).toBe(42);
    expect(Date.now() - started).toBeLessThan(50);
    expect(run).toHaveBeenCalledTimes(1);
  });
});
