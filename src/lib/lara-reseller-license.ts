import { queryOptions } from "@tanstack/react-query";
import { z } from "zod";

import { apiClient } from "./api-client";
import { requestLaraApi } from "./lara-api-client";
import { assignNumeric, ulidFor } from "./preview-id-map";
import { getRuntimeMode } from "./runtime-mode";
import type { License as PreviewLicense, LicenseStatus } from "@/generated/api/schema";

/**
 * Plan 09 steps 47 + 48. Reseller-scoped License read surface.
 *
 * Backend contract lives in `backend/app/Http/Controllers/Reseller/LicenseController.php`
 * (`::index`, `::show`, `::ledger`) and is routed at
 *   GET /Api/Reseller/Licenses
 *   GET /Api/Reseller/Licenses/{LicenseKey}
 *   GET /Api/Reseller/Licenses/{LicenseKey}/Ledger
 * Shard binding is applied server-side by `ShardBindingMiddleware`, so the
 * URL never carries a reseller id: the caller's tenant is inferred from
 * the Sanctum session. This module keeps that invariant on the client by
 * refusing to accept a `resellerId` in the request URL and instead reading
 * `Users.Me` client-side (see `reseller.$resellerId.licenses.tsx`) purely
 * for the row-scope UI gate. Server row-scope remains authoritative.
 *
 * Shape differs from the Admin `licenseSchema` in `lara-license.ts`:
 * reseller reads project denormalized `TierName` + `EnvironmentName` +
 * `Status` strings + a `Version` int for If-Match, whereas Admin reads
 * project raw FK ids. Do NOT collapse the two schemas: the wire shapes
 * are owned by their respective controllers.
 *
 * Plan 17 Step 7: preview-mode bridge.
 *   * List and detail branch on `getRuntimeMode().Mode === "preview"` and
 *     route through the existing `admin.licenses.list` preview handler
 *     (there is no `reseller.licenses.*` operation in the modern schema).
 *   * The caller's numeric `resellerId` maps back to a ULID via
 *     `ulidFor("resellers", resellerId)` (primed by the default seed as
 *     1 <-> RSLLR1) and is passed as the `ResellerId` filter so
 *     `matchesFilters` in `preview-fixtures/licenses.ts:111` returns only
 *     rows owned by that shard. Live/production path is unchanged.
 */
export const resellerLicenseSchema = z.object({
  LicenseId: z.number().int().positive(),
  LicenseKey: z.string().min(1),
  PrefixValue: z.string(),
  ResellerId: z.number().int().positive(),
  IssuedByUserId: z.number().int().nonnegative(),
  IssuerActorType: z.string(),
  TierName: z.string(),
  EnvironmentName: z.string(),
  ProductVersion: z.string(),
  Status: z.string(),
  IssuedAt: z.string(),
  ExpiresAt: z.string(),
  RevokedAt: z.string(),
  RevokeReason: z.string(),
  Version: z.number().int().nonnegative(),
});

export type ResellerLicense = z.infer<typeof resellerLicenseSchema>;

const TIER_NAMES = ["Starter", "Growth", "Scale", "Enterprise"] as const;
const ENVIRONMENT_NAMES = ["Production", "Staging", "Sandbox"] as const;
const STATUS_LABEL: Record<LicenseStatus, string> = {
  active: "Active",
  suspended: "Suspended",
  revoked: "Revoked",
  expired: "Expired",
};

function hashUlid(id: string): number {
  let h = 0;
  for (let i = 0; i < id.length; i++) h = (h * 31 + id.charCodeAt(i)) | 0;

  return Math.abs(h);
}

function prefixFromSerial(serial: string): string {
  const parts = serial.split("-");
  if (parts.length < 2) return serial;

  return `${parts[0]}-${parts[1]}`;
}

function isRevoked(p: PreviewLicense): boolean {
  return p.Status === "revoked";
}

async function adaptPreviewResellerLicense(
  p: PreviewLicense,
  resellerNumericId: number,
): Promise<ResellerLicense> {
  const LicenseId = await assignNumeric("licenses", p.Id);
  const h = hashUlid(p.Id);

  return {
    LicenseId,
    LicenseKey: p.Serial,
    PrefixValue: prefixFromSerial(p.Serial),
    ResellerId: resellerNumericId,
    IssuedByUserId: 1,
    IssuerActorType: "admin",
    TierName: TIER_NAMES[h % TIER_NAMES.length],
    EnvironmentName: ENVIRONMENT_NAMES[h % ENVIRONMENT_NAMES.length],
    ProductVersion: p.Serial,
    Status: STATUS_LABEL[p.Status],
    IssuedAt: p.IssuedAt,
    ExpiresAt: p.ExpiresAt ?? "",
    RevokedAt: isRevoked(p) ? p.UpdatedAt : "",
    RevokeReason: isRevoked(p) ? "See admin audit log" : "",
    Version: p.Version,
  };
}

async function fetchPreviewResellerLicenseList(
  resellerId: number,
  signal?: AbortSignal,
): Promise<ResellerLicense[]> {
  const ulid = await ulidFor("resellers", resellerId);
  const isFailed = !ulid;
  if (isFailed) {
    console.warn("lara-reseller-license:preview-bridge:reseller-not-found", {
      ResellerId: resellerId,
    });

    return [];
  }
  const res = await apiClient.call("admin.licenses.list", { ResellerId: ulid }, { signal });
  const rows = await Promise.all(res.Items.map((p) => adaptPreviewResellerLicense(p, resellerId)));
  console.info("lara-reseller-license:preview-bridge:list", {
    ResellerId: resellerId,
    Ulid: ulid,
    Count: rows.length,
  });

  return rows;
}

/** GET /Api/Reseller/Licenses. Server bounds the page via ShardBinding. */
export function resellerLicenseListQueryOptions(resellerId: number, limit = 100) {
  return queryOptions({
    queryKey: ["LaraApi", "Reseller", resellerId, "Licenses", limit],
    queryFn: ({ signal }) => {
      if (getRuntimeMode().Mode === "preview") {
        return fetchPreviewResellerLicenseList(resellerId, signal);
      }

      return requestLaraApi(`/Reseller/Licenses?Limit=${limit}`, resellerLicenseSchema, { signal });
    },
    retry: false,
  });
}

async function fetchPreviewResellerLicenseDetail(
  resellerId: number,
  licenseKey: string,
  signal?: AbortSignal,
): Promise<ResellerLicense[]> {
  const rows = await fetchPreviewResellerLicenseList(resellerId, signal);
  const match = rows.filter((r) => r.LicenseKey === licenseKey);
  if (match.length === 0) {
    console.warn("lara-reseller-license:preview-bridge:detail-not-found", {
      ResellerId: resellerId,
      LicenseKey: licenseKey,
    });
  }

  return match;
}

/** GET /Api/Reseller/Licenses/{LicenseKey}. */
export function resellerLicenseDetailQueryOptions(resellerId: number, licenseKey: string) {
  return queryOptions({
    queryKey: ["LaraApi", "Reseller", resellerId, "License", licenseKey],
    queryFn: ({ signal }) => {
      if (getRuntimeMode().Mode === "preview") {
        return fetchPreviewResellerLicenseDetail(resellerId, licenseKey, signal);
      }

      return requestLaraApi(
        `/Reseller/Licenses/${encodeURIComponent(licenseKey)}`,
        resellerLicenseSchema,
        { signal },
      );
    },
    retry: false,
  });
}
