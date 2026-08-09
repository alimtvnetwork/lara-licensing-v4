/**
 * Preview fixtures: admin tier-features domain (Plan 17 Step 23).
 *
 * Registers no apiClient operations today: the read path in
 * `src/lib/lara-features.ts` (`tierFeaturesQueryOptions`) bypasses
 * `apiClient.call` and routes through `requestLaraApi`, which fails
 * loud in preview mode (INV-RM-05). The preview bridge in the same
 * module reads deterministic rows from this domain slot instead.
 *
 * This module exists so `PREVIEW_FIXTURE_MODULE_NAMES` gains a
 * `tier-features` slot in `preview-store`, letting the default seed
 * persist rows keyed by `<LicenseTierId>::<FeatureKey>`. Empty slot
 * under the `empty` seed and error-rejection under the `error` seed
 * are both enforced by callers reading the shared error-code map.
 *
 * INV-RM-05: preview and live callers observe the same typed shape
 * (`tierFeatureResourceSchema` in `lara-features.ts`).
 */

import type { PreviewFixtureModule } from "./_module";

const mod: PreviewFixtureModule = {
  name: "tier-features",
  operations: [],
  register(): void {
    // No operation ids: preview reads are served by the bridge in
    // src/lib/lara-features.ts against preview-store domain "tier-features".
  },
};

export default mod;
