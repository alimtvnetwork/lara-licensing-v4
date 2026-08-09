/**
 * Plan 17 Step 25: shared config-tier seed surface.
 *
 * Config-tier data (feature definitions + tier-features matrix) is
 * hydrated by EVERY preview seed (default, empty, error) so admin
 * lookup screens (`admin.features`, tier resolvers) always find the
 * canonical catalog and render an authentic empty *transactional*
 * state, never an "internal error" from an empty catalog.
 *
 * This is the minimal single-file precursor to steps 8-13 (the full
 * `src/lib/preview-seeds/config/` directory + spec/06 parity linter).
 * Row values are copied verbatim from `default.ts` so both seeds stay
 * bit-identical on the config surface.
 */

import type { FeatureDefinition } from "@/generated/api/schema";
import { write } from "../preview-store";
import type { TierFeatureResource } from "../lara-features";

const T0 = "2026-01-01T00:00:00Z";

const FEATURE_DEFINITIONS: readonly FeatureDefinition[] = [
  {
    Code: "core.reports",
    DisplayName: "Reports",
    Description: "Standard reporting suite.",
    Category: "core",
    IsBillable: false,
    CreatedAt: T0,
    UpdatedAt: T0,
  },
  {
    Code: "core.dashboards",
    DisplayName: "Dashboards",
    Description: "KPI dashboards.",
    Category: "core",
    IsBillable: false,
    CreatedAt: T0,
    UpdatedAt: T0,
  },
  {
    Code: "addon.exports",
    DisplayName: "Data Exports",
    Description: "CSV/Parquet exports.",
    Category: "addon",
    IsBillable: true,
    CreatedAt: T0,
    UpdatedAt: T0,
  },
  {
    Code: "addon.sso",
    DisplayName: "SSO",
    Description: "SAML/OIDC SSO.",
    Category: "addon",
    IsBillable: true,
    CreatedAt: T0,
    UpdatedAt: T0,
  },
] as const;

const TIER_FEATURES: readonly TierFeatureResource[] = [
  { LicenseTierId: 1, FeatureKey: "Modules.Reports", Value: true },
  { LicenseTierId: 1, FeatureKey: "Modules.Api", Value: false },
  { LicenseTierId: 1, FeatureKey: "Limits.MaxUsers", Value: 5 },
  { LicenseTierId: 1, FeatureKey: "Limits.MaxProjects", Value: 3 },
  { LicenseTierId: 1, FeatureKey: "Branding.Watermark", Value: true },
  { LicenseTierId: 1, FeatureKey: "Support.Tier", Value: "Community" },
  { LicenseTierId: 2, FeatureKey: "Modules.Reports", Value: true },
  { LicenseTierId: 2, FeatureKey: "Modules.Api", Value: true },
  { LicenseTierId: 2, FeatureKey: "Limits.MaxUsers", Value: 25 },
  { LicenseTierId: 2, FeatureKey: "Limits.MaxProjects", Value: 10 },
  { LicenseTierId: 2, FeatureKey: "Branding.Watermark", Value: false },
  { LicenseTierId: 2, FeatureKey: "Support.Tier", Value: "Standard" },
  { LicenseTierId: 3, FeatureKey: "Modules.Reports", Value: true },
  { LicenseTierId: 3, FeatureKey: "Modules.Api", Value: true },
  { LicenseTierId: 3, FeatureKey: "Limits.MaxUsers", Value: 250 },
  { LicenseTierId: 3, FeatureKey: "Limits.MaxProjects", Value: 100 },
  { LicenseTierId: 3, FeatureKey: "Branding.Watermark", Value: false },
  { LicenseTierId: 3, FeatureKey: "Support.Tier", Value: "Priority" },
] as const;

export const CONFIG_FEATURE_CODES: readonly string[] = FEATURE_DEFINITIONS.map((f) => f.Code);
export const CONFIG_TIER_FEATURE_COUNT = TIER_FEATURES.length;

/**
 * Hydrate the config-tier surface (feature catalog + tier-features).
 * Idempotent: writes overwrite by key so repeated calls converge.
 * Does NOT write transactional data (licenses, quotas, audit, ...).
 */
export async function hydrateConfig(): Promise<void> {
  for (const f of FEATURE_DEFINITIONS) {
    await write<FeatureDefinition>("features", f.Code, f);
  }
  for (const r of TIER_FEATURES) {
    await write<TierFeatureResource>("tier-features", `${r.LicenseTierId}::${r.FeatureKey}`, r);
  }
}
