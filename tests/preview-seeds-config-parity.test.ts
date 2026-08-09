/**
 * Plan 17 Step 26: lock the preview config-tier surface to the canonical
 * FeatureKey registry (spec/21-app/45-license-features.md §2, mirrored in
 * `src/lib/lara-features.ts:48`) and the shipped feature catalog.
 *
 * Any drift here (new/removed FeatureKey, ValueType change, tier count
 * change) must be reflected in `src/lib/preview-seeds/config.ts` in the
 * same commit or this test fails, preventing silent config-parity rot
 * between spec, runtime registry, and preview seed.
 */
import "fake-indexeddb/auto";
import { beforeEach, describe, expect, it } from "vitest";
import { resetAll, list } from "../src/lib/preview-store";
import { hydrateConfig, CONFIG_FEATURE_CODES, CONFIG_TIER_FEATURE_COUNT } from "../src/lib/preview-seeds/config";
import {
  featureKeyValueTypeRegistry,
  validateFeatureValue,
  type FeatureKeyValue,
} from "../src/lib/lara-features";
import type { FeatureDefinition } from "@/generated/api/schema";

const REGISTRY_KEYS = Object.keys(featureKeyValueTypeRegistry) as FeatureKeyValue[];
const EXPECTED_TIER_IDS = [1, 2, 3] as const;

describe("preview-seeds:config parity", () => {
  beforeEach(async () => {
    await resetAll();
  });

  it("CONFIG_TIER_FEATURE_COUNT == tiers x registry keys", () => {
    expect(CONFIG_TIER_FEATURE_COUNT).toBe(EXPECTED_TIER_IDS.length * REGISTRY_KEYS.length);
  });

  it("hydrates one tier-features row per (tier, FeatureKey) pair with a valid Value", async () => {
    await hydrateConfig();
    const entries = await list<{ LicenseTierId: number; FeatureKey: FeatureKeyValue; Value: unknown }>("tier-features");
    expect(entries.length).toBe(CONFIG_TIER_FEATURE_COUNT);
    const rows = entries.map(([, v]) => v);
    for (const tier of EXPECTED_TIER_IDS) {
      for (const key of REGISTRY_KEYS) {
        const row = rows.find((r) => r.LicenseTierId === tier && r.FeatureKey === key);
        expect(row, `missing tier=${tier} key=${key}`).toBeDefined();
        // Throws if ValueType mismatches the registry: locks spec §3 shape.
        expect(() => validateFeatureValue(key, row!.Value)).not.toThrow();
      }
    }
  });

  it("hydrates the feature catalog exactly once per declared Code", async () => {
    await hydrateConfig();
    const entries = await list<FeatureDefinition>("features");
    expect(entries.length).toBe(CONFIG_FEATURE_CODES.length);
    const rows = entries.map(([, v]) => v);
    const codes = rows.map((r) => r.Code).sort();
    expect(codes).toEqual([...CONFIG_FEATURE_CODES].sort());
    for (const r of rows) {
      expect(typeof r.DisplayName).toBe("string");
      expect(typeof r.IsBillable).toBe("boolean");
      expect(["core", "addon"]).toContain(r.Category);
    }
  });

  it("is idempotent (repeated hydrateConfig calls converge on the same row count)", async () => {
    await hydrateConfig();
    await hydrateConfig();
    const features = await list("features");
    const tierFeatures = await list("tier-features");
    expect(features.length).toBe(CONFIG_FEATURE_CODES.length);
    expect(tierFeatures.length).toBe(CONFIG_TIER_FEATURE_COUNT);
  });
});
