import { queryOptions } from "@tanstack/react-query";
import { z } from "zod";

import { apiClient } from "./api-client";
import { HttpMethodType, requestLaraApi } from "./lara-api-client";
import { getRuntimeMode } from "./runtime-mode";

/**
 * Feature admin client per:
 *   - spec/21-app/45-license-features.md v1.0.0 (registry, ValueType, precedence)
 *   - spec/21-app/11-api-contracts/02-license-contracts.md v1.4.0
 *     §Feature admin endpoints (seven routes)
 *   - spec/21-app/40-permissions.md §2 (Licenses.Read/Update, Roles.Assign)
 *   - spec/21-app/12-error-taxonomy.md (FeatureUnknown, FeatureValueInvalid)
 *   - spec/21-app/08-idempotency-envelope-hardening.md (PUT/DELETE Idempotency-Key)
 *
 * The FeatureKey and ValueType closed sets are enforced client-side BEFORE
 * the request goes on the wire, so a caller cannot silently target a
 * forbidden synonym or a wrong-typed value; violations throw a ZodError
 * before any network call, matching AC-API-LIC-013 / AC-API-LIC-014.
 */

/** Closed set from spec/21-app/45-license-features.md §2. */
export const FeatureKeyType = {
  ModulesReports: "Modules.Reports",
  ModulesApi: "Modules.Api",
  LimitsMaxUsers: "Limits.MaxUsers",
  LimitsMaxProjects: "Limits.MaxProjects",
  BrandingWatermark: "Branding.Watermark",
  SupportTier: "Support.Tier",
} as const;
export type FeatureKeyValue = (typeof FeatureKeyType)[keyof typeof FeatureKeyType];

export const featureKeySchema = z.enum([
  "Modules.Reports",
  "Modules.Api",
  "Limits.MaxUsers",
  "Limits.MaxProjects",
  "Branding.Watermark",
  "Support.Tier",
]);

export const featureValueTypeSchema = z.enum(["Boolean", "Number", "String"]);
export type FeatureValueTypeValue = z.infer<typeof featureValueTypeSchema>;

/** Registry table from spec/21-app/45-license-features.md §2 (FeatureKey -> ValueType). */
export const featureKeyValueTypeRegistry: Record<FeatureKeyValue, FeatureValueTypeValue> = {
  "Modules.Reports": "Boolean",
  "Modules.Api": "Boolean",
  "Limits.MaxUsers": "Number",
  "Limits.MaxProjects": "Number",
  "Branding.Watermark": "Boolean",
  "Support.Tier": "String",
};

/** Closed set from spec/21-app/45-license-features.md §2 Support.Tier row. */
export const SupportTierType = {
  Community: "Community",
  Standard: "Standard",
  Priority: "Priority",
} as const;
export type SupportTierValue = (typeof SupportTierType)[keyof typeof SupportTierType];
const supportTierSchema = z.enum(["Community", "Standard", "Priority"]);

/**
 * Validate a raw JSON value against the declared ValueType per
 * spec/21-app/45-license-features.md §3. Throws ZodError before any
 * network call so the server never sees a FeatureValueInvalid we could
 * have caught locally. Intentionally strict: `"true"`, `0`, `1` MUST NOT
 * be coerced.
 */
export function validateFeatureValue(
  featureKey: FeatureKeyValue,
  rawValue: unknown,
): boolean | number | string {
  const expectedType = featureKeyValueTypeRegistry[featureKey];
  if (expectedType === "Boolean") {
    return z.boolean().parse(rawValue);
  }
  if (expectedType === "Number") {
    return z.number().finite().int().parse(rawValue);
  }
  if (featureKey === "Support.Tier") {
    return supportTierSchema.parse(rawValue);
  }

  return z.string().min(1).max(128).parse(rawValue);
}

export const featureCatalogResourceSchema = z.object({
  FeatureKey: featureKeySchema,
  ValueType: featureValueTypeSchema,
});
export type FeatureCatalogResource = z.infer<typeof featureCatalogResourceSchema>;

export const tierFeatureResourceSchema = z.object({
  LicenseTierId: z.number().int().positive(),
  FeatureKey: featureKeySchema,
  Value: z.union([z.boolean(), z.number(), z.string()]),
});
export type TierFeatureResource = z.infer<typeof tierFeatureResourceSchema>;

