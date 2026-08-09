import { queryOptions } from "@tanstack/react-query";
import { z } from "zod";

import { apiClient } from "./api-client";
import { HttpMethodType, requestLaraApi } from "./lara-api-client";
import {
  EnvironmentIdType,
  ENVIRONMENT_IDS,
  environmentIdSchema,
  type EnvironmentIdValue,
} from "./lara-environment";
import { assignNumeric, numericFor, ulidFor } from "./preview-id-map";
import { getRuntimeMode } from "./runtime-mode";
import type { License as PreviewLicense } from "@/generated/api/schema";

export { EnvironmentIdType, ENVIRONMENT_IDS };
export type { EnvironmentIdValue };

/**
 * Closed sets are normative in:
 *   - spec/21-app/05-license-categories.md §Canonical set (Daily..Key, ordinals 1..7; AC-CAT-005)
 *   - spec/21-app/43-license-tiers.md §2 (Tier1..Unlimited, ordinals 1..4; AC-LT-002)
 *   - spec/21-app/44-environments.md §2 (owned by src/lib/lara-environment.ts)
 * The FK id equals the ordinal per those owner files. The client MUST reject
 * out-of-set ids BEFORE POST /Licenses fires so the ValidationFailed (400)
 * response is observable on both sides of the wire without spending a server
 * round-trip.
 */
export const LicenseCategoryIdType = {
  Daily: 1,
  Weekly: 2,
  Monthly: 3,
  Yearly: 4,
  Lifetime: 5,
  Dev: 6,
  Key: 7,
} as const;
export type LicenseCategoryIdValue =
  (typeof LicenseCategoryIdType)[keyof typeof LicenseCategoryIdType];
export const LICENSE_CATEGORY_IDS = [1, 2, 3, 4, 5, 6, 7] as const;

export const LicenseTierIdType = { Tier1: 1, Tier2: 2, Tier3: 3, Unlimited: 4 } as const;
export type LicenseTierIdValue = (typeof LicenseTierIdType)[keyof typeof LicenseTierIdType];
export const LICENSE_TIER_IDS = [1, 2, 3, 4] as const;

const licenseCategoryIdSchema = z.union([
  z.literal(1),
  z.literal(2),
  z.literal(3),
  z.literal(4),
  z.literal(5),
  z.literal(6),
  z.literal(7),
]);

const licenseTierIdSchema = z.union([z.literal(1), z.literal(2), z.literal(3), z.literal(4)]);

export const licenseSchema = z.object({
  LicenseId: z.number().int().positive(),
  LicenseCategoryId: licenseCategoryIdSchema,
  LicenseTierId: licenseTierIdSchema,
  EnvironmentId: environmentIdSchema,
  LicensePackageId: z.number().int().positive().nullable().optional(),
  ResellerId: z.number().int().positive().nullable().optional(),
  IssuedByUserId: z.number().int().positive(),
  ProductVersion: z.string().min(1),
  IsActive: z.boolean(),
  IssuedAt: z.string().datetime(),
  ExpiresAt: z.string().datetime().nullable().optional(),
  UserCount: z.number().int().nonnegative().nullable().optional(),
  MachineCount: z.number().int().nonnegative().nullable().optional(),
  IsSingleUse: z.boolean(),
});

export type License = z.infer<typeof licenseSchema>;

export const licenseCreateSchema = z.object({
  LicenseCategoryId: licenseCategoryIdSchema,
  LicenseTierId: licenseTierIdSchema,
  EnvironmentId: environmentIdSchema,
  LicensePackageId: z.number().int().positive().optional(),
  ResellerId: z.number().int().positive().optional(),
  ProductVersion: z.string().trim().min(1).max(64),
  UserCount: z.number().int().positive().optional(),
  MachineCount: z.number().int().positive().optional(),
  IsSingleUse: z.boolean(),
});

export type LicenseCreateInput = z.infer<typeof licenseCreateSchema>;

/**
 * POST /Licenses is mutating; Idempotency-Key is REQUIRED per
 * spec/21-app/11-api-contracts/08-idempotency-envelope-hardening.md so a
 * network retry cannot double-consume reseller quota.
 */
