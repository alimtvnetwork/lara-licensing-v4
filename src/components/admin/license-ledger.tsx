// Plan 09 step 40 (final slice). License ledger built on <Timeline />.
//
// Root cause this addresses: `admin.licenses.$licenseId.tsx` showed
// current facts + mutate actions but no history, so operators could not
// answer "when was this license last revoked / restored / touched, by
// whom, with which request id" without opening `admin.audit.tsx` and
// hand-filtering `TargetType=License` + `TargetId=<id>`. This component
// runs that exact filter through the shared `auditLogsQueryOptions`
// transport and renders it as a token-themed Timeline. Zero new
// endpoints; the backend `GET /Api/Admin/AuditLogs` transport already
// accepts these filters (see `src/lib/lara-audit.ts` §buildQuery).

import { useQuery } from "@tanstack/react-query";

import { Timeline, type TimelineEntry, type TimelineTone } from "../ui/timeline";
import { EmptyState } from "../ui/empty-state";
import { auditLogsQueryOptions, type AuditLog } from "../../lib/lara-audit";
import { formatLaraApiError } from "../../lib/lara-api-error";

interface Props {
  readonly licenseId: number;
}

/**
 * Map an audit `Action` (canonical strings emitted by `AuditWriter`, see
 * spec/21-app/47-audit-trail §Action codes) to a Timeline tone. Unknown
 * actions fall to `neutral` rather than a fabricated color so a future
 * action lands visibly plain rather than being mis-classified.
 */
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

function toEntries(rows: readonly AuditLog[]): TimelineEntry[] {
  return rows.map((row) => ({
    id: row.AuditLogId,
    title: row.Action,
    description: `${actorLabel(row)} - Request ${row.RequestId}`,
    timestamp: row.CreatedAt,
    tone: toneFor(row.Action),
  }));
}

/**
 * Small render-time guard: `AuditLog[]` may include rows for other
 * targets when the transport ever returns a wider page (e.g. filters
 * ignored on the server). Filter client-side too so a wire regression
 * cannot leak unrelated events into the license ledger.
 */
function belongsToLicense(row: AuditLog, licenseId: number): boolean {
  if (row.TargetType !== "License") return false;

  return row.TargetId === String(licenseId);
}

export function LicenseLedger({ licenseId }: Props) {
  const query = useQuery(auditLogsQueryOptions({ TargetType: "License", Limit: 50 }));

  return (
    <section className="mt-6 rounded-md border border-border bg-card p-6" data-ui="license-ledger">
      <header className="mb-4 flex items-baseline justify-between gap-4">
        <h2 className="text-lg font-semibold">Ledger</h2>
        <p className="text-xs text-muted-foreground">
          Persistent audit trail for this license (newest first).
        </p>
      </header>
      {query.isPending ? (
        <p className="text-sm text-muted-foreground">Loading ledger...</p>
      ) : query.isError ? (
        <p role="alert" className="text-sm text-destructive">
          {formatLaraApiError(query.error)}
        </p>
      ) : (
        <Timeline
          ariaLabel={`Audit ledger for license ${licenseId}`}
          entries={toEntries((query.data ?? []).filter((row) => belongsToLicense(row, licenseId)))}
          emptyState={
            <EmptyState
              preset="box"
              headline="No ledger entries yet"
              body="Mutations to this license will appear here as AuditWriter records them."
            />
          }
        />
      )}
    </section>
  );
}
