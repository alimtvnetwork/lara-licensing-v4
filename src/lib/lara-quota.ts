import { queryOptions } from "@tanstack/react-query";
import { z } from "zod";

import { apiClient } from "./api-client";
import { HttpMethodType, requestLaraApi } from "./lara-api-client";
import { ApiErrorCodeType, LaraApiError } from "./lara-api-error";
import { assignNumeric, ulidFor } from "./preview-id-map";
import { getRuntimeMode } from "./runtime-mode";

import type { Quota as PreviewQuota } from "@/generated/api/schema";

/**
 * Reseller quota + quota-request client per:
 *   - spec/21-app/41-reseller-quotas.md v1.0.0 (ResellerQuotas model, ledger)
 *   - spec/21-app/42-quota-requests.md v1.0.0 (approval workflow state machine)
 *   - spec/21-app/11-api-contracts/04-admin-contracts.md v1.2.0
 *     §Reseller Quotas surfaces (GET /Resellers/{id}/Quotas and /QuotaLedger)
 *   - spec/21-app/11-api-contracts/05-quota-request-contracts.md v1.0.0
 *     §Endpoints (submit/list/get/approve/deny/cancel + POST .../Quotas/{catId}/Adjust)
 *   - spec/21-app/40-permissions.md §2 (Quotas.Request/Approve/Adjust)
 *   - spec/21-app/12-error-taxonomy.md v1.9.0 (QuotaExhausted, QuotaCategoryUnauthorized,
 *     QuotaLedgerConflict, ConflictState)
 *
 * All mutating rows require an Idempotency-Key per
 * spec/21-app/08-idempotency-envelope-hardening.md; a replay MUST re-emit the
 * stored envelope byte-for-byte and MUST NOT double-execute the atomic ledger
 * insert (AC-API-QR-003). Verify rows are OUT of scope for this module.
 */

/** Closed set from spec/21-app/42-quota-requests.md §State machine (rows 1..4). */
export const QuotaRequestStatusType = {
  Pending: "Pending",
  Approved: "Approved",
  Denied: "Denied",
  Cancelled: "Cancelled",
} as const;
export type QuotaRequestStatusValue =
  (typeof QuotaRequestStatusType)[keyof typeof QuotaRequestStatusType];

/** Closed set from spec/21-app/41-reseller-quotas.md §Ledger. */
export const QuotaLedgerActionType = {
  QuotaConsumed: "QuotaConsumed",
  QuotaRestored: "QuotaRestored",
  QuotaAdjusted: "QuotaAdjusted",
} as const;
export type QuotaLedgerActionValue =
  (typeof QuotaLedgerActionType)[keyof typeof QuotaLedgerActionType];

export const resellerQuotaSchema = z.object({
  ResellerId: z.number().int().positive(),
  LicenseCategoryId: z.number().int().positive(),
  LicenseTierId: z.number().int().positive(),
  LicensesGranted: z.number().int().nonnegative(),
  LicensesConsumed: z.number().int().nonnegative(),
  LicensesRemaining: z.number().int(),
  PeriodStart: z.string().datetime(),
  PeriodEnd: z.string().datetime().nullable().optional(),
});
export type ResellerQuota = z.infer<typeof resellerQuotaSchema>;

export const quotaLedgerRowSchema = z.object({
  LedgerId: z.number().int().positive(),
  ResellerId: z.number().int().positive(),
  LicenseCategoryId: z.number().int().positive(),
  LicenseTierId: z.number().int().positive(),
  LedgerAction: z.enum(["QuotaConsumed", "QuotaRestored", "QuotaAdjusted"]),
  Delta: z.number().int(),
  LicenseId: z.number().int().positive().nullable().optional(),
  QuotaRequestId: z.number().int().positive().nullable().optional(),
  RequestId: z.string().min(1),
  ActorUserId: z.number().int().positive(),
  CreatedAt: z.string().datetime(),
});
export type QuotaLedgerRow = z.infer<typeof quotaLedgerRowSchema>;

