/**
 * Preview fixtures: features catalog domain (Plan 16 Step 42).
 *
 * Registers `admin.features.list` which enumerates the seeded feature
 * catalog from the preview store keyed by `Code`. Results are sorted by
 * Category then Code so the license form multi-select renders in a
 * predictable order across seeds. Under the `error` seed the handler
 * rejects with `ERROR_SEED_DOMAIN_CODE.features` (INV-RM-06).
 *
 * The features catalog is read-only in preview mode: mutations happen
 * only through backend seeders (FeatureCatalogSeeder, backend/v0.272.0).
 * INV-RM-05: preview and live callers observe identical
 * `AdminFeaturesListResponse` shape.
 */

import type { PreviewFixtureModule } from "./_module";
import { previewError, previewSuccess } from "./_shared";
import { list } from "@/lib/preview-store";
import { registerPreviewHandler } from "@/lib/preview-transport";
import { ERROR_SEED_DOMAIN_CODE } from "@/lib/preview-seeds/error";
import type { AdminFeaturesListResponse, FeatureDefinition } from "@/generated/api/schema";

const HTTP_UNPROCESSABLE = 422;

function rejectIfErrorSeed(seed: string, requestId: string): void {
  if (seed !== "error") return;
  previewError(
    ERROR_SEED_DOMAIN_CODE.features,
    "Preview error seed active: features calls always fail (INV-RM-06).",
    HTTP_UNPROCESSABLE,
    requestId,
  );
}

async function loadAllFeatures(): Promise<FeatureDefinition[]> {
  const rows = await list<FeatureDefinition>("features");

  return rows.map(([, v]) => v);
}

function sortCatalog(items: FeatureDefinition[]): FeatureDefinition[] {
  return [...items].sort((a, b) => {
    if (a.Category !== b.Category) return a.Category.localeCompare(b.Category);

    return a.Code.localeCompare(b.Code);
  });
}

const mod: PreviewFixtureModule = {
  name: "features",
  operations: ["admin.features.list"],
  register(): void {
    registerPreviewHandler(
      "admin.features.list",
      async (ctx): Promise<AdminFeaturesListResponse> => {
        rejectIfErrorSeed(ctx.Seed, ctx.RequestId);
        const all = await loadAllFeatures();
        const sorted = sortCatalog(all);
        console.info("preview-fixtures:admin.features.list", {
          RequestId: ctx.RequestId,
          Count: sorted.length,
        });

        return previewSuccess<"admin.features.list">({ Items: sorted });
      },
    );
  },
};

export default mod;
