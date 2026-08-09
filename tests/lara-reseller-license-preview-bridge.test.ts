/**
 * Plan 17 Step 7: `resellerLicenseListQueryOptions` preview bridge.
 *
 * Proves the reseller license list route resolves numeric `resellerId=1`
 * back to `RSLLR1` via the id-map and only returns rows scoped to that
 * reseller (LIC00001 in the default seed).
 */

import "fake-indexeddb/auto";
import { beforeEach, describe, expect, it } from "vitest";

import { loadDefaultSeed } from "@/lib/preview-seeds/default";
import {
  resellerLicenseDetailQueryOptions,
  resellerLicenseListQueryOptions,
  resellerLicenseSchema,
} from "@/lib/lara-reseller-license";
import { freezeRuntimeMode } from "@/lib/runtime-mode";
import { registerAllPreviewHandlers } from "@/lib/preview-fixtures";
import { resetIdMap } from "@/lib/preview-id-map";
import { resetAll as resetPreviewStore } from "@/lib/preview-store";

async function resetAll(): Promise<void> {
  await resetPreviewStore();
  await resetIdMap("admin-users");
  await resetIdMap("licenses");
  await resetIdMap("resellers");
}

describe("resellerLicenseListQueryOptions preview bridge", () => {
  beforeEach(async () => {
    await resetAll();
    registerAllPreviewHandlers();
    freezeRuntimeMode({ Mode: "preview", ApiBaseUrl: null, PreviewSeed: "default" });
    await loadDefaultSeed();
  });

  it("returns only rows owned by resellerId=1 (RSLLR1) with legacy schema shape", async () => {
    const rows = await resellerLicenseListQueryOptions(1).queryFn!({} as never);
    // RSLLR1 has LIC00001 and LIC00004 in the default seed.
    expect(rows).toHaveLength(2);

    const [row] = rows;
    expect(row.LicenseId).toBe(1);
    expect(row.ResellerId).toBe(1);
    expect(row.LicenseKey).toBe("LARA-AAAA-0001");
    expect(row.Status).toBe("Active");
    expect(row.PrefixValue).toBe("LARA-AAAA");
    expect(row.Version).toBe(3);
    expect(() => resellerLicenseSchema.parse(row)).not.toThrow();
  });

  it("unknown reseller id returns empty list (bridge warns, no crash)", async () => {
    const rows = await resellerLicenseListQueryOptions(999).queryFn!({} as never);
    expect(rows).toEqual([]);
  });

  it("detail bridge filters list result by LicenseKey", async () => {
    const rows = await resellerLicenseDetailQueryOptions(1, "LARA-AAAA-0001").queryFn!({} as never);
    // LIC00001 has LicenseKey "LARA-AAAA-0001".
    // In the default seed, Reseller 1 (RSLLR1) owns LIC00001 and LIC00004.
    // fetchPreviewResellerLicenseDetail calls fetchPreviewResellerLicenseList(1) which returns 2 rows,
    // then filters by LicenseKey "LARA-AAAA-0001", returning exactly 1 row.
    expect(rows).toHaveLength(1);
    expect(rows[0].LicenseKey).toBe("LARA-AAAA-0001");
    const missing = await resellerLicenseDetailQueryOptions(1, "LARA-XXXX-9999").queryFn!({} as never);
    expect(missing).toEqual([]);
  });
});
