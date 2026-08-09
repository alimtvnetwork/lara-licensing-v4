// Plan 06 step 81. Client-side mirror of the runtime feature-map resolver.
//
// Normative source: spec/21-app/45-license-features.md v1.0.0 §4 (Precedence).
// Layer order is strictly `LicenseFeatures` (shard, per-license override) over
// `TierFeatures` (Root, tier default). Absence of a key means NOT licensed:
// AC-FEAT-004 / AC-FEAT-005 forbid synthesizing `false` / `0` / `""` for a key
// that no layer supplies, so this module never invents an entry and callers
// must treat a missing key as "not licensed" rather than as a falsy value.
//
// Parity targets, all three must agree key-for-key:
//   - src/lib/lara-features.ts `resolveFeatureMap` (SPA, lines 298-310)
//   - backend/app/Services/FeatureService.php `resolve()` (array_merge order)
//   - this module
//
// Pure and deterministic: no network calls, no Inertia coupling. The layers
// arrive as props resolved server-side, because the console is read-only for
// features until a TierFeatures/LicenseFeatures PATCH contract exists.

/** Value domain from spec 45 §2: JSONB scalars only, never objects or arrays. */
export type FeatureValue = boolean | number | string;

/** Declared ValueType column on Root `Features` (spec 45 §2). */
export type FeatureValueType = "Boolean" | "Number" | "String";

/**
 * The two layers, exactly as `FeatureService::layers()` projects them:
 * FeatureKey -> decoded JSONB scalar. A key absent from both layers is not
 * licensed and must not appear in the resolved map.
 */
export interface FeatureLayers {
  LicenseTierId: number | null;
  /** Root `TierFeatures` rows for the license's tier. */
  TierDefaults: Record<string, FeatureValue>;
  /** Shard `LicenseFeatures` rows for this license; wins on conflict. */
  LicenseOverrides: Record<string, FeatureValue>;
  /** Root `Features.ValueType`, keyed by FeatureKey, for rendering only. */
  ValueTypes: Record<string, FeatureValueType | string>;
}

export const EMPTY_FEATURE_LAYERS: FeatureLayers = {
  LicenseTierId: null,
  TierDefaults: {},
  LicenseOverrides: {},
  ValueTypes: {},
};

/** Which layer supplied the effective value. */
export const FeatureOriginType = {
  TierDefault: "TierDefault",
  LicenseOverride: "LicenseOverride",
} as const;
export type FeatureOrigin = (typeof FeatureOriginType)[keyof typeof FeatureOriginType];

export interface FeatureEntry {
  FeatureKey: string;
  Value: FeatureValue;
  Origin: FeatureOrigin;
  ValueType: FeatureValueType | string | null;
  /**
   * Present only when an override shadowed a tier default, so the console can
   * show the operator what the tier would otherwise have granted. `undefined`
   * (not `null`) when the tier layer had no row for this key at all.
   */
  ShadowedTierValue?: FeatureValue;
}

/**
 * Resolve the effective FeatureKey -> Value map.
 *
 * Mirrors `array_merge($tierLayer, $overrides)` in FeatureService::resolve():
 * tier layer first, overrides last, so an override with the same key replaces
 * the default. Keys present in neither layer stay absent.
 */
export function resolveFeatureMap(layers: FeatureLayers): Record<string, FeatureValue> {
  const map: Record<string, FeatureValue> = {};
  for (const [key, value] of Object.entries(layers.TierDefaults ?? {})) {
    map[key] = value;
  }
  for (const [key, value] of Object.entries(layers.LicenseOverrides ?? {})) {
    map[key] = value;
  }
  return map;
}

/**
 * Resolved map plus provenance, sorted by FeatureKey. FeatureKey is
 * case-significant per spec 45 §2, so the sort is a plain codepoint compare
 * and never lowercases the key.
 */
export function resolveFeatureEntries(layers: FeatureLayers): FeatureEntry[] {
  const tier = layers.TierDefaults ?? {};
  const overrides = layers.LicenseOverrides ?? {};
  const valueTypes = layers.ValueTypes ?? {};
  const keys = Array.from(new Set([...Object.keys(tier), ...Object.keys(overrides)])).sort();

  return keys.map((FeatureKey) => {
    const hasOverride = Object.prototype.hasOwnProperty.call(overrides, FeatureKey);
    const hasTier = Object.prototype.hasOwnProperty.call(tier, FeatureKey);
    const entry: FeatureEntry = {
      FeatureKey,
      Value: hasOverride ? overrides[FeatureKey] : tier[FeatureKey],
      Origin: hasOverride ? FeatureOriginType.LicenseOverride : FeatureOriginType.TierDefault,
      ValueType: valueTypes[FeatureKey] ?? null,
    };
    if (hasOverride && hasTier) {
      entry.ShadowedTierValue = tier[FeatureKey];
    }
    return entry;
  });
}

/**
 * True only when the key is present in some layer AND its value is the boolean
 * `true`. A Number or String feature is never coerced to a gate: spec 45 §3
 * types are strict, and `1` / `"true"` must not read as enabled.
 */
export function isFeatureEnabled(map: Record<string, FeatureValue>, featureKey: string): boolean {
  return Object.prototype.hasOwnProperty.call(map, featureKey) && map[featureKey] === true;
}

/** Sentinel copy for a key no layer supplies (AC-FEAT-004). */
export const FEATURE_NOT_LICENSED = "not licensed";

/**
 * Presentation only. Booleans read enabled/disabled; Number and String render
 * the literal scalar so catalog drift stays visible instead of blanking.
 */
export function formatFeatureValue(value: FeatureValue | undefined, valueType?: string | null): string {
  if (value === undefined) return FEATURE_NOT_LICENSED;
  if (valueType === "Boolean" || typeof value === "boolean") {
    return value === true ? "enabled" : "disabled";
  }
  return String(value);
}
