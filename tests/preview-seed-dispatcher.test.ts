/**
 * Plan 16 Step 39 test: seed dispatcher routes `version.json.PreviewSeed`
 * to the correct loader, no-ops outside preview mode, falls back to default
 * (with log) on unknown ids, and is idempotent across reloads.
 */
import "fake-indexeddb/auto";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { resetAll, list } from "../src/lib/preview-store";
import {
  freezeRuntimeMode,
  resetRuntimeMode,
  type RuntimeConfig,
} from "../src/lib/runtime-mode";
import { dispatchPreviewSeed } from "../src/lib/preview-seed-dispatcher";

function freeze(cfg: Partial<RuntimeConfig>): void {
  freezeRuntimeMode({
    Mode: "preview",
    ApiBaseUrl: null,
    PreviewSeed: "default",
    ...cfg,
  } as RuntimeConfig);
}

describe("preview-seed-dispatcher", () => {
  beforeEach(async () => {
    await resetAll();
    resetRuntimeMode();
  });

  it("no-ops outside preview mode", async () => {
    freeze({ Mode: "production", ApiBaseUrl: "https://api.example.com", PreviewSeed: "default" });
    const r = await dispatchPreviewSeed();
    expect(r).toEqual({ Dispatched: false, SeedId: null, Hydrated: false, UsedFallback: false });
    expect(await list("licenses")).toEqual([]);
  });

  it("dispatches default seed and hydrates on first run", async () => {
    freeze({ PreviewSeed: "default" });
    const r = await dispatchPreviewSeed();
    expect(r.Dispatched).toBe(true);
    expect(r.SeedId).toBe("default");
    expect(r.Hydrated).toBe(true);
    expect(r.UsedFallback).toBe(false);
    expect((await list("licenses")).length).toBeGreaterThan(0);
  });

  it("dispatches empty seed leaving content domains empty", async () => {
    freeze({ PreviewSeed: "empty" });
    const r = await dispatchPreviewSeed();
    expect(r.SeedId).toBe("empty");
    expect(await list("licenses")).toEqual([]);
    expect((await list("admin-users")).length).toBe(2);
  });

  it("dispatches error seed", async () => {
    freeze({ PreviewSeed: "error" });
    const r = await dispatchPreviewSeed();
    expect(r.SeedId).toBe("error");
    expect(await list("licenses")).toEqual([]);
  });

  it("falls back to default on unknown seed id and logs UNKNOWN_PREVIEW_SEED", async () => {
    const err = vi.spyOn(console, "error").mockImplementation(() => {});
    freeze({ PreviewSeed: "does-not-exist" });
    const r = await dispatchPreviewSeed();
    expect(r.SeedId).toBe("default");
    expect(r.UsedFallback).toBe(true);
    expect(err).toHaveBeenCalled();
    const call = err.mock.calls.find((c) => JSON.stringify(c).includes("UNKNOWN_PREVIEW_SEED"));
    expect(call).toBeDefined();
    err.mockRestore();
  });

  it("is idempotent across repeated dispatches", async () => {
    freeze({ PreviewSeed: "default" });
    const first = await dispatchPreviewSeed();
    const second = await dispatchPreviewSeed();
    expect(first.Hydrated).toBe(true);
    expect(second.Hydrated).toBe(false);
  });
});
