// Plan 06 step 70. Admin feature/tier matrix page at `/admin/features`.
// Props come straight from Admin\FeatureController::index via routes/web.php.
// Read-only surface: no mutation controls until the TierFeatures PATCH
// contract from spec 24/38 §"Mutations" is implemented.

import { Head } from "@inertiajs/react";

import ConsoleLayout from "@/Layouts/ConsoleLayout";
import { PageHeader } from "@/Components/shell/PageHeader";
import { StatCard } from "@/Components/ui/StatCard";
import {
  TierMatrix,
  type FeatureRow,
  type TierAxisEntry,
} from "@/Components/admin/TierMatrix";

interface Props {
  features?: FeatureRow[];
  tiers?: TierAxisEntry[];
}

export default function FeaturesIndex({ features = [], tiers = [] }: Props) {
  const assignedCells = features.reduce((sum, row) => sum + row.AssignedTierCount, 0);
  const totalCells = features.length * tiers.length;
  const unassignedFeatures = features.filter((row) => row.AssignedTierCount === 0).length;

  return (
    <ConsoleLayout>
      <Head title="Features | Licensing Portal">
        <meta name="robots" content="noindex,nofollow" />
      </Head>
      <PageHeader
        title="Features"
        breadcrumbs={[{ label: "Admin", to: "/admin" }, { label: "Features" }]}
        description="Tier defaults from Root TierFeatures. Per-license overrides in LicenseFeatures win at runtime, so a value here is the fallback, not the final answer."
      />

      <div className="mt-8 grid gap-4 sm:grid-cols-3">
        <StatCard label="Features in catalog" value={features.length} />
        <StatCard
          label="Tier cells assigned"
          value={totalCells === 0 ? "0" : `${assignedCells}/${totalCells}`}
        />
        <StatCard
          label="Features with no tier"
          value={unassignedFeatures}
          description={
            unassignedFeatures > 0
              ? "These resolve to not licensed unless a per-license override sets them."
              : undefined
          }
        />
      </div>

      <div className="mt-8">
        <TierMatrix features={features} tiers={tiers} />
      </div>

      <p className="mt-4 text-xs text-muted-foreground">
        Read-only view. Editing tier defaults requires the TierFeatures update endpoint, which is
        not implemented yet; toggling here without it would show state the server never accepted.
      </p>
    </ConsoleLayout>
  );
}
