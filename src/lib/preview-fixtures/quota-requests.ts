/**
 * Preview fixtures: admin quota-requests domain (Plan 18 Phase D).
 *
 * Registers:
 *   - admin.quotaRequests.list (GET /api/admin/quota-requests/all)
 *
 * Behaviour:
 *   * Reads deterministic seed rows from preview-store domain "quota-requests".
 *   * Results are sorted newest-first by SubmittedAt.
 *   * Honours `Query` (ResellerSlug substring, case-insensitive) and `Status`.
 *
 * INV-RM-05: preview and live callers observe the same typed shape.
 */

import type { PreviewFixtureModule } from "./_module";
import { previewError, previewSuccess } from "./_shared";
import { list } from "@/lib/preview-store";
import { registerPreviewHandler } from "@/lib/preview-transport";
import { ERROR_SEED_DOMAIN_CODE } from "@/lib/preview-seeds/error";
import type { AdminQuotaRequestRow } from "@/generated/api/schema";

const HTTP_UNPROCESSABLE = 422;

function rejectIfErrorSeed(seed: string, requestId: string): void {
  if (seed !== "error") return;
  previewError(
    ERROR_SEED_DOMAIN_CODE["quota-requests"],
    "Preview error seed active: quota-requests list is denied (INV-RM-06).",
    HTTP_UNPROCESSABLE,
    requestId,
  );
}

function matchesFilters(r: AdminQuotaRequestRow, params: Record<string, any>): boolean {
  if (params.Status && r.Status !== params.Status) return false;
  const isFailed = !params.Query;
  if (isFailed) return true;
  const q = params.Query.toLowerCase();

  return r.ResellerSlug.toLowerCase().includes(q);
}

const mod: PreviewFixtureModule = {
  name: "quota-requests",
  operations: ["admin.quotaRequests.list"],
  register(): void {
    registerPreviewHandler(
      "admin.quotaRequests.list",
      async (ctx): Promise<AdminQuotaRequestRow[]> => {
        rejectIfErrorSeed(ctx.Seed, ctx.RequestId);
        const raw = await list<AdminQuotaRequestRow>("quota-requests");
        const rows = raw
          .map(([, v]) => v)
          .filter((r) => matchesFilters(r, ctx.Params))
          .sort((a, b) => b.SubmittedAt.localeCompare(a.SubmittedAt));

        console.info("preview-fixtures:admin.quotaRequests.list", {
          RequestId: ctx.RequestId,
          Total: rows.length,
          Query: (ctx.Params as any).Query ?? null,
          Status: (ctx.Params as any).Status ?? null,
        });

        return previewSuccess<"admin.quotaRequests.list">(rows);
      },
    );
  },
};

export default mod;
