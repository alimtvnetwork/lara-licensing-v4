import { useState } from "react";
import { useSuspenseQuery } from "@tanstack/react-query";
import { createFileRoute } from "@tanstack/react-router";
import { ScrollText } from "lucide-react";

import { PageHeader } from "../../components/shell/PageHeader";
import { RoutePending, RouteErrorState } from "../../components/shell/RouteFallbacks";
import { EmptyState } from "../../components/ui/empty-state";
import { FilterBar, FilterText } from "../../components/ui/filter-bar";
import { auditLogsQueryOptions } from "../../lib/lara-audit";
// Plan 16 step 69: type-only consumer of the real-BE barrel. Runtime call
// still goes through `requestLaraApi` inside `auditLogsQueryOptions`; only
// the type imports are routed through `@/generated/api/real-be-schema` to
// pin the barrel's `AuditLog` / `AuditLogFilters` re-exports as
// load-bearing. See tests/real-be-schema-consumer.test.ts.
import type { AuditLog, AuditLogFilters } from "@/generated/api/real-be-schema";

/**
 * Plan 09 step 23 + step 95. Admin audit-log viewer.
 *
 * Route-level fallbacks (`RoutePending`, `RouteErrorState`) are shared
 * with the other admin list routes so pending/error surfaces stay
 * uniform. Zero-row states render the `search` EmptyState preset when
 * filters are active (query returned zero matches) or the `box` preset
 * when the table is genuinely empty.
 */
export const Route = createFileRoute("/_authenticated/admin/audit")({
  ssr: false,
  loader: ({ context }) => context.queryClient.ensureQueryData(auditLogsQueryOptions()),
  head: () => ({
    meta: [
      { title: "Audit log | Licensing Portal" },
      { name: "robots", content: "noindex,nofollow" },
    ],
  }),
  pendingComponent: () => (
    <RoutePending title="Audit log" description="Persistent mutation trail from Root AuditLogs." />
  ),
  errorComponent: ({ error, reset }) => (
    <RouteErrorState title="Audit log" error={error} reset={reset} />
  ),
  notFoundComponent: AuditNotFound,
  component: AuditPage,
});

function hasActiveFilters(filters: AuditLogFilters): boolean {
  return Boolean(filters.Action || filters.TargetType || filters.ActorId !== undefined);
}

function AuditPage() {
  const [filters, setFilters] = useState<AuditLogFilters>({});
  const query = useSuspenseQuery(auditLogsQueryOptions(filters));
  const rows = query.data;
  const filtered = hasActiveFilters(filters);

  return (
    <>
      <PageHeader
        title="Audit log"
        description={`Persistent mutation trail from Root AuditLogs. ${rows.length} rows loaded (newest first).`}
      />
      <AuditFilters filters={filters} onChange={setFilters} />
      {rows.length === 0 ? (
        <EmptyState
          preset={filtered ? "search" : "box"}
          headline={filtered ? "No audit rows match these filters" : "No audit events recorded yet"}
          body={
            filtered
              ? "Clear the filters or widen the criteria to see recent activity."
              : "Mutations captured by AuditWriter will appear here in real time."
          }
          secondary={
            filtered ? (
              <button
                type="button"
                onClick={() => setFilters({})}
                className="focus-ring inline-flex h-9 items-center rounded-md border border-input px-3 text-sm font-medium surface-hover"
              >
                Clear filters
              </button>
            ) : null
          }
        />
      ) : (
        <AuditTable rows={rows} />
      )}
    </>
  );
}

function AuditFilters({
  filters,
  onChange,
}: {
  filters: AuditLogFilters;
  onChange: (f: AuditLogFilters) => void;
}) {
  const [draft, setDraft] = useState<AuditLogFilters>(filters);
  const clear = () => {
    setDraft({});
    onChange({});
  };

  return (
    <FilterBar
      mode="submit"
      hasActiveFilters={hasActiveFilters(draft) || hasActiveFilters(filters)}
      onApply={() => onChange(draft)}
      onClear={clear}
      ariaLabel="Audit filters"
    >
      <FilterText
        id="Action"
        label="Action"
        value={draft.Action ?? ""}
        onChange={(v) => setDraft({ ...draft, Action: v })}
        placeholder="license.revoke"
      />
      <FilterText
        id="TargetType"
        label="TargetType"
        value={draft.TargetType ?? ""}
        onChange={(v) => setDraft({ ...draft, TargetType: v })}
        placeholder="License"
      />
      <FilterText
        id="ActorId"
        label="ActorId"
        value={draft.ActorId === undefined ? "" : String(draft.ActorId)}
        onChange={(v) => {
          const trimmed = v.trim();
          setDraft({ ...draft, ActorId: trimmed === "" ? undefined : Number(trimmed) });
        }}
        placeholder="42"
        inputMode="numeric"
      />
    </FilterBar>
  );
}

function AuditTable({ rows }: { rows: AuditLog[] }) {
  return (
    <div className="mt-6 overflow-x-auto rounded-md border border-border">
      <table className="min-w-full text-sm">
        <thead className="bg-muted/40 text-left text-xs uppercase text-muted-foreground">
          <tr>
            <th className="px-3 py-2 font-medium">When</th>
            <th className="px-3 py-2 font-medium">Actor</th>
            <th className="px-3 py-2 font-medium">Action</th>
            <th className="px-3 py-2 font-medium">Target</th>
            <th className="px-3 py-2 font-medium">RequestId</th>
          </tr>
        </thead>
        <tbody className="divide-y divide-border">
          {rows.map((row) => (
            <tr key={row.AuditLogId} className="align-top">
              <td className="whitespace-nowrap px-3 py-2 font-mono text-xs text-muted-foreground">
                {row.CreatedAt}
              </td>
              <td className="whitespace-nowrap px-3 py-2 text-xs">
                <span className="font-mono">{row.ActorType}</span>
                {row.ActorId === null ? null : (
                  <span className="ml-1 text-muted-foreground">#{row.ActorId}</span>
                )}
              </td>
              <td className="whitespace-nowrap px-3 py-2 font-medium">{row.Action}</td>
              <td className="whitespace-nowrap px-3 py-2 text-xs">
                <span className="font-mono">{row.TargetType}</span>
                {row.TargetId === null ? null : (
                  <span className="ml-1 text-muted-foreground">#{row.TargetId}</span>
                )}
              </td>
              <td className="whitespace-nowrap px-3 py-2 font-mono text-xs text-muted-foreground">
                {row.RequestId}
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

function AuditNotFound() {
  return (
    <PageHeader
      title="Audit log unavailable"
      description={"AuditEvents.Read is required to view this page."}
    />
  );
}

void ScrollText;