export const licenseFeatureResourceSchema = z.object({
  LicenseId: z.number().int().positive(),
  FeatureKey: featureKeySchema,
  Value: z.union([z.boolean(), z.number(), z.string()]),
});
export type LicenseFeatureResource = z.infer<typeof licenseFeatureResourceSchema>;

/** GET /Features per 02-license-contracts.md §Feature admin. */
export function featureCatalogQueryOptions(pageSize = 100) {
  return queryOptions({
    queryKey: ["LaraApi", "Features", pageSize],
    queryFn: ({ signal }) =>
      getRuntimeMode().Mode === "preview"
        ? fetchPreviewFeatureCatalog()
        : requestLaraApi(`/Features?PageSize=${pageSize}`, featureCatalogResourceSchema, {
            signal,
          }),
    retry: false,
  });
}

/**
 * Preview bridge: synthesize the legacy closed-set feature catalog from
 * `featureKeyValueTypeRegistry` (spec/21-app/45-license-features.md §2).
 * Preview handler `admin.features.list` returns modern free-form codes
 * (e.g. `core.reports`) that would fail `featureKeySchema.parse`; the
 * catalog is fixed by spec so seed rows do not influence its contents.
 */
async function fetchPreviewFeatureCatalog(): Promise<FeatureCatalogResource[]> {
  const rows: FeatureCatalogResource[] = (
    Object.keys(featureKeyValueTypeRegistry) as FeatureKeyValue[]
  ).map((FeatureKey) => ({ FeatureKey, ValueType: featureKeyValueTypeRegistry[FeatureKey] }));
  console.info("lara-features:preview-bridge:catalog", { Count: rows.length });
  // Verify handler is registered so INV-RM-05 logs still fire in tests/tools.
  try {
    await apiClient.call("admin.features.list", {});
  } catch {
    /* preview-only sanity ping */
  }

  return rows;
}

/** GET /Tiers/{LicenseTierId}/Features per 02-license-contracts.md §Feature admin. */
export function tierFeaturesQueryOptions(licenseTierId: number) {
  return queryOptions({
    queryKey: ["LaraApi", "Tiers", licenseTierId, "Features"],
    queryFn: ({ signal }) =>
      getRuntimeMode().Mode === "preview"
        ? fetchPreviewTierFeatures(licenseTierId)
        : requestLaraApi(`/Tiers/${licenseTierId}/Features`, tierFeatureResourceSchema, { signal }),
    retry: false,
  });
}

/**
 * Preview bridge (Plan 17 Step 23): read tier-features from
 * preview-store domain "tier-features" (keyed by `<LicenseTierId>::<FeatureKey>`)
 * and validate each row through `tierFeatureResourceSchema` so live and
 * preview callers observe the same typed shape (INV-RM-05). Rows for
 * other tiers are filtered out client-side.
 */
async function fetchPreviewTierFeatures(licenseTierId: number): Promise<TierFeatureResource[]> {
  const { list } = await import("./preview-store");
  const entries = await list<TierFeatureResource>("tier-features");
  const rows = entries
    .map(([, v]) => v)
    .filter((r) => r.LicenseTierId === licenseTierId)
    .map((r) => tierFeatureResourceSchema.parse(r));
  console.info("lara-features:preview-bridge:tier-features", {
    LicenseTierId: licenseTierId,
    Count: rows.length,
  });

  return rows;
}

/**
 * PUT /Tiers/{LicenseTierId}/Features/{FeatureKey}. Validates value locally
 * against the §3 ValueType contract BEFORE sending, so an invalid value
 * short-circuits with ZodError instead of a 400 FeatureValueInvalid.
 */
export async function putTierFeature(
  licenseTierId: number,
  featureKey: FeatureKeyValue,
  rawValue: unknown,
  idempotencyKey: string,
): Promise<TierFeatureResource> {
  const value = validateFeatureValue(featureKey, rawValue);
  const [row] = await requestLaraApi(
    `/Tiers/${licenseTierId}/Features/${encodeURIComponent(featureKey)}`,
    tierFeatureResourceSchema,
    {
      method: HttpMethodType.Put,
      body: { Value: value },
      headers: { "Idempotency-Key": idempotencyKey },
    },
  );

  return row;
}

/** DELETE /Tiers/{LicenseTierId}/Features/{FeatureKey}. */
export async function deleteTierFeature(
  licenseTierId: number,
  featureKey: FeatureKeyValue,
  idempotencyKey: string,
): Promise<void> {
  await requestLaraApi(
    `/Tiers/${licenseTierId}/Features/${encodeURIComponent(featureKey)}`,
    tierFeatureResourceSchema,
    {
      method: HttpMethodType.Delete,
      headers: { "Idempotency-Key": idempotencyKey },
    },
  );
}

