/**
 * Preview fixtures: admin quotas domain (Plan 16 Step 45).
 *
 * Registers two operations:
 *   - admin.quotas.list   (GET   /api/admin/quotas)
 *   - admin.quotas.update (PATCH /api/admin/quotas/:Id)  [If-Match]
 *
 * Behaviour:
 *   * Seed data lives at `quotas::<Id>`. List filters by optional
 *     `ResellerId` and paginates with the same cursor scheme used by
 *     admin.licenses.list (Cursor = numeric offset as string, page size
 *     DEFAULT_PAGE_SIZE). Results are sorted deterministically by
 *     (ResellerName, FeatureCode) so pagination is stable across calls.
 *   * Update accepts `{ Id, IfMatch, Allocated }`. `IfMatch` MUST equal
 *     the persisted `Version` or the handler returns 412
 *     PreconditionFailed (mirrors the backend hardening shipped at
 *     v0.262.0 and the licenses handler at Step 41).
 *   * Business invariant (INV-BR-quota, ties to QuotaRestored accounting
 *     shipped in v0.273.0): the new `Allocated` MUST satisfy
 *     `Allocated >= max(0, Used - Restored)`, otherwise return 422
 *     ValidationFailed. This prevents preview drift where an admin
 *     lowers Allocated below net-consumed quota.
 *   * On success Version increments by 1 and UpdatedAt is refreshed.
 *   * Missing Id returns 404 ValidationFailed (no dedicated
 *     `QuotaNotFound` code exists in lara-api-error.ts today; message
 *     carries the Id).
 *   * Under the `error` seed every op rejects with
 *     ERROR_SEED_DOMAIN_CODE.quotas per INV-RM-06.
 *
 * Function bodies obey the 15-line cap. INV-RM-05 preserved: preview
 * and live callers observe the same typed response shape.
 */

import type { PreviewFixtureModule } from "./_module";
import { previewError, previewSuccess } from "./_shared";
import { list, read, write } from "@/lib/preview-store";
import { registerPreviewHandler } from "@/lib/preview-transport";
import { ApiErrorCodeType } from "@/lib/lara-api-error";
import { ERROR_SEED_DOMAIN_CODE } from "@/lib/preview-seeds/error";
import type {
  AdminQuotaUpdateRequest,
  AdminQuotaUpdateResponse,
  AdminQuotasListRequest,
  AdminQuotasListResponse,
  Quota,
} from "@/generated/api/schema";

const HTTP_NOT_FOUND = 404;
const HTTP_PRECONDITION_FAILED = 412;
const HTTP_UNPROCESSABLE = 422;
const DEFAULT_PAGE_SIZE = 25;

function nowIso(): string {
  return new Date().toISOString();
}

function rejectIfErrorSeed(seed: string, requestId: string): void {
  if (seed !== "error") return;
  previewError(
    ERROR_SEED_DOMAIN_CODE.quotas,
    "Preview error seed active: quota calls always fail (INV-RM-06).",
    HTTP_UNPROCESSABLE,
    requestId,
  );
}

async function readQuota(id: string, requestId: string): Promise<Quota> {
  const found = await read<Quota>("quotas", id);
  const isFailed = !found;
  if (isFailed) {
    previewError(
      ApiErrorCodeType.ValidationFailed,
      `Quota ${id} not found in preview store.`,
      HTTP_NOT_FOUND,
      requestId,
    );
  }

  return found;
}

function assertVersionMatch(quota: Quota, ifMatch: string, requestId: string): void {
  if (String(quota.Version) === String(ifMatch)) return;
  console.warn("preview-fixtures:quotas:if-match-mismatch", {
    RequestId: requestId,
    QuotaId: quota.Id,
    ExpectedVersion: quota.Version,
    ProvidedIfMatch: ifMatch,
  });
  previewError(
    ApiErrorCodeType.PreconditionFailed,
    `If-Match ${ifMatch} does not match current Version ${quota.Version}.`,
    HTTP_PRECONDITION_FAILED,
    requestId,
  );
}

