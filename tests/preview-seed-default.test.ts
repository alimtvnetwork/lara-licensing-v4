/**
 * Plan 16 Step 36: default preview seed tests.
 */
import "fake-indexeddb/auto";
import { beforeEach, describe, expect, it } from "vitest";
import { list, listKeys, read, resetAll } from "@/lib/preview-store";
import { loadDefaultSeed } from "@/lib/preview-seeds/default";
import type { License, MeUser } from "@/generated/api/schema";

describe("default preview seed (Plan 16 Step 36)", () => {
  beforeEach(async () => {
    await resetAll();
  });

  it("hydrates every populated domain on first run", async () => {
    const r = await loadDefaultSeed();
    expect(r.Hydrated).toBe(true);
    expect((await listKeys("admin-users")).length).toBeGreaterThanOrEqual(2);
    expect((await listKeys("features")).length).toBeGreaterThanOrEqual(4);
    expect((await listKeys("licenses")).length).toBeGreaterThanOrEqual(3);
    expect((await listKeys("serials")).length).toBeGreaterThanOrEqual(3);
    expect((await listKeys("updates")).length).toBeGreaterThanOrEqual(6);
    expect((await listKeys("quotas")).length).toBeGreaterThanOrEqual(3);
    expect((await listKeys("audit")).length).toBeGreaterThanOrEqual(30);
    expect((await listKeys("metrics"))).toContain("kpis");


    expect(await read<MeUser>("me", "current")).toBeDefined();
    expect(await read("auth", "credentials")).toBeDefined();
  });

  it("is idempotent across reload (second load reports Hydrated=false)", async () => {
    await loadDefaultSeed();
    const before = await list<License>("licenses");
    const r2 = await loadDefaultSeed();
    const after = await list<License>("licenses");
    expect(r2.Hydrated).toBe(false);
    expect(after).toEqual(before);
  });

  it("emits typed records that match schema shape (License spot-check)", async () => {
    await loadDefaultSeed();
    const l = await read<License>("licenses", "01H00000000000000LIC00001");
    expect(l).toBeDefined();
    expect(l!.Serial).toBe("LARA-AAAA-0001");
    expect(l!.Status).toBe("active");
    expect(l!.Features).toContain("core.reports");
    expect(l!.Version).toBe(3);
  });
});