export const quotaRequestSchema = z.object({
  QuotaRequestId: z.number().int().positive(),
  ResellerId: z.number().int().positive(),
  LicenseCategoryId: z.number().int().positive(),
  LicenseTierId: z.number().int().positive(),
  RequestedDelta: z.number().int().positive(),
  ApprovedDelta: z.number().int().positive().nullable().optional(),
  Status: z.enum(["Pending", "Approved", "Denied", "Cancelled"]),
  SubmittedByUserId: z.number().int().positive(),
  SubmittedAt: z.string().datetime(),
  DecidedByUserId: z.number().int().positive().nullable().optional(),
  DecidedAt: z.string().datetime().nullable().optional(),
  DenialReason: z.string().min(1).max(500).nullable().optional(),
  Justification: z.string().min(1).max(1000).nullable().optional(),
});
export type QuotaRequest = z.infer<typeof quotaRequestSchema>;

export const quotaAdjustmentResultSchema = z.object({
  ResellerId: z.number().int().positive(),
  LicenseCategoryId: z.number().int().positive(),
  LicenseTierId: z.number().int().positive(),
  Delta: z
    .number()
    .int()
    .refine((value) => value !== 0, "Delta MUST be non-zero"),
  LicensesGranted: z.number().int().nonnegative(),
  LicensesConsumed: z.number().int().nonnegative(),
  LicensesRemaining: z.number().int(),
  LedgerId: z.number().int().positive(),
  ActorUserId: z.number().int().positive(),
  CreatedAt: z.string().datetime(),
});
export type QuotaAdjustmentResult = z.infer<typeof quotaAdjustmentResultSchema>;

/**
 * Admin read: GET /Resellers/{ResellerId}/Quotas per 04-admin-contracts.md.
 * Reseller Reads: same URL, filtered server-side to caller's own resellerId
 * via row-scope (see spec/21-app/40-permissions.md §Row-scope).
 *
 * Plan 17 Step 8: in `Mode=preview` this branches through
 * `apiClient.call("admin.quotas.list")` and adapts the ULID-keyed
 * modern `Quota` shape into the legacy positive-int `ResellerQuota`
 * shape so the reseller dashboard and quota-adjust forms mount.
 */
function hashUlidForQuota(id: string): number {
  let h = 0;
  for (let i = 0; i < id.length; i++) h = (h * 31 + id.charCodeAt(i)) | 0;

  return Math.abs(h);
}

function derivePreviewQuotaFks(ulid: string): {
  LicenseCategoryId: number;
  LicenseTierId: number;
} {
  const h = hashUlidForQuota(ulid);

  return {
    LicenseCategoryId: (h % 7) + 1,
    LicenseTierId: (h % 4) + 1,
  };
}

function adaptPreviewQuota(p: PreviewQuota, resellerId: number): ResellerQuota {
  const fks = derivePreviewQuotaFks(p.Id);

  return {
    ResellerId: resellerId,
    LicenseCategoryId: fks.LicenseCategoryId,
    LicenseTierId: fks.LicenseTierId,
    LicensesGranted: p.Allocated,
    LicensesConsumed: p.Used,
    LicensesRemaining: Math.max(0, p.Allocated - p.Used),
    PeriodStart: p.UpdatedAt,
    PeriodEnd: null,
  };
}

async function fetchPreviewResellerQuotas(
  resellerId: number,
  signal?: AbortSignal,
): Promise<ResellerQuota[]> {
  const ulid = await ulidFor("resellers", resellerId);
  const isFailed = !ulid;
  if (isFailed) {
    console.warn("lara-quota:preview-bridge:reseller-not-found", { ResellerId: resellerId });

    return [];
  }
  const res = await apiClient.call("admin.quotas.list", { ResellerId: ulid }, { signal });
  await assignNumeric("resellers", ulid);
  const rows = res.Items.map((p) => adaptPreviewQuota(p, resellerId));
  console.info("lara-quota:preview-bridge:list", {
    ResellerId: resellerId,
    Ulid: ulid,
    Count: rows.length,
  });

  return rows;
}

