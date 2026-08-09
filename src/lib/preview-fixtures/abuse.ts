/**
 * Preview fixtures: admin abuse domain (Plan 18 Phase D).
 *
 * Registers:
 *   - admin.abuse.list (GET /api/admin/abuse/events)
 *
 * Behaviour:
 *   * Reads deterministic seed rows from preview-store domain "abuse".
 *   * Results are sorted newest-first by OccurredAt.
 *   * Honours `Query` (substring match on ResellerSlug, EventType, or LicenseId).
 *
 * INV-RM-05: preview and live callers observe the same typed shape.
 */

import type { PreviewFixtureModule } from "./_module";
import { previewError, previewSuccess } from "./_shared";
import { list } from "@/lib/preview-store";
import { registerPreviewHandler } from "@/lib/preview-transport";
import { ERROR_SEED_DOMAIN_CODE } from "@/lib/preview-seeds/error";
import type {
  AbuseEvent,
  AdminAbuseListRequest,
  AdminAbuseListResponse,
} from "@/generated/api/schema";

const HTTP_UNPROCESSABLE = 422;
const DEFAULT_PAGE_SIZE = 25;

function rejectIfErrorSeed(seed: string, requestId: string): void {
  if (seed !== "error") return;
  previewError(
    ERROR_SEED_DOMAIN_CODE.abuse,
    "Preview error seed active: abuse list is denied (INV-RM-06).",
    HTTP_UNPROCESSABLE,
    requestId,
  );
}

function matchesFilters(e: AbuseEvent, p: AdminAbuseListRequest): boolean {
  if (!p.Query) return true;
  const q = p.Query.toLowerCase();
  const resellerSlug = (e.Metadata?.ResellerSlug as string | undefined) ?? "";

  return (
    e.EventType.toLowerCase().includes(q) ||
    e.Target.toLowerCase().includes(q) ||
    e.IpAddress.toLowerCase().includes(q) ||
    resellerSlug.toLowerCase().includes(q)
  );
}

const mod: PreviewFixtureModule = {
  name: "abuse",
  operations: ["admin.abuse.list"],
  register(): void {
    registerPreviewHandler("admin.abuse.list", async (ctx): Promise<AdminAbuseListResponse> => {
      rejectIfErrorSeed(ctx.Seed, ctx.RequestId);
      const raw = await list<AbuseEvent>("abuse");
      const all = raw
        .map(([, v]) => v)
        .filter((e) => matchesFilters(e, ctx.Params))
        .sort((a, b) => b.OccurredAt.localeCompare(a.OccurredAt));

      const start = (ctx.Params as any).Cursor
        ? Number.parseInt((ctx.Params as any).Cursor, 10)
        : 0;
      const slice = all.slice(start, start + DEFAULT_PAGE_SIZE);
      const next =
        start + DEFAULT_PAGE_SIZE < all.length ? String(start + DEFAULT_PAGE_SIZE) : null;

      console.info("preview-fixtures:admin.abuse.list", {
        RequestId: ctx.RequestId,
        Total: all.length,
        Query: (ctx.Params as any).Query ?? null,
      });

      return previewSuccess<"admin.abuse.list">({
        Items: slice,
        Cursor: next,
        Total: all.length,
      });
    });
  },
};

export default mod;
