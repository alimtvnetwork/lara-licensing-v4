/**
 * Plan 17 Step 8: `resellerQuotasQueryOptions` preview bridge.
 *
 * Proves the legacy `resellerId=1` route resolves through the id-map
 * primed by `loadDefaultSeed()` into `admin.quotas.list`, and that the
 * adapted rows match the legacy `resellerQuotaSchema` (positive-int FK
 * ids, LicensesGranted/Consumed/Remaining accounting).
 */

import "fake-indexeddb/auto";
import { beforeEach, describe, expect, it } from "vitest";

import { resellerQuotasQueryOptions, resellerQuotaSchema } from "@/lib/lara-quota";
import { loadDefaultSeed } from "@/lib/preview-seeds/default";
import { freezeRuntimeMode } from "@/lib/runtime-mode";
import { registerAllPreviewHandlers } from "@/lib/preview-fixtures";
import { resetIdMap } from "@/lib/preview-id-map";
import { resetAll as resetPreviewStore } from "@/lib/preview-store";

async function resetAll(): Promise<void> {
  await resetPreviewStore();
  await resetIdMap("licenses");
  await resetIdMap("resellers");
}

describe("resellerQuotasQueryOptions preview bridge", () => {
  beforeEach(async () => {
    await resetAll();
    registerAllPreviewHandlers();
    freezeRuntimeMode({ Mode: "preview", ApiBaseUrl: null, PreviewSeed: "default" });
    await loadDefaultSeed();
  });

  it("resolves resellerId=1 via id-map into the seeded 3 quota rows", async () => {
    const rows = await resellerQuotasQueryOptions(1).queryFn!({} as never);
    expect(rows).toHaveLength(3);
    for (const row of rows) {
      expect(() => resellerQuotaSchema.parse(row)).not.toThrow();
      expect(row.ResellerId).toBe(1);
      expect(row.LicensesRemaining).toBe(Math.max(0, row.LicensesGranted - row.LicensesConsumed));
    }
  });

  it("unmapped reseller id returns empty list (no throw)", async () => {
    const rows = await resellerQuotasQueryOptions(999).queryFn!({} as never);
    expect(rows).toEqual([]);
  });
});