export function resellerQuotasQueryOptions(resellerId: number, pageSize = 100) {
  return queryOptions({
    queryKey: ["LaraApi", "Resellers", resellerId, "Quotas", pageSize],
    queryFn: ({ signal }) => {
      if (getRuntimeMode().Mode === "preview") {
        return fetchPreviewResellerQuotas(resellerId, signal);
      }

      return requestLaraApi(
        `/Resellers/${resellerId}/Quotas?PageSize=${pageSize}`,
        resellerQuotaSchema,
        { signal },
      );
    },
    retry: false,
  });
}

export function quotaLedgerQueryOptions(resellerId: number, pageSize = 100) {
  return queryOptions({
    queryKey: ["LaraApi", "Resellers", resellerId, "QuotaLedger", pageSize],
    queryFn: ({ signal }) =>
      requestLaraApi(
        `/Resellers/${resellerId}/QuotaLedger?PageSize=${pageSize}`,
        quotaLedgerRowSchema,
        { signal },
      ),
    retry: false,
  });
}

export const quotaRequestSubmitSchema = z.object({
  LicenseCategoryId: z.number().int().positive(),
  LicenseTierId: z.number().int().positive(),
  RequestedDelta: z.number().int().min(1).max(10000),
  Justification: z.string().trim().min(1).max(1000).optional(),
});
export type QuotaRequestSubmitInput = z.infer<typeof quotaRequestSubmitSchema>;

/**
 * Submit: POST /Resellers/{ResellerId}/QuotaRequests. Mutating, so
 * Idempotency-Key is REQUIRED per 08-idempotency-envelope-hardening.md.
 * Emitting the same key returns the stored Pending row without a second
 * AuditLogs insert (AC-API-QR-003).
 */
export async function submitQuotaRequest(
  resellerId: number,
  input: QuotaRequestSubmitInput,
  idempotencyKey: string,
): Promise<QuotaRequest> {
  const [row] = await requestLaraApi(`/Resellers/${resellerId}/QuotaRequests`, quotaRequestSchema, {
    method: HttpMethodType.Post,
    body: input,
    headers: { "Idempotency-Key": idempotencyKey },
  });

  return row;
}

export function quotaRequestListQueryOptions(resellerId: number, status?: QuotaRequestStatusValue) {
  const query = status ? `?Status=${status}` : "";

  return queryOptions({
    queryKey: ["LaraApi", "Resellers", resellerId, "QuotaRequests", status ?? "All"],
    queryFn: ({ signal }) =>
      requestLaraApi(`/Resellers/${resellerId}/QuotaRequests${query}`, quotaRequestSchema, {
        signal,
      }),
    retry: false,
  });
}

export function quotaRequestQueryOptions(requestId: number) {
  return queryOptions({
    queryKey: ["LaraApi", "QuotaRequests", requestId],
    queryFn: ({ signal }) =>
      requestLaraApi(`/QuotaRequests/${requestId}`, quotaRequestSchema, { signal }),
    retry: false,
  });
}

/**
 * Plan 09 step 43. Admin cross-shard quota-request inbox.
 *
 * Wraps `GET /Api/Admin/QuotaRequests/All` (see
 * backend/app/Http/Controllers/Admin/QuotaRequestController::indexAll):
 * server fans out across every active reseller shard, decorates each
 * row with its `ResellerSlug`, and sorts newest-first. Warnings for
 * unreachable shards live in the response `Meta` block and are
 * intentionally NOT surfaced here yet; a follow-up wires them into the
 * dashboard shard-status panel where they belong.
 */
export const adminQuotaRequestRowSchema = quotaRequestSchema.extend({
  ResellerSlug: z.string().min(1),
});
export type AdminQuotaRequestRow = z.infer<typeof adminQuotaRequestRowSchema>;

