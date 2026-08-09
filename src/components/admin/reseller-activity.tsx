// Plan 09 step 34. Reseller detail activity timeline.
//
// Root cause this addresses: admin.resellers.$resellerId.tsx showed the
// edit form, prefix manager, and quota section but no audit trail, so
// support had to open admin.audit.tsx and hand-filter by
// TargetType=Reseller + TargetId=<id> to answer "who last touched this
// reseller, when, with which RequestId". Same pattern as
// license-ledger.tsx (v0.310.0) so tone maps stay uniform.

import { useQuery } from "@tanstack/react-query";

import { Timeline, type TimelineEntry, type TimelineTone } from "../ui/timeline";
import { EmptyState } from "../ui/empty-state";
import { auditLogsQueryOptions, type AuditLog } from "../../lib/lara-audit";
import { formatLaraApiError } from "../../lib/lara-api-error";

const RESELLER_ACTIVITY_LIMIT = 50;

function toneFor(action: string): TimelineTone {
  const lower = action.toLowerCase();
  if (lower.includes("revoke") || lower.includes("delete") || lower.includes("destroy"))
    return "danger";
  if (lower.includes("restore") || lower.includes("issue") || lower.includes("create"))
    return "success";
  if (lower.includes("update") || lower.includes("edit")) return "primary";
  if (lower.includes("warn") || lower.includes("expire")) return "warning";

  return "neutral";
}

function actorLabel(row: AuditLog): string {
  if (row.ActorId === null) return row.ActorType;

  return `${row.ActorType} #${row.ActorId}`;
}

/**
 * Wire regression guard: we filter server-side by TargetType=Reseller
 * but a widening of that filter must never leak unrelated targets into
 * this reseller's history. Client-side TargetId equality is cheap.
 */
function belongsToReseller(row: AuditLog, resellerId: number): boolean {
  return row.TargetType === "Reseller" && row.TargetId === String(resellerId);
}

function toEntries(rows: readonly AuditLog[], resellerId: number): TimelineEntry[] {
  return rows
    .filter((row) => belongsToReseller(row, resellerId))
    .slice(0, RESELLER_ACTIVITY_LIMIT)
    .map((row) => ({
      id: row.AuditLogId,
      title: `${row.Action}`,
      description: `${actorLabel(row)} - Request ${row.RequestId}`,
      timestamp: row.CreatedAt,
      tone: toneFor(row.Action),
    }));
}

export function ResellerActivity({ resellerId }: { resellerId: number }) {
  const query = useQuery(
    auditLogsQueryOptions({ TargetType: "Reseller", Limit: RESELLER_ACTIVITY_LIMIT }),
  );

  return (
    <section
      className="mt-8 rounded-md border border-border bg-card p-6"
      data-shell-region="reseller-activity"
      data-ui="reseller-activity"
    >
      <header className="mb-4 flex items-baseline justify-between gap-4">
        <h2 className="text-lg font-semibold">Activity</h2>
        <p className="text-xs text-muted-foreground">
          Latest {RESELLER_ACTIVITY_LIMIT} mutations captured by AuditWriter for this reseller.
        </p>
      </header>
      {query.isPending ? (
        <p className="text-sm text-muted-foreground">Loading activity...</p>
      ) : query.isError ? (
        <p role="alert" className="text-sm text-destructive">
          {formatLaraApiError(query.error)}
        </p>
      ) : (
        <Timeline
          ariaLabel="Reseller activity"
          entries={toEntries(query.data ?? [], resellerId)}
          emptyState={
            <EmptyState
              preset="box"
              headline="No activity yet"
              body="Reseller mutations (updates, prefix changes, quota grants) will surface here as they land."
            />
          }
        />
      )}
    </section>
  );
}
