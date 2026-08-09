// Plan 09 step 29. Admin dashboard Recent Activity feed.
//
// Root cause this addresses: the admin overview showed four KPI totals
// but no "what happened recently", so operators had no signal that a
// mutation had just landed (revoke, restore, publish, etc.). Support
// resorted to opening `admin.audit.tsx` on every page load. This feed
// consumes the same `auditLogsQueryOptions` transport, caps at 15 rows,
// and renders through the shared <Timeline /> primitive so tone maps
// stay uniform with the license ledger.

import { useQuery } from "@tanstack/react-query";

import { Timeline, type TimelineEntry, type TimelineTone } from "../ui/timeline";
import { EmptyState } from "../ui/empty-state";
import { auditLogsQueryOptions, type AuditLog } from "../../lib/lara-audit";
import { formatLaraApiError } from "../../lib/lara-api-error";

const RECENT_ACTIVITY_LIMIT = 15;

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

function targetLabel(row: AuditLog): string {
  if (row.TargetId === null) return row.TargetType;

  return `${row.TargetType} #${row.TargetId}`;
}

function toEntries(rows: readonly AuditLog[]): TimelineEntry[] {
  return rows.slice(0, RECENT_ACTIVITY_LIMIT).map((row) => ({
    id: row.AuditLogId,
    title: `${row.Action} - ${targetLabel(row)}`,
    description: `${actorLabel(row)} - Request ${row.RequestId}`,
    timestamp: row.CreatedAt,
    tone: toneFor(row.Action),
  }));
}

export function RecentActivity() {
  const query = useQuery(auditLogsQueryOptions({ Limit: RECENT_ACTIVITY_LIMIT }));

  return (
    <section
      className="mt-6 rounded-md border border-border bg-card p-6"
      data-shell-region="admin-activity"
      data-ui="admin-recent-activity"
    >
      <header className="mb-4 flex items-baseline justify-between gap-4">
        <h2 className="text-lg font-semibold">Recent activity</h2>
        <p className="text-xs text-muted-foreground">
          Latest {RECENT_ACTIVITY_LIMIT} mutations captured by AuditWriter.
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
          ariaLabel="Recent admin activity"
          entries={toEntries(query.data ?? [])}
          emptyState={
            <EmptyState
              preset="box"
              headline="Nothing has happened yet"
              body="License, reseller, and session mutations will surface here as they land."
            />
          }
        />
      )}
    </section>
  );
}