/**
 * Plan 17 Step 21: preview bridge for `adminQuotaRequestsQueryOptions`.
 * Reads deterministic seed rows from preview-store domain
 * `"quota-requests"` (seeded in `preview-seeds/default.ts`), applies
 * the optional `Status` filter, sorts newest-first by `SubmittedAt`,
 * and caps at `limit`. Preserves the live-mode response shape
 * (`AdminQuotaRequestRow[]`) so INV-RM-05 holds and no `requestLaraApi`
 * call is made in `Mode=preview` (which would fail loud per INV-RM-04).
 */

export function adminQuotaRequestsQueryOptions(
  status?: QuotaRequestStatusValue,
  limit: number = 200,
) {
  const params = new URLSearchParams();
  params.set("Limit", String(limit));
  if (status !== undefined) params.set("Status", status);
  const qs = params.toString();

  return queryOptions({
    queryKey: ["LaraApi", "Admin", "QuotaRequests", "All", status ?? "All", limit],
    queryFn: async ({ signal }) => {
      if (getRuntimeMode().Mode === "preview") {
        return apiClient.call("admin.quotaRequests.list", {}, { signal });
      }

      return requestLaraApi(`/Admin/QuotaRequests/All?${qs}`, adminQuotaRequestRowSchema, {
        signal,
      });
    },
    retry: false,
    staleTime: 15_000,
  });
}

export const quotaRequestApproveSchema = z.object({
  ApprovedDelta: z.number().int().min(1).max(10000).optional(),
});
export type QuotaRequestApproveInput = z.infer<typeof quotaRequestApproveSchema>;

/**
 * Approve a Pending quota request. Admin surface (spec 42 §Endpoints):
 * `POST /Api/Admin/QuotaRequests/{RequestId}/Approve?ResellerSlug=...`.
 *
 * Root cause note (v0.315.0): the backend `Admin\QuotaRequestController::approve`
 * calls `requireResellerSlug()` and 422s (`ValidationFailed`) when the query
 * parameter is missing. Callers MUST pass the reseller slug that owns the
 * request; the shard resolver binds on it before mutating.
 */
export async function approveQuotaRequest(
  requestId: number,
  resellerSlug: string,
  input: QuotaRequestApproveInput,
  idempotencyKey: string,
): Promise<QuotaRequest> {
  const slug = resellerSlug.trim();
  if (slug.length === 0) {
    throw new Error("approveQuotaRequest requires a non-empty resellerSlug for shard binding.");
  }
  const [row] = await requestLaraApi(
    `/QuotaRequests/${requestId}/Approve?ResellerSlug=${encodeURIComponent(slug)}`,
    quotaRequestSchema,
    {
      method: HttpMethodType.Post,
      body: input,
      headers: { "Idempotency-Key": idempotencyKey },
    },
  );

  return row;
}

export const quotaRequestDenySchema = z.object({
  Reason: z.string().trim().min(1).max(500),
});
export type QuotaRequestDenyInput = z.infer<typeof quotaRequestDenySchema>;

/**
 * Deny a Pending quota request. Admin surface (spec 42 §Endpoints):
 * `POST /Api/Admin/QuotaRequests/{RequestId}/Deny?ResellerSlug=...`.
 * The `ResellerSlug` query parameter is required by the backend for shard
 * binding; see `approveQuotaRequest` for the same requirement.
 */
export async function denyQuotaRequest(
  requestId: number,
  resellerSlug: string,
  input: QuotaRequestDenyInput,
  idempotencyKey: string,
): Promise<QuotaRequest> {
  const slug = resellerSlug.trim();
  if (slug.length === 0) {
    throw new Error("denyQuotaRequest requires a non-empty resellerSlug for shard binding.");
  }
  const [row] = await requestLaraApi(
    `/QuotaRequests/${requestId}/Deny?ResellerSlug=${encodeURIComponent(slug)}`,
    quotaRequestSchema,
    {
      method: HttpMethodType.Post,
      body: input,
      headers: { "Idempotency-Key": idempotencyKey },
    },
  );

  return row;
}

