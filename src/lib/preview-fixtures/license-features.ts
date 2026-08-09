/**
 * Preview fixtures: admin license-features domain (Plan 17 Step 23).
 *
 * Registers no apiClient operations today: the read path in
 * `src/lib/lara-features.ts` (`licenseFeaturesQueryOptions`) bypasses
 * `apiClient.call` and routes through `requestLaraApi`, which fails
 * loud in preview mode (INV-RM-05). The preview bridge in the same
 * module reads deterministic rows from this domain slot instead.
 *
 * This module exists so `PREVIEW_FIXTURE_MODULE_NAMES` gains a
 * `license-features` slot in `preview-store`, letting the default seed
 * persist rows keyed by `<LicenseId>::<FeatureKey>` where LicenseId is
 * the numeric id assigned by `primeLegacyIdMap()` for "licenses".
 *
 * INV-RM-05: preview and live callers observe the same typed shape
 * (`licenseFeatureResourceSchema` in `lara-features.ts`).
 */

import type { PreviewFixtureModule } from "./_module";

const mod: PreviewFixtureModule = {
  name: "license-features",
  operations: [],
  register(): void {
    // No operation ids: preview reads are served by the bridge in
    // src/lib/lara-features.ts against preview-store domain "license-features".
  },
};

export default mod;