function assertAllocatedFloor(next: number, quota: Quota, requestId: string): void {
  const floor = Math.max(0, quota.Used - quota.Restored);
  if (next >= floor && next >= 0) return;
  console.warn("preview-fixtures:quotas:allocated-below-floor", {
    RequestId: requestId,
    QuotaId: quota.Id,
    Requested: next,
    Floor: floor,
    Used: quota.Used,
    Restored: quota.Restored,
  });
  previewError(
    ApiErrorCodeType.ValidationFailed,
    `Allocated ${next} cannot be below net consumption ${floor} (Used ${quota.Used} - Restored ${quota.Restored}).`,
    HTTP_UNPROCESSABLE,
    requestId,
  );
}

async function loadAllQuotas(): Promise<Quota[]> {
  const rows = await list<Quota>("quotas");

  return rows
    .map(([, v]) => v)
    .sort((a, b) => {
      const byReseller = a.ResellerName.localeCompare(b.ResellerName);

      return byReseller !== 0 ? byReseller : a.FeatureCode.localeCompare(b.FeatureCode);
    });
}

function matchesFilters(q: Quota, p: AdminQuotasListRequest): boolean {
  if (p.ResellerId && q.ResellerId !== p.ResellerId) return false;
  if (!p.Query) return true;
  const qry = p.Query.toLowerCase();

  return q.FeatureCode.toLowerCase().includes(qry) || q.ResellerName.toLowerCase().includes(qry);
}

function paginate(items: Quota[], cursor: string | null | undefined): AdminQuotasListResponse {
  const start = cursor ? Number.parseInt(cursor, 10) || 0 : 0;
  const end = start + DEFAULT_PAGE_SIZE;
  const slice = items.slice(start, end);
  const next = end < items.length ? String(end) : null;

  return { Items: slice, Cursor: next, Total: items.length };
}

function applyPatch(current: Quota, patch: AdminQuotaUpdateRequest): Quota {
  return {
    ...current,
    Allocated: patch.Allocated,
    Version: current.Version + 1,
    UpdatedAt: nowIso(),
  };
}

const mod: PreviewFixtureModule = {
  name: "quotas",
  operations: ["admin.quotas.list", "admin.quotas.update"],
  register(): void {
    registerPreviewHandler("admin.quotas.list", async (ctx): Promise<AdminQuotasListResponse> => {
      rejectIfErrorSeed(ctx.Seed, ctx.RequestId);
      const all = await loadAllQuotas();
      const params = ctx.Params as AdminQuotasListRequest;
      const filtered = all.filter((q) => matchesFilters(q, params));
      console.info("preview-fixtures:admin.quotas.list", {
        RequestId: ctx.RequestId,
        Total: filtered.length,
        ResellerId: params.ResellerId ?? null,
        Query: params.Query ?? null,
      });

      return previewSuccess<"admin.quotas.list">(paginate(filtered, params.Cursor));
    });

    registerPreviewHandler(
      "admin.quotas.update",
      async (ctx): Promise<AdminQuotaUpdateResponse> => {
        rejectIfErrorSeed(ctx.Seed, ctx.RequestId);
        const current = await readQuota(ctx.Params.Id, ctx.RequestId);
        assertVersionMatch(current, ctx.Params.IfMatch, ctx.RequestId);
        assertAllocatedFloor(ctx.Params.Allocated, current, ctx.RequestId);
        const next = applyPatch(current, ctx.Params);
        await write<Quota>("quotas", next.Id, next);
        console.info("preview-fixtures:admin.quotas.update", {
          RequestId: ctx.RequestId,
          QuotaId: next.Id,
          FromVersion: current.Version,
          ToVersion: next.Version,
          FromAllocated: current.Allocated,
          ToAllocated: next.Allocated,
        });

        return previewSuccess<"admin.quotas.update">(next);
      },
    );
  },
};

export default mod;
