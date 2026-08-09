// Plan 06 step 81 regression guard.
//
// Locks the Inertia console's feature-map precedence to spec
// 21-app/45-license-features.md §4 and keeps three implementations in sync:
// FeatureService::layers()/resolve() (PHP), src/lib/lara-features.ts
// resolveFeatureMap (SPA), and backend/resources/js/lib/featureMap.ts.

import { readFileSync } from "node:fs";
import { describe, expect, it } from "vitest";

import {
  EMPTY_FEATURE_LAYERS,
  FEATURE_NOT_LICENSED,
  FeatureOriginType,
  formatFeatureValue,
  isFeatureEnabled,
  resolveFeatureEntries,
  resolveFeatureMap,
  type FeatureLayers,
} from "../backend/resources/js/lib/featureMap";

const layers = (over: Partial<FeatureLayers>): FeatureLayers => ({
  ...EMPTY_FEATURE_LAYERS,
  ...over,
});

describe("featureMap precedence (spec 45 §4)", () => {
  it("returns tier defaults when there is no override", () => {
    const map = resolveFeatureMap(
      layers({ TierDefaults: { "Modules.Reports": true, "Limits.MaxUsers": 10 } }),
    );
    expect(map).toEqual({ "Modules.Reports": true, "Limits.MaxUsers": 10 });
  });

  it("lets a per-license override replace the tier default", () => {
    const map = resolveFeatureMap(
      layers({
        TierDefaults: { "Modules.Reports": true, "Limits.MaxUsers": 10 },
        LicenseOverrides: { "Limits.MaxUsers": 50 },
      }),
    );
    expect(map["Limits.MaxUsers"]).toBe(50);
    expect(map["Modules.Reports"]).toBe(true);
  });

  it("lets an override deny a feature the tier granted", () => {
    const map = resolveFeatureMap(
      layers({
        TierDefaults: { "Modules.Api": true },
        LicenseOverrides: { "Modules.Api": false },
      }),
    );
    expect(map["Modules.Api"]).toBe(false);
    expect(isFeatureEnabled(map, "Modules.Api")).toBe(false);
  });

  it("surfaces an override-only key that no tier row supplies", () => {
    const map = resolveFeatureMap(layers({ LicenseOverrides: { "Support.Tier": "Priority" } }));
    expect(map["Support.Tier"]).toBe("Priority");
  });

  // AC-FEAT-004 / AC-FEAT-005: absence means NOT licensed.
  it("never synthesizes a default for an unsupplied key", () => {
    const map = resolveFeatureMap(layers({ TierDefaults: { "Modules.Reports": true } }));
    expect(Object.keys(map)).toEqual(["Modules.Reports"]);
    expect("Modules.Api" in map).toBe(false);
    expect(map["Modules.Api"]).toBeUndefined();
    expect(isFeatureEnabled(map, "Modules.Api")).toBe(false);
  });

  it("resolves an empty map when both layers are empty", () => {
    expect(resolveFeatureMap(EMPTY_FEATURE_LAYERS)).toEqual({});
  });

  it("tolerates missing layer objects from a partial server payload", () => {
    expect(resolveFeatureMap({ LicenseTierId: 2 } as unknown as FeatureLayers)).toEqual({});
  });

  it("does not coerce truthy scalars into an enabled gate (spec 45 §3)", () => {
    const map = resolveFeatureMap(
      layers({ TierDefaults: { "Limits.MaxUsers": 1, "Support.Tier": "true" } }),
    );
    expect(isFeatureEnabled(map, "Limits.MaxUsers")).toBe(false);
    expect(isFeatureEnabled(map, "Support.Tier")).toBe(false);
  });
});