export async function cancelQuotaRequest(
  requestId: number,
  idempotencyKey: string,
): Promise<QuotaRequest> {
  const [row] = await requestLaraApi(`/QuotaRequests/${requestId}/Cancel`, quotaRequestSchema, {
    method: HttpMethodType.Post,
    body: {},
    headers: { "Idempotency-Key": idempotencyKey },
  });

  return row;
}

export const quotaAdjustSchema = z.object({
  LicenseTierId: z.number().int().positive(),
  Delta: z
    .number()
    .int()
    .refine((value) => value !== 0, "Delta MUST be non-zero"),
  Reason: z.string().trim().min(1).max(500),
});
export type QuotaAdjustInput = z.infer<typeof quotaAdjustSchema>;

/**
 * POST /Resellers/{ResellerId}/Quotas/{CategoryId}/Adjust per
 * 05-quota-request-contracts.md §Endpoints. Signed non-zero Delta;
 * server writes a single ResellerQuotaLedger row in the SAME transaction
 * as the ResellerQuotas update (AC-API-QR-004 owner obligations 1..6).
 */
export async function adjustQuota(
  resellerId: number,
  categoryId: number,
  input: QuotaAdjustInput,
  idempotencyKey: string,
): Promise<QuotaAdjustmentResult> {
  const [row] = await requestLaraApi(
    `/Resellers/${resellerId}/Quotas/${categoryId}/Adjust`,
    quotaAdjustmentResultSchema,
    {
      method: HttpMethodType.Post,
      body: input,
      headers: { "Idempotency-Key": idempotencyKey },
    },
  );

  return row;
}

/**
 * Client-side preflight for reseller-scoped `POST /Licenses`. Mirrors the
 * exact envelope codes/HTTP statuses from
 * spec/21-app/11-api-contracts/02-license-contracts.md §Reseller quota
 * decrement (steps 3, 4; AC-API-LIC-006) so the UI surface never has to
 * distinguish a preflight decision from a server envelope: same
 * `LaraApiError` shape, same `errorCode`, same `httpStatus`.
 *
 * IMPORTANT: this is a UX preflight, not enforcement. The server remains
 * authoritative per the §Reseller quota decrement contract. When the
 * cached quota list is stale (`quotas = []` because the query has not
 * settled) we return `undefined` and let the wire trip proceed so the
 * server envelope is the observed decision.
 *
 * Throws:
 *  - `QuotaCategoryUnauthorized` (403) when no `(LicenseCategoryId,
 *    LicenseTierId)` row exists for the caller's reseller scope.
 *  - `QuotaExhausted` (409) when a matching row has `LicensesRemaining <= 0`.
 * Absolute `LicensesGranted`/`LicensesConsumed` counts stay client-local
 * and are NOT written into the thrown message, matching the
 * no-leak clause of AC-ERR-006 for the shape callers see.
 */
export function preflightLicenseQuota(
  quotas: ReadonlyArray<ResellerQuota> | undefined,
  categoryId: number,
  tierId: number,
): void {
  if (!quotas || quotas.length === 0) return;
  const row = quotas.find((q) => q.LicenseCategoryId === categoryId && q.LicenseTierId === tierId);
  const isFailed = !row;
  if (isFailed) {
    throw new LaraApiError(
      `No quota provisioned for category ${categoryId}, tier ${tierId}.`,
      ApiErrorCodeType.QuotaCategoryUnauthorized,
      403,
    );
  }
  if (row.LicensesRemaining <= 0) {
    throw new LaraApiError(
      `Quota exhausted for category ${categoryId}, tier ${tierId}.`,
      ApiErrorCodeType.QuotaExhausted,
      409,
    );
  }
}
