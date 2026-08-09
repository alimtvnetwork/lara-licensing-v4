/**
 * Preview fixtures: admin audit domain (Plan 16 Step 47).
 *
 * Registers one operation:
 *   - admin.audit.list (GET /api/admin/audit)
 *
 * Behaviour:
 *   * Seed rows live at `audit::<Id>` (see preview-seeds/default.ts).
 *   * Filters (all optional, AND-combined):
 *       - EventType    exact match (case-sensitive, mirrors backend
 *         `App\Enums\AuditEventType` handling).
 *       - ActorUserId  exact match on ULID.
 *       - TargetType   exact match (added in Phase D).
 *       - TargetId     exact match (added in Phase D).
 *       - Since        include entries with OccurredAt >= Since.
 *       - Until        include entries with OccurredAt <= Until.
 *   * Sort: OccurredAt DESC, then Id DESC as a stable tiebreaker so
 *     pagination is deterministic across preview reloads.
 *   * Pagination: Cursor is a numeric offset string. Page size is
 *     DEFAULT_PAGE_SIZE (25), matching admin.licenses.list /
 *     admin.quotas.list conventions.
 *   * Under the `error` seed the op rejects with
 *     ERROR_SEED_DOMAIN_CODE.audit (AuthForbidden, 403) per INV-RM-06.
 *   * No writes: audit is read-only from the admin UI. Emissions land
 *     inside other handlers in later steps.
 *
 * Every function body respects the 15-line cap. INV-RM-05 preserved:
 * live and preview callers observe the same typed response shape.
 */

import type { PreviewFixtureModule } from "./_module";
import { previewError, previewSuccess } from "./_shared";
import { list } from "@/lib/preview-store";
import { registerPreviewHandler } from "@/lib/preview-transport";
import { ERROR_SEED_DOMAIN_CODE } from "@/lib/preview-seeds/error";
import type {
  AdminAuditListRequest,
  AdminAuditListResponse,
  AuditEntry,
} from "@/generated/api/schema";

const HTTP_FORBIDDEN = 403;
const DEFAULT_PAGE_SIZE = 25;

function rejectIfErrorSeed(seed: string, requestId: string): void {
  if (seed !== "error") return;
  previewError(
    ERROR_SEED_DOMAIN_CODE.audit,
    "Preview error seed active: audit list is denied (INV-RM-06).",
    HTTP_FORBIDDEN,
    requestId,
  );
}

async function loadAllAudit(): Promise<AuditEntry[]> {
  const rows = await list<AuditEntry>("audit");

  return rows
    .map(([, v]) => v)
    .sort((a, b) => {
      const byTime = b.OccurredAt.localeCompare(a.OccurredAt);

      return byTime !== 0 ? byTime : b.Id.localeCompare(a.Id);
    });
}

function matchesFilters(e: AuditEntry, p: Record<string, any>): boolean {
  if (p.EventType && e.EventType !== p.EventType) return false;
  if (p.ActorUserId && e.ActorUserId !== p.ActorUserId) return false;

  if (p.TargetType && e.TargetType !== p.TargetType) return false;
  if (p.TargetId && e.TargetId !== p.TargetId) return false;

  if (p.Since && e.OccurredAt < p.Since) return false;
  if (p.Until && e.OccurredAt > p.Until) return false;

  if (p.Query) {
    const q = p.Query.toLowerCase();
    if (!e.EventType.toLowerCase().includes(q) && !e.TargetId.toLowerCase().includes(q))
      return false;
  }

  return true;
}

function paginate(items: AuditEntry[], cursor: string | null | undefined): AdminAuditListResponse {
  const start = cursor ? Number.parseInt(cursor, 10) || 0 : 0;
  const end = start + DEFAULT_PAGE_SIZE;
  const slice = items.slice(start, end);
  const next = end < items.length ? String(end) : null;

  return { Items: slice, Cursor: next, Total: items.length };
}

const mod: PreviewFixtureModule = {
  name: "audit",
  operations: ["admin.audit.list"],
  register(): void {
    registerPreviewHandler("admin.audit.list", async (ctx): Promise<AdminAuditListResponse> => {
      rejectIfErrorSeed(ctx.Seed, ctx.RequestId);
      const all = await loadAllAudit();
      const filtered = all.filter((e) => matchesFilters(e, ctx.Params));
      console.info("preview-fixtures:admin.audit.list", {
        RequestId: ctx.RequestId,
        Total: filtered.length,
        Query: (ctx.Params as any).Query ?? null,
        Since: ctx.Params.Since ?? null,
        Until: ctx.Params.Until ?? null,
      });

      return previewSuccess<"admin.audit.list">(paginate(filtered, ctx.Params.Cursor));
    });
  },
};

export default mod;