describe("featureMap provenance", () => {
  it("tags origin and records the shadowed tier value", () => {
    const entries = resolveFeatureEntries(
      layers({
        TierDefaults: { "Limits.MaxUsers": 10, "Modules.Reports": true },
        LicenseOverrides: { "Limits.MaxUsers": 50 },
        ValueTypes: { "Limits.MaxUsers": "Number", "Modules.Reports": "Boolean" },
      }),
    );
    const maxUsers = entries.find((e) => e.FeatureKey === "Limits.MaxUsers");
    expect(maxUsers?.Origin).toBe(FeatureOriginType.LicenseOverride);
    expect(maxUsers?.Value).toBe(50);
    expect(maxUsers?.ShadowedTierValue).toBe(10);
    expect(maxUsers?.ValueType).toBe("Number");

    const reports = entries.find((e) => e.FeatureKey === "Modules.Reports");
    expect(reports?.Origin).toBe(FeatureOriginType.TierDefault);
    expect(reports?.ShadowedTierValue).toBeUndefined();
  });

  it("omits ShadowedTierValue for an override-only key", () => {
    const [entry] = resolveFeatureEntries(layers({ LicenseOverrides: { "Modules.Api": true } }));
    expect(entry.Origin).toBe(FeatureOriginType.LicenseOverride);
    expect("ShadowedTierValue" in entry).toBe(false);
  });

  it("sorts by case-significant FeatureKey without lowercasing", () => {
    const entries = resolveFeatureEntries(
      layers({ TierDefaults: { "Modules.Api": true, "Branding.Watermark": false, "Support.Tier": "Standard" } }),
    );
    expect(entries.map((e) => e.FeatureKey)).toEqual([
      "Branding.Watermark",
      "Modules.Api",
      "Support.Tier",
    ]);
  });

  it("renders values by declared type and never blanks an unknown type", () => {
    expect(formatFeatureValue(true, "Boolean")).toBe("enabled");
    expect(formatFeatureValue(false, "Boolean")).toBe("disabled");
    expect(formatFeatureValue(0, "Number")).toBe("0");
    expect(formatFeatureValue("Priority", "String")).toBe("Priority");
    expect(formatFeatureValue(7, "Mystery")).toBe("7");
    expect(formatFeatureValue(undefined)).toBe(FEATURE_NOT_LICENSED);
  });
});

describe("featureMap parity with the PHP resolver and the SPA", () => {
  const service = readFileSync("backend/app/Services/FeatureService.php", "utf8");
  const spa = readFileSync("src/lib/lara-features.ts", "utf8");

  it("keeps FeatureService::resolve() merging overrides last", () => {
    expect(service).toContain("return array_merge($tierLayer, $overrides);");
  });

  it("exposes an unmerged layers() projection for the console", () => {
    expect(service).toContain("public function layers(int $licenseId");
    expect(service).toContain("'TierDefaults' => $tierDefaults,");
    expect(service).toContain("'LicenseOverrides' => $overrides,");
    expect(service).toContain("'ValueTypes' => $valueTypes,");
  });

  it("keeps the SPA resolver on the same tier-then-override order", () => {
    const body = spa.slice(spa.indexOf("export function resolveFeatureMap"));
    expect(body.indexOf("tierRows")).toBeLessThan(body.indexOf("licenseRows"));
  });
});

describe("license detail wiring", () => {
  const web = readFileSync("backend/routes/web.php", "utf8");
  const page = readFileSync("backend/resources/js/Pages/Admin/Licenses/Show.tsx", "utf8");
  const panel = readFileSync("backend/resources/js/Components/admin/LicenseFeaturePanel.tsx", "utf8");

  it("resolves the layers server-side and passes them as a prop", () => {
    expect(web).toContain("->layers((int) $license['LicenseId'])");
    expect(web).toContain("'featureLayers' => $featureLayers,");
  });

  it("degrades to a null prop instead of blanking the page", () => {
    expect(web).toContain("$featureLayers = null;");
    expect(web).toContain("console.license.feature_layers_failed");
  });

  it("renders the panel on the license detail page", () => {
    expect(page).toContain("<LicenseFeaturePanel layers={featureLayers} />");
    expect(page).toContain("featureLayers: FeatureLayers | null;");
  });

  // spec 24/38 §Anti-patterns: no optimistic feature toggles in the console.
  it("keeps the panel read-only", () => {
    expect(panel).not.toContain("onChange");
    expect(panel).not.toContain("laraRequest");
    expect(panel).not.toContain("<input");
  });
});
