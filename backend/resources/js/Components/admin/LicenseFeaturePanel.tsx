// Plan 06 step 81. Read-only entitlement panel for the license detail page.
//
// Renders the resolved feature map with provenance per
// spec/21-app/45-license-features.md §4. Deliberately no toggles: there is no
// LicenseFeatures PATCH contract in the Laravel port yet, and spec
// 24/38 §"Anti-patterns" bans optimistic feature switches. A key absent from
// both layers is simply not in the list, because AC-FEAT-004 forbids showing a
// synthesized `disabled` row for something that is not licensed at all.

import { EmptyState } from "@/Components/ui/EmptyState";
import {
  FeatureOriginType,
  formatFeatureValue,
  resolveFeatureEntries,
  type FeatureLayers,
} from "@/lib/featureMap";

interface Props {
  layers: FeatureLayers | null;
}

export function LicenseFeaturePanel({ layers }: Props) {
  if (layers === null) {
    return (
      <EmptyState
        preset="box"
        headline="Entitlements unavailable"
        body="The feature layers could not be resolved for this license. Reload, or check that Root Features is seeded."
      />
    );
  }

  const entries = resolveFeatureEntries(layers);

  if (entries.length === 0) {
    return (
      <EmptyState
        preset="box"
        headline="No entitlements"
        body="Neither the tier defaults nor the per-license overrides grant any feature. Nothing is licensed."
      />
    );
  }

  return (
    <table className="w-full text-sm">
      <caption className="sr-only">
        Resolved feature entitlements. Per-license overrides take precedence over tier defaults.
      </caption>
      <thead>
        <tr className="border-b border-border text-left">
          <th scope="col" className="py-2 font-medium">
            Feature key
          </th>
          <th scope="col" className="py-2 font-medium">
            Effective
          </th>
          <th scope="col" className="py-2 text-right font-medium">
            Source
          </th>
        </tr>
      </thead>
      <tbody>
        {entries.map((entry) => (
          <tr key={entry.FeatureKey} className="border-b border-border last:border-0">
            {/* FeatureKey is case-significant (spec 45 §2) and never lowercased. */}
            <th scope="row" className="py-2 text-left font-mono text-xs font-normal">
              {entry.FeatureKey}
            </th>
            <td className="py-2 font-medium">
              {formatFeatureValue(entry.Value, entry.ValueType)}
            </td>
            <td className="py-2 text-right text-xs text-muted-foreground">
              {entry.Origin === FeatureOriginType.LicenseOverride ? "override" : "tier default"}
              {entry.ShadowedTierValue !== undefined ? (
                <span className="ml-1">
                  (tier: {formatFeatureValue(entry.ShadowedTierValue, entry.ValueType)})
                </span>
              ) : null}
            </td>
          </tr>
        ))}
      </tbody>
    </table>
  );
}
