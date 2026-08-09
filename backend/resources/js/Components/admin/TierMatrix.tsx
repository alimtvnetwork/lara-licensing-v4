// Plan 06 step 70. TierFeatures matrix, rendered from server truth only.
//
// Contract (spec/24-app-ui-design-system/38-route-blueprint-admin-features.md
// §"Anti-patterns" 2 and AC-ROUTE-FEATURES-004): no optimistic state, no local
// mutation, no synthesized defaults. There is deliberately no onChange here;
// cells are read-only text/badges until a TierFeatures PATCH endpoint with the
// `If-Match: <FeatureEtag>` contract exists.
//
// spec/21-app/45-license-features.md §4 AC-FEAT-004: an absent cell means "not
// licensed at this tier". It renders as "not set", never as false/0/"".

import { EmptyState } from "@/Components/ui/EmptyState";
import { cn } from "@/lib/utils";

export interface TierAxisEntry {
  LicenseTierId: number;
  TierName: string;
  TierOrdinal: number;
}

export interface TierCell {
  Value: boolean | number | string | null;
  UpdatedAt: string | null;
}

export interface FeatureRow {
  FeatureId: number;
  FeatureKey: string;
  ValueType: string;
  /** Keyed by LicenseTierId as a string, because JSON object keys are strings. */
  Cells: Record<string, TierCell | null>;
  AssignedTierCount: number;
}

const NOT_SET = "not set";

/**
 * Renders a JSONB scalar exactly as stored. A Boolean feature shows
 * enabled/disabled; Number and String show the literal value. An unknown
 * ValueType still renders the raw scalar rather than hiding it, so catalog
 * drift is visible instead of silently blank.
 */
function renderCellValue(cell: TierCell | null, valueType: string): string {
  if (cell === null || cell.Value === null) return NOT_SET;
  const value = cell.Value;
  if (valueType === "Boolean" || typeof value === "boolean") {
    return value === true ? "enabled" : "disabled";
  }
  return String(value);
}

function cellTone(cell: TierCell | null, valueType: string): string {
  if (cell === null || cell.Value === null) return "text-muted-foreground";
  if (valueType === "Boolean" || typeof cell.Value === "boolean") {
    return cell.Value === true ? "text-foreground font-medium" : "text-muted-foreground";
  }
  return "text-foreground font-medium";
}

interface Props {
  features: FeatureRow[];
  tiers: TierAxisEntry[];
}

export function TierMatrix({ features, tiers }: Props) {
  if (tiers.length === 0) {
    return (
      <EmptyState
        preset="box"
        headline="No license tiers"
        body="Root LicenseTiers has no rows. Run the root migrations before curating tier defaults."
      />
    );
  }

  if (features.length === 0) {
    return (
      <EmptyState
        preset="box"
        headline="No features in the catalog"
        body="Root Features is empty. Seed it from config(lara.feature_registry) with FeatureCatalogSeeder."
      />
    );
  }

  return (
    <div className="overflow-x-auto rounded-xl border border-border bg-card">
      <table className="w-full min-w-[640px] text-sm">
        <caption className="sr-only">
          Feature catalog by license tier. Values are tier defaults from TierFeatures; per-license
          overrides in LicenseFeatures take precedence at runtime.
        </caption>
        <thead>
          <tr className="border-b border-border text-left">
            <th scope="col" className="px-4 py-3 font-medium">
              Feature key
            </th>
            <th scope="col" className="px-4 py-3 font-medium">
              Value type
            </th>
            {tiers.map((tier) => (
              <th key={tier.LicenseTierId} scope="col" className="px-4 py-3 font-medium">
                {tier.TierName}
                <span className="ml-1 text-xs text-muted-foreground">#{tier.TierOrdinal}</span>
              </th>
            ))}
            <th scope="col" className="px-4 py-3 text-right font-medium">
              Tiers set
            </th>
          </tr>
        </thead>
        <tbody>
          {features.map((feature) => (
            <tr key={feature.FeatureId} className="border-b border-border last:border-0">
              {/* FeatureKey is case-significant (spec 24/38 §4) and never lowercased. */}
              <th scope="row" className="px-4 py-3 text-left font-mono text-xs font-normal">
                {feature.FeatureKey}
              </th>
              <td className="px-4 py-3 text-muted-foreground">{feature.ValueType}</td>
              {tiers.map((tier) => {
                const cell = feature.Cells?.[String(tier.LicenseTierId)] ?? null;
                return (
                  <td
                    key={tier.LicenseTierId}
                    className={cn("px-4 py-3", cellTone(cell, feature.ValueType))}
                    title={cell?.UpdatedAt ? `Updated ${cell.UpdatedAt}` : "No TierFeatures row"}
                  >
                    {renderCellValue(cell, feature.ValueType)}
                  </td>
                );
              })}
              <td className="px-4 py-3 text-right tabular-nums">
                {feature.AssignedTierCount}/{tiers.length}
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}
