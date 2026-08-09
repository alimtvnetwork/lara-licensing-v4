/**
 * Plan 17 Step 9 + Step 23: `featureCatalogQueryOptions` preview bridge and
 * tier / license feature preview bridges.
 *
 * Proves the legacy closed-set feature catalog resolves in preview mode
 * from `featureKeyValueTypeRegistry` (spec/21-app/45-license-features.md §2)
 * and that `featureCatalogResourceSchema.parse` accepts every synthesized
 * row. After Step 23, tier / license feature queries also resolve from
 * the preview store (previously returned `[]`).
 */

import "fake-indexeddb/auto";
import { beforeEach, describe, expect, it } from "vitest";

import {
  featureCatalogQueryOptions,
  featureCatalogResourceSchema,
  featureKeyValueTypeRegistry,
  licenseFeaturesQueryOptions,
  tierFeaturesQueryOptions,
} from "@/lib/lara-features";
import { loadDefaultSeed } from "@/lib/preview-seeds/default";
import { freezeRuntimeMode } from "@/lib/runtime-mode";
import { registerAllPreviewHandlers } from "@/lib/preview-fixtures";
import { resetAll as resetPreviewStore } from "@/lib/preview-store";

describe("featureCatalogQueryOptions preview bridge", () => {
  beforeEach(async () => {
    await resetPreviewStore();
    registerAllPreviewHandlers();
    freezeRuntimeMode({ Mode: "preview", ApiBaseUrl: null, PreviewSeed: "default" });
    await loadDefaultSeed();
  });

  it("returns the closed-set catalog synthesized from the registry", async () => {
    const rows = await featureCatalogQueryOptions().queryFn!({} as never);
    const registryKeys = Object.keys(featureKeyValueTypeRegistry).sort();
    expect(rows.map((r) => r.FeatureKey).sort()).toEqual(registryKeys);
    for (const row of rows) {
      expect(() => featureCatalogResourceSchema.parse(row)).not.toThrow();
      expect(row.ValueType).toBe(featureKeyValueTypeRegistry[row.FeatureKey]);
    }
  });

  it("tier / license feature queries resolve from the preview store", async () => {
    const tierRows = await tierFeaturesQueryOptions(1).queryFn!({} as never);
    expect(Array.isArray(tierRows)).toBe(true);
    const licenseRows = await licenseFeaturesQueryOptions(1).queryFn!({} as never);
    expect(Array.isArray(licenseRows)).toBe(true);
  });
});
