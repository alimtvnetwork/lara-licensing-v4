// Plan 06 step 67. Audit trail viewer ported from the React SPA route
// src/routes/_authenticated/admin.audit.tsx onto Inertia.
//
// GET /Api/Admin/AuditLogs (App\Http\Controllers\Admin\AuditController::index)
// already returns newest-first rows shaped by AuditEntryResource, so this
// component is pure presentation plus a filter bar. Filters are pushed
// through Inertia query params (Action, TargetType, ActorId, Limit) so the
// controller does the filtering server-side, matching the SPA contract.
//
// Reused in reseller scope (step 68) by passing a narrower row set and
// hiding the filter bar.

import * as React from "react";
import { router } from "@inertiajs/react";
import { ScrollText, Search } from "lucide-react";

import { EmptyState } from "@/Components/ui/EmptyState";
import { Button } from "@/Components/ui/Button";

export interface AuditRow {
  AuditLogId: number;
  ActorType: string;
  ActorId: number | null;
  Action: string;
  TargetType: string;
  TargetId: string | null;
  RequestId: string;
  Payload: Record<string, unknown> | null;
  CreatedAt: string;
}

export interface AuditFilters {
  Action?: string;
  TargetType?: string;
  ActorId?: string;
}

interface Props {
  rows: AuditRow[];
  filters?: AuditFilters;
  /** Inertia URL the filter bar submits to. Omit to hide the filter bar. */
  filterUrl?: string;
  emptyHeadline?: string;
  emptyBody?: string;
}

const stampFormatter = new Intl.DateTimeFormat(undefined, { dateStyle: "medium", timeStyle: "medium" });

function formatStamp(value: string): string {
  const parsed = Date.parse(value);
  return Number.isNaN(parsed) ? value : stampFormatter.format(new Date(parsed));
}

function hasActiveFilters(filters: AuditFilters): boolean {
  return Boolean(filters.Action || filters.TargetType || filters.ActorId);
}

export function AuditTable({
  rows,
  filters = {},
  filterUrl,
  emptyHeadline,
  emptyBody,
}: Props) {
  const [draft, setDraft] = React.useState<AuditFilters>(filters);
  const filtered = hasActiveFilters(filters);

  const submit = (next: AuditFilters) => {
    if (filterUrl === undefined) return;
    const query: Record<string, string> = {};
    if (next.Action) query.Action = next.Action.trim();
    if (next.TargetType) query.TargetType = next.TargetType.trim();
    if (next.ActorId) query.ActorId = next.ActorId.trim();
    router.get(filterUrl, query, { preserveScroll: true, preserveState: true });
  };

  return (
    <div>
      {filterUrl !== undefined && (
        <form
          aria-label="Audit filters"
          className="flex flex-wrap items-end gap-3 rounded-lg border border-border bg-card p-4"
          onSubmit={(event) => {
            event.preventDefault();
            submit(draft);
          }}
        >
          <FilterField id="Action" label="Action" placeholder="license.revoke" value={draft.Action ?? ""} onChange={(v) => setDraft({ ...draft, Action: v })} />
          <FilterField id="TargetType" label="TargetType" placeholder="License" value={draft.TargetType ?? ""} onChange={(v) => setDraft({ ...draft, TargetType: v })} />
          <FilterField id="ActorId" label="ActorId" placeholder="42" inputMode="numeric" value={draft.ActorId ?? ""} onChange={(v) => setDraft({ ...draft, ActorId: v })} />
          <Button type="submit">Apply</Button>
          {(filtered || hasActiveFilters(draft)) && (
            <Button
              type="button"
              variant="outline"
              onClick={() => {
                setDraft({});
                submit({});
              }}
            >
              Clear
            </Button>
          )}
        </form>
      )}

      {rows.length === 0 ? (
        <EmptyState
          className="mt-6"
          preset="box"
          icon={filtered ? <Search className="size-6 text-muted-foreground" /> : <ScrollText className="size-6 text-muted-foreground" />}
          headline={emptyHeadline ?? (filtered ? "No audit rows match these filters" : "No audit events recorded yet")}
          body={
            emptyBody ??
            (filtered
              ? "Clear the filters or widen the criteria to see recent activity."
              : "Mutations captured by AuditWriter will appear here as they happen.")
          }
        />
      ) : (
        <div className="mt-6 overflow-x-auto rounded-lg border border-border">
          <table className="min-w-full text-sm">
            <thead className="bg-muted/40 text-left text-xs uppercase tracking-wide text-muted-foreground">
              <tr>
                <th scope="col" className="px-3 py-2 font-medium">When</th>
                <th scope="col" className="px-3 py-2 font-medium">Actor</th>
                <th scope="col" className="px-3 py-2 font-medium">Action</th>
                <th scope="col" className="px-3 py-2 font-medium">Target</th>
                <th scope="col" className="px-3 py-2 font-medium">RequestId</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-border">
              {rows.map((row) => (
                <tr key={row.AuditLogId} className="align-top">
                  <td className="whitespace-nowrap px-3 py-2 font-mono text-xs text-muted-foreground">{formatStamp(row.CreatedAt)}</td>
                  <td className="whitespace-nowrap px-3 py-2 text-xs">
                    <span className="font-mono">{row.ActorType || "unknown"}</span>
                    {row.ActorId !== null && <span className="ml-1 text-muted-foreground">#{row.ActorId}</span>}
                  </td>
                  <td className="whitespace-nowrap px-3 py-2 font-medium">{row.Action || "unknown"}</td>
                  <td className="whitespace-nowrap px-3 py-2 text-xs">
                    <span className="font-mono">{row.TargetType || "unknown"}</span>
                    {row.TargetId !== null && <span className="ml-1 text-muted-foreground">#{row.TargetId}</span>}
                  </td>
                  <td className="whitespace-nowrap px-3 py-2 font-mono text-xs text-muted-foreground">{row.RequestId || "unknown"}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </div>
  );
}

function FilterField({
  id,
  label,
  value,
  onChange,
  placeholder,
  inputMode,
}: {
  id: string;
  label: string;
  value: string;
  onChange: (value: string) => void;
  placeholder?: string;
  inputMode?: "numeric";
}) {
  return (
    <label htmlFor={id} className="flex flex-col gap-1 text-xs font-medium text-muted-foreground">
      {label}
      <input
        id={id}
        name={id}
        value={value}
        inputMode={inputMode}
        placeholder={placeholder}
        onChange={(event) => onChange(event.target.value)}
        className="h-9 w-48 rounded-md border border-input bg-background px-3 text-sm text-foreground"
      />
    </label>
  );
}
