/**
 * Preview fixtures: admin resellers domain (Plan 18 Phase D).
 *
 * Registers one operation:
 *   - admin.resellers.list (GET /api/admin/resellers)
 *
 * Behaviour:
 *   * Enumerates seeded resellers from preview-store domain "resellers".
 *   * Supports deterministic pagination by Name ASC (Id ASC tiebreaker).
 *   * Honours "Query" filter (case-insensitive Name match).
 *   * Under the "error" seed rejects with ERROR_SEED_DOMAIN_CODE.resellers
 *     (AuthForbidden, 403) per INV-RM-06.
 *
 * INV-RM-05 preserved: preview + live callers observe the same typed
 * AdminResellersListResponse shape.
 */

import type { PreviewFixtureModule } from "./_module";
import { previewError, previewSuccess } from "./_shared";
import { list } from "@/lib/preview-store";
import { registerPreviewHandler } from "@/lib/preview-transport";
import { ERROR_SEED_DOMAIN_CODE } from "@/lib/preview-seeds/error";
import type {
  AdminReseller,
  AdminResellersListRequest,
  AdminResellersListResponse,
} from "@/generated/api/schema";

const HTTP_FORBIDDEN = 403;
const DEFAULT_PAGE_SIZE = 25;

function rejectIfErrorSeed(seed: string, requestId: string): void {
  if (seed !== "error") return;
  previewError(
    ERROR_SEED_DOMAIN_CODE.resellers,
    "Preview error seed active: reseller list is denied (INV-RM-06).",
    HTTP_FORBIDDEN,
    requestId,
  );
}

async function loadAllResellers(): Promise<AdminReseller[]> {
  const rows = await list<AdminReseller>("resellers");

  return rows
    .map(([, v]) => v)
    .sort((a, b) => {
      const byName = a.Name.localeCompare(b.Name);

      return byName !== 0 ? byName : a.Id.localeCompare(b.Id);
    });
}

function matchesFilters(r: AdminReseller, p: AdminResellersListRequest): boolean {
  if (!p.Query) return true;
  const q = p.Query.toLowerCase();

  return r.Name.toLowerCase().includes(q) || r.Slug.toLowerCase().includes(q);
}

function paginate(
  items: AdminReseller[],
  cursor: string | null | undefined,
): AdminResellersListResponse {
  const start = cursor ? Number.parseInt(cursor, 10) || 0 : 0;
  const end = start + DEFAULT_PAGE_SIZE;
  const slice = items.slice(start, end);
  const next = end < items.length ? String(end) : null;

  return { Items: slice, Cursor: next, Total: items.length };
}

const mod: PreviewFixtureModule = {
  name: "resellers",
  operations: ["admin.resellers.list"],
  register(): void {
    registerPreviewHandler(
      "admin.resellers.list",
      async (ctx): Promise<AdminResellersListResponse> => {
        rejectIfErrorSeed(ctx.Seed, ctx.RequestId);
        const all = await loadAllResellers();
        const params = ctx.Params as AdminResellersListRequest;
        const filtered = all.filter((r) => matchesFilters(r, params));
        console.info("preview-fixtures:admin.resellers.list", {
          RequestId: ctx.RequestId,
          Total: filtered.length,
          Query: params.Query ?? null,
        });

        return previewSuccess<"admin.resellers.list">(paginate(filtered, params.Cursor));
      },
    );
  },
};

export default mod;
