/**
 * Plan 17 Step 5: seed-time id-map priming (deterministic legacy ids).
 *
 * Locks the numeric ids emitted for `admin-users`, `licenses`, and
 * `resellers` after `loadDefaultSeed()` / `loadEmptySeed()` runs, so
 * legacy bridges in Steps 6+ (e.g. `licenseQueryOptions(1)`) resolve
 * to the expected ULID without per-render fabrication.
 */

import "fake-indexeddb/auto";
import { beforeEach, describe, expect, it } from "vitest";

import { loadDefaultSeed } from "@/lib/preview-seeds/default";
import { loadEmptySeed } from "@/lib/preview-seeds/empty";
import { numericFor, ulidFor, resetIdMap } from "@/lib/preview-id-map";
import { resetAll as resetPreviewStore } from "@/lib/preview-store";

async function resetAll(): Promise<void> {
  await resetPreviewStore();
  await resetIdMap("admin-users");
  await resetIdMap("licenses");
  await resetIdMap("resellers");
}

describe("preview seeds prime the legacy id-map", () => {
  beforeEach(async () => {
    await resetAll();
  });

  it("default seed assigns admin-users 1..2, licenses 1..3, resellers 1", async () => {
    await loadDefaultSeed();
    expect(await numericFor("admin-users", "01H0000000000000000ADMIN1")).toBe(1);
    expect(await numericFor("admin-users", "01H0000000000000000RSLL01")).toBe(2);
    expect(await numericFor("licenses", "01H00000000000000LIC00001")).toBe(1);
    expect(await numericFor("licenses", "01H00000000000000LIC00002")).toBe(2);
    expect(await numericFor("licenses", "01H00000000000000LIC00003")).toBe(3);
    expect(await numericFor("resellers", "01H000000000000000RSLLR1")).toBe(1);
    expect(await ulidFor("licenses", 2)).toBe("01H00000000000000LIC00002");
  });

  it("empty seed primes only admin-users; licenses/resellers stay empty", async () => {
    await loadEmptySeed();
    expect(await numericFor("admin-users", "01H0000000000000000ADMIN1")).toBe(1);
    expect(await numericFor("admin-users", "01H0000000000000000RSLL01")).toBe(2);
    expect(await numericFor("licenses", "01H00000000000000LIC00001")).toBeUndefined();
    expect(await numericFor("resellers", "01H000000000000000RSLLR1")).toBeUndefined();
  });

  it("re-running default seed after reset yields identical numeric ids", async () => {
    await loadDefaultSeed();
    await resetAll();
    await loadDefaultSeed();
    expect(await numericFor("licenses", "01H00000000000000LIC00001")).toBe(1);
    expect(await numericFor("licenses", "01H00000000000000LIC00003")).toBe(3);
  });
});