export async function createLicense(
  input: LicenseCreateInput,
  idempotencyKey: string,
): Promise<License> {
  const [created] = await requestLaraApi("/Licenses", licenseSchema, {
    method: HttpMethodType.Post,
    body: input,
    headers: { "Idempotency-Key": idempotencyKey },
  });

  return created;
}

export const licenseUpdateSchema = z
  .object({
    LicenseCategoryId: licenseCategoryIdSchema.optional(),
    LicensePackageId: z.number().int().positive().nullable().optional(),
    ProductVersion: z.string().trim().min(1).max(64).optional(),
    IsActive: z.boolean().optional(),
    ExpiresAt: z.string().datetime().nullable().optional(),
    UserCount: z.number().int().positive().nullable().optional(),
    MachineCount: z.number().int().positive().nullable().optional(),
    IsSingleUse: z.boolean().optional(),
  })
  .refine((value) => Object.keys(value).length > 0, {
    message: "At least one field is required.",
  });

export type LicenseUpdateInput = z.infer<typeof licenseUpdateSchema>;

/**
 * `DELETE /Licenses/{LicenseId}` response envelope.
 *
 * `QuotaRestored` and `RestoreSkippedReason` mirror the audit payload
 * fields defined by spec/21-app/48-quota-restore-on-revoke.md §2 step 7
 * and §5. Both are optional so older deployments (pre-spec-48 servers)
 * that only return `{LicenseId, IsDeleted}` continue to parse. The
 * `RestoreSkippedReason` enum is closed and MUST match §1 verbatim; any
 * server value outside this set is a wire contract break, not a UI bug.
 */
export const licenseRestoreSkippedReasonSchema = z.enum([
  "AdminIssued",
  "ClosedPeriod",
  "TimeExpired",
  "AlreadyRestored",
]);
export type LicenseRestoreSkippedReason = z.infer<typeof licenseRestoreSkippedReasonSchema>;

export const licenseDeleteSchema = z.object({
  LicenseId: z.number().int().positive(),
  IsDeleted: z.literal(true),
  QuotaRestored: z.boolean().optional(),
  RestoreSkippedReason: licenseRestoreSkippedReasonSchema.optional(),
});

export type LicenseDeleteResult = z.infer<typeof licenseDeleteSchema>;

export interface LicenseWithEtag {
  license: License;
  /**
   * Quoted strong ETag exactly as emitted by `GET /Licenses/{LicenseId}`
   * per spec/21-app/11-api-contracts/09-concurrency-control.md §ETag
   * shape. `undefined` only when the server omitted the header (older
   * deployments); mutations against a row with no ETag will surface
   * `428 PreconditionRequired` at the wire, which is the correct
   * behaviour: the client MUST NOT invent an ETag.
   */
  etag: string | undefined;
}

function hashUlid(id: string): number {
  let h = 0;
  for (let i = 0; i < id.length; i++) h = (h * 31 + id.charCodeAt(i)) | 0;

  return Math.abs(h);
}

interface PreviewClosedSetIds {
  LicenseCategoryId: LicenseCategoryIdValue;
  LicenseTierId: LicenseTierIdValue;
  EnvironmentId: EnvironmentIdValue;
}

function derivePreviewClosedSetIds(ulid: string): PreviewClosedSetIds {
  const h = hashUlid(ulid);

  return {
    LicenseCategoryId: ((h % 7) + 1) as LicenseCategoryIdValue,
    LicenseTierId: ((h % 4) + 1) as LicenseTierIdValue,
    EnvironmentId: ((h % 3) + 1) as EnvironmentIdValue,
  };
}

function buildAdaptedLicense(
  p: PreviewLicense,
  ids: { LicenseId: number; ResellerId: number | null; closed: PreviewClosedSetIds },
): License {
  return {
    LicenseId: ids.LicenseId,
    ...ids.closed,
    LicensePackageId: null,
    ResellerId: ids.ResellerId,
    IssuedByUserId: 1,
    ProductVersion: p.Serial,
    IsActive: p.Status === "active",
    IssuedAt: p.IssuedAt,
    ExpiresAt: p.ExpiresAt ?? null,
    UserCount: null,
    MachineCount: null,
    IsSingleUse: p.MaxActivations === 1,
  };
}

