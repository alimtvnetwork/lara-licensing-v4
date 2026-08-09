// Plan 09 step 37. User detail activity timeline.
//
// Root cause this addresses: admin.users.$userId.tsx showed roles,
// impersonation controls, and sessions but no audit trail, forcing
// support to open admin.audit.tsx and hand-filter by TargetType=User +
// TargetId=<id> to answer "who last touched this user, when, with
// which RequestId". Same shape as reseller-activity.tsx (v0.312.0)
// and license-ledger.tsx (v0.310.0) so tone maps stay uniform.

import { useQuery } from "@tanstack/react-query";

import { Timeline, type TimelineEntry, type TimelineTone } from "../ui/timeline";
import { EmptyState } from "../ui/empty-state";
import { auditLogsQueryOptions, type AuditLog } from "../../lib/lara-audit";
import { formatLaraApiError } from "../../lib/lara-api-error";

const USER_ACTIVITY_LIMIT = 50;

function toneFor(action: string): TimelineTone {
  const lower = action.toLowerCase();
  if (
    lower.includes("revoke") ||
    lower.includes("delete") ||
    lower.includes("destroy") ||
    lower.includes("disable")
  )
    return "danger";
  if (
    lower.includes("grant") ||
    lower.includes("assign") ||
    lower.includes("create") ||
    lower.includes("restore") ||
    lower.includes("enable")
  )
    return "success";
  if (lower.includes("update") || lower.includes("edit") || lower.includes("impersonate"))
    return "primary";
  if (lower.includes("warn") || lower.includes("expire")) return "warning";

  return "neutral";
}

function actorLabel(row: AuditLog): string {
  if (row.ActorId === null) return row.ActorType;

  return `${row.ActorType} #${row.ActorId}`;
}

/**
 * Wire regression guard: server filters by TargetType=User but any
 * widening must not leak unrelated targets into this user's history.
 */
function belongsToUser(row: AuditLog, userId: number): boolean {
  return row.TargetType === "User" && row.TargetId === String(userId);
}

function toEntries(rows: readonly AuditLog[], userId: number): TimelineEntry[] {
  return rows
    .filter((row) => belongsToUser(row, userId))
    .slice(0, USER_ACTIVITY_LIMIT)
    .map((row) => ({
      id: row.AuditLogId,
      title: row.Action,
      description: `${actorLabel(row)} - Request ${row.RequestId}`,
      timestamp: row.CreatedAt,
      tone: toneFor(row.Action),
    }));
}

export function UserActivity({ userId }: { userId: number }) {
  const query = useQuery(auditLogsQueryOptions({ TargetType: "User", Limit: USER_ACTIVITY_LIMIT }));

  return (
    <section
      className="mt-8 rounded-md border border-border bg-card p-6"
      data-shell-region="user-activity"
      data-ui="user-activity"
    >
      <header className="mb-4 flex items-baseline justify-between gap-4">
        <h2 className="text-lg font-semibold">Activity</h2>
        <p className="text-xs text-muted-foreground">
          Latest {USER_ACTIVITY_LIMIT} mutations captured by AuditWriter for this user.
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
          ariaLabel="User activity"
          entries={toEntries(query.data ?? [], userId)}
          emptyState={
            <EmptyState
              preset="box"
              headline="No activity yet"
              body="Role changes, session revocations, and impersonation events for this user will surface here."
            />
          }
        />
      )}
    </section>
  );
}
