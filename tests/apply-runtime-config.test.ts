/**
 * apply-runtime-config.test.ts (Plan 17 Step 34).
 *
 * Verifies the fast-path branching in `applyRuntimeConfigChange`:
 *   - no-op when configs are identical
 *   - seed-only diff: no reload, seed re-dispatched, queries invalidated
 *   - Mode change: reload triggered
 *   - write-failed: returns without side effects
 */

import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import type { QueryClient } from "@tanstack/react-query";

import { applyRuntimeConfigChange } from "../src/lib/apply-runtime-config";
import { freezeRuntimeMode, resetRuntimeMode, type RuntimeConfig } from "../src/lib/runtime-mode";

vi.mock("../src/lib/version-json-loader", () => ({
  writeRuntimeOverride: vi.fn(() => true),
}));
vi.mock("../src/lib/preview-store", () => ({
  resetAll: vi.fn(async () => undefined),
}));
vi.mock("../src/lib/preview-seed-dispatcher", () => ({
  dispatchPreviewSeed: vi.fn(async () => ({ Dispatched: true, SeedId: "empty", Hydrated: true, UsedFallback: false })),
}));

const PREVIEW_DEFAULT: RuntimeConfig = { Mode: "preview", ApiBaseUrl: null, PreviewSeed: "default" };

function installFakeQueryClient(): { invalidateQueries: ReturnType<typeof vi.fn> } {
  const invalidateQueries = vi.fn(async () => undefined);
  (globalThis as unknown as { __LARA_QUERY_CLIENT__: QueryClient }).__LARA_QUERY_CLIENT__ = {
    invalidateQueries,
  } as unknown as QueryClient;
  return { invalidateQueries };
}

describe("applyRuntimeConfigChange", () => {
  const originalLocation = window.location;

  beforeEach(() => {
    resetRuntimeMode();
    freezeRuntimeMode(PREVIEW_DEFAULT);
    Object.defineProperty(window, "location", {
      configurable: true,
      value: { ...originalLocation, reload: vi.fn() },
    });
  });

  afterEach(() => {
    Object.defineProperty(window, "location", { configurable: true, value: originalLocation });
    delete (globalThis as unknown as { __LARA_QUERY_CLIENT__?: QueryClient }).__LARA_QUERY_CLIENT__;
  });

  it("returns no-op when nothing changed", async () => {
    const r = await applyRuntimeConfigChange(PREVIEW_DEFAULT);
    expect(r).toEqual({ Applied: false, FastPath: false, Reason: "no-op" });
    expect(window.location.reload).not.toHaveBeenCalled();
  });

  it("takes fast-path on seed-only change and invalidates queries", async () => {
    const { invalidateQueries } = installFakeQueryClient();
    const r = await applyRuntimeConfigChange({ ...PREVIEW_DEFAULT, PreviewSeed: "empty" });
    expect(r).toEqual({ Applied: true, FastPath: true, Reason: "seed-only" });
    expect(invalidateQueries).toHaveBeenCalledTimes(1);
    expect(window.location.reload).not.toHaveBeenCalled();
  });

  it("reloads on Mode change", async () => {
    const r = await applyRuntimeConfigChange({ ...PREVIEW_DEFAULT, Mode: "production", ApiBaseUrl: "https://x.test" });
    expect(r.FastPath).toBe(false);
    expect(r.Reason).toBe("mode-change");
    expect(window.location.reload).toHaveBeenCalledTimes(1);
  });

  it("reloads on ApiBaseUrl change while staying in production", async () => {
    freezeRuntimeMode({ Mode: "production", ApiBaseUrl: "https://a.test", PreviewSeed: "default" });
    const r = await applyRuntimeConfigChange({ Mode: "production", ApiBaseUrl: "https://b.test", PreviewSeed: "default" });
    expect(r.Reason).toBe("url-change");
    expect(window.location.reload).toHaveBeenCalledTimes(1);
  });

  it("returns write-failed when storage write is rejected", async () => {
    const { writeRuntimeOverride } = await import("../src/lib/version-json-loader");
    (writeRuntimeOverride as ReturnType<typeof vi.fn>).mockReturnValueOnce(false);
    const r = await applyRuntimeConfigChange({ ...PREVIEW_DEFAULT, PreviewSeed: "empty" });
    expect(r).toEqual({ Applied: false, FastPath: false, Reason: "write-failed" });
    expect(window.location.reload).not.toHaveBeenCalled();
  });
});