async function adaptPreviewLicense(p: PreviewLicense): Promise<License> {
  const LicenseId = await assignNumeric("licenses", p.Id);
  const ResellerId = p.ResellerId ? await assignNumeric("resellers", p.ResellerId) : null;
  const closed = derivePreviewClosedSetIds(p.Id);

  return buildAdaptedLicense(p, { LicenseId, ResellerId, closed });
}

function logPreviewBridge(license: License, ulid: string, version: number, status: string): void {
  console.info("lara-license:preview-bridge", {
    LicenseId: license.LicenseId,
    Ulid: ulid,
    Version: version,
    Status: status,
  });
}

async function fetchPreviewLicenseWithEtag(
  licenseId: number,
  signal?: AbortSignal,
): Promise<LicenseWithEtag> {
  const ulid = await ulidFor("licenses", licenseId);
  const isFailed = !ulid;
  if (isFailed) {
    console.warn("lara-license:preview-bridge:not-found", { LicenseId: licenseId });
    throw new Error(`License ${licenseId} not found in preview id-map.`);
  }
  const res = await apiClient.call("admin.licenses.show", { Id: ulid }, { signal });
  const license = await adaptPreviewLicense(res);
  logPreviewBridge(license, ulid, res.Version, res.Status);

  return { license, etag: String(res.Version) };
}

async function fetchLicenseWithEtag(
  licenseId: number,
  signal?: AbortSignal,
): Promise<LicenseWithEtag> {
  if (getRuntimeMode().Mode === "preview") return fetchPreviewLicenseWithEtag(licenseId, signal);
  let etag: string | undefined;
  const [license] = await requestLaraApi(`/Licenses/${licenseId}`, licenseSchema, {
    signal,
    onResponseHeaders: (headers) => {
      const value = headers.get("ETag");
      if (typeof value === "string" && value.length > 0) etag = value;
    },
  });

  return { license, etag };
}

// Reserved for the list-bridge in Plan 17 Step 7 (row-level `numericFor` lookups).
void numericFor;

export async function getLicense(licenseId: number, signal?: AbortSignal): Promise<License> {
  const { license } = await fetchLicenseWithEtag(licenseId, signal);

  return license;
}

/**
 * `PATCH /Licenses/{LicenseId}` is in-scope per
 * spec/21-app/11-api-contracts/09-concurrency-control.md §Scope, so
 * `If-Match` is REQUIRED. The client MUST refuse to fire the request
 * without one; skipping it would guarantee a `428 PreconditionRequired`
 * round-trip and burn an `X-Request-Id` for a preventable failure.
 */
export async function updateLicense(
  licenseId: number,
  input: LicenseUpdateInput,
  idempotencyKey: string,
  ifMatch: string,
): Promise<License> {
  const [updated] = await requestLaraApi(`/Licenses/${licenseId}`, licenseSchema, {
    method: HttpMethodType.Patch,
    body: input,
    headers: { "Idempotency-Key": idempotencyKey, "If-Match": ifMatch },
  });

  return updated;
}

/**
 * `DELETE /Licenses/{LicenseId}` (revoke) is in-scope per
 * spec/21-app/11-api-contracts/09-concurrency-control.md §Scope, so
 * `If-Match` is REQUIRED. See the `updateLicense` docblock for the
 * client-side refusal rationale.
 */
export async function deleteLicense(
  licenseId: number,
  idempotencyKey: string,
  ifMatch: string,
): Promise<LicenseDeleteResult> {
  const [deleted] = await requestLaraApi(`/Licenses/${licenseId}`, licenseDeleteSchema, {
    method: HttpMethodType.Delete,
    headers: { "Idempotency-Key": idempotencyKey, "If-Match": ifMatch },
  });

  return deleted;
}

export const licenseQueryOptions = (licenseId: number) =>
  queryOptions({
    queryKey: ["LaraApi", "License", licenseId],
    queryFn: ({ signal }) => fetchLicenseWithEtag(licenseId, signal),
    retry: false,
  });