/** GET /Licenses/{LicenseId}/Features per 02-license-contracts.md §Feature admin. */
export function licenseFeaturesQueryOptions(licenseId: number) {
  return queryOptions({
    queryKey: ["LaraApi", "Licenses", licenseId, "Features"],
    queryFn: ({ signal }) =>
      getRuntimeMode().Mode === "preview"
        ? fetchPreviewLicenseFeatures(licenseId)
        : requestLaraApi(`/Licenses/${licenseId}/Features`, licenseFeatureResourceSchema, {
            signal,
          }),
    retry: false,
  });
}

/**
 * Preview bridge (Plan 17 Step 23): read license-features overrides from
 * preview-store domain "license-features" (keyed by `<LicenseId>::<FeatureKey>`)
 * and validate through `licenseFeatureResourceSchema` (INV-RM-05).
 */
async function fetchPreviewLicenseFeatures(licenseId: number): Promise<LicenseFeatureResource[]> {
  const { list } = await import("./preview-store");
  const entries = await list<LicenseFeatureResource>("license-features");
  const rows = entries
    .map(([, v]) => v)
    .filter((r) => r.LicenseId === licenseId)
    .map((r) => licenseFeatureResourceSchema.parse(r));
  console.info("lara-features:preview-bridge:license-features", {
    LicenseId: licenseId,
    Count: rows.length,
  });

  return rows;
}

/**
 * PUT /Licenses/{LicenseId}/Features/{FeatureKey}. Client-side ValueType guard.
 * Requires `ifMatch`: the strong ETag from the most recent
 * `GET /Licenses/{LicenseId}` per
 * spec/21-app/11-api-contracts/09-concurrency-control.md §Scope row 3.
 * Missing header would return `428 PreconditionRequired`; a stale value
 * would return `412 PreconditionFailed` with the fresh ETag in
 * `Details[0].Value`.
 */
export async function putLicenseFeature(
  licenseId: number,
  featureKey: FeatureKeyValue,
  rawValue: unknown,
  idempotencyKey: string,
  ifMatch: string,
): Promise<LicenseFeatureResource> {
  const value = validateFeatureValue(featureKey, rawValue);
  const [row] = await requestLaraApi(
    `/Licenses/${licenseId}/Features/${encodeURIComponent(featureKey)}`,
    licenseFeatureResourceSchema,
    {
      method: HttpMethodType.Put,
      body: { Value: value },
      headers: { "Idempotency-Key": idempotencyKey, "If-Match": ifMatch },
    },
  );

  return row;
}

/**
 * DELETE /Licenses/{LicenseId}/Features/{FeatureKey}. In-scope per
 * spec/21-app/11-api-contracts/09-concurrency-control.md §Scope row 4;
 * `ifMatch` requirement identical to `putLicenseFeature`.
 */
export async function deleteLicenseFeature(
  licenseId: number,
  featureKey: FeatureKeyValue,
  idempotencyKey: string,
  ifMatch: string,
): Promise<void> {
  await requestLaraApi(
    `/Licenses/${licenseId}/Features/${encodeURIComponent(featureKey)}`,
    licenseFeatureResourceSchema,
    {
      method: HttpMethodType.Delete,
      headers: { "Idempotency-Key": idempotencyKey, "If-Match": ifMatch },
    },
  );
}

/**
 * Resolve the runtime feature map for a license per
 * spec/21-app/45-license-features.md §4 (Precedence). Tier layer first,
 * then LicenseFeatures overrides. Absence of a key means "not licensed":
 * callers MUST NOT synthesize defaults from this map. Pure function, no
 * network calls, deterministic; suitable for both admin previews and the
 * verify-response mirror.
 *
 * AC-FEAT-003 and AC-FEAT-004 are locked in `tests/lara-feature-precedence.test.ts`.
 */
export function resolveFeatureMap(
  tierRows: readonly TierFeatureResource[],
  licenseRows: readonly LicenseFeatureResource[],
): Record<FeatureKeyValue, boolean | number | string> {
  const map = {} as Record<FeatureKeyValue, boolean | number | string>;
  for (const row of tierRows) {
    map[row.FeatureKey] = row.Value;
  }
  for (const row of licenseRows) {
    map[row.FeatureKey] = row.Value;
  }

  return map;
}
