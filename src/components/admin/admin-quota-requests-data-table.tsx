// Plan 09 step 43 + 43-follow-up (v0.315.0). Admin cross-shard
// quota-requests inbox with lineage-aware Approve/Deny actions.
//
// Root cause this addresses: the backend fans out `GET
// /Api/Admin/QuotaRequests/All` across every active reseller shard
// (see `Admin\QuotaRequestController::indexAll`) but the frontend had
// no read surface. Approve/Deny were reachable per-row only through
// the reseller-scoped list, so admin operators had to know which
// reseller a Pending request belonged to before they could reach it.
//
// v0.315.0: inline lineage-aware Approve/Deny actions render on
// Pending rows and call the fixed transport
// (`?ResellerSlug=<row.ResellerSlug>`) with a fresh Idempotency-Key.

import * as React from "react";
import { Link } from "@tanstack/react-router";
import { useMutation, useQueryClient } from "@tanstack/react-query";
import { Check, X } from "lucide-react";

import { DataTable, type LaraColumn, type SortState } from "../ui/data-table";
import { EmptyState } from "../ui/empty-state";
import { FilterBar, FilterChipGroup, FilterText } from "../ui/filter-bar";
import { StatusBadge } from "../shell/StatusBadge";
import { LineageBadge } from "./lineage-badge";
import { formatLaraApiError } from "../../lib/lara-api-error";
import {
  approveQuotaRequest,
  denyQuotaRequest,
  QuotaRequestStatusType,
  type AdminQuotaRequestRow,
  type QuotaRequestStatusValue,
} from "../../lib/lara-quota";

const PAGE_SIZE = 25;

const dateTimeFormatter = new Intl.DateTimeFormat(undefined, {
  dateStyle: "medium",
  timeStyle: "short",
});

type StatusFilter = "All" | QuotaRequestStatusValue;

const STATUS_OPTIONS = [
  { value: "All", label: "All" },
  { value: QuotaRequestStatusType.Pending, label: "Pending" },
  { value: QuotaRequestStatusType.Approved, label: "Approved" },
  { value: QuotaRequestStatusType.Denied, label: "Denied" },
  { value: QuotaRequestStatusType.Cancelled, label: "Cancelled" },
] as const satisfies readonly { value: StatusFilter; label: string }[];

// Tone is sourced from the QuotaRequestStatus badge registry
// (src/components/badge/registry.ts), which is the closed-set-backed
// intent map. No local tone table — Plan 15 Step 20.

interface Filters {
  search: string;
  status: StatusFilter;
}

const EMPTY_FILTERS: Filters = { search: "", status: "All" };

function hasActive(filters: Filters): boolean {
  return filters.search.trim() !== "" || filters.status !== "All";
}

function matches(row: AdminQuotaRequestRow, filters: Filters): boolean {
  if (filters.status !== "All" && row.Status !== filters.status) return false;
  const term = filters.search.trim().toLowerCase();
  if (term === "") return true;
  if (row.ResellerSlug.toLowerCase().includes(term)) return true;
  if (String(row.QuotaRequestId).includes(term)) return true;
  if (String(row.ResellerId).includes(term)) return true;

  return false;
}

function compare(a: AdminQuotaRequestRow, b: AdminQuotaRequestRow, sort: SortState): number {
  const dir = sort.direction === "asc" ? 1 : -1;
  switch (sort.field) {
    case "QuotaRequestId":
      return (a.QuotaRequestId - b.QuotaRequestId) * dir;
    case "ResellerSlug":
      return a.ResellerSlug.localeCompare(b.ResellerSlug) * dir;
    case "Status":
      return a.Status.localeCompare(b.Status) * dir;
    case "RequestedDelta":
      return (a.RequestedDelta - b.RequestedDelta) * dir;
    case "SubmittedAt":
      return (Date.parse(a.SubmittedAt) - Date.parse(b.SubmittedAt)) * dir;
    default:
      return 0;
  }
}

const columns: readonly LaraColumn<AdminQuotaRequestRow>[] = [
  {
    field: "QuotaRequestId",
    header: "ID",
    sortable: true,
    width: "5rem",
    render: (row) => <span className="font-mono text-xs">{row.QuotaRequestId}</span>,
  },
  {
    field: "ResellerSlug",
    header: "Reseller",
    sortable: true,
    render: (row) => (
      <Link
        to="/admin/resellers/$resellerId"
        params={{ resellerId: row.ResellerId }}
        className="font-medium text-foreground hover:underline"
      >
        {row.ResellerSlug}
      </Link>
    ),
  },
  {
    field: "Scope",
    header: "Category / Tier",
    render: (row) => (
      <span className="font-mono text-xs text-muted-foreground">
        cat {row.LicenseCategoryId} / tier {row.LicenseTierId}
      </span>
    ),
  },
  {
    field: "RequestedDelta",
    header: "Requested",
    sortable: true,
    align: "end",
    render: (row) => <span className="font-mono text-sm">+{row.RequestedDelta}</span>,
  },
  {
    field: "Status",
    header: "Status",
    sortable: true,
    render: (row) => <StatusBadge registry="QuotaRequestStatus" value={row.Status} />,
  },
  {
    field: "SubmittedAt",
    header: "Submitted",
    sortable: true,
    render: (row) => (
      <span className="text-xs text-muted-foreground" title={row.SubmittedAt}>
        {dateTimeFormatter.format(new Date(row.SubmittedAt))}
      </span>
    ),
  },
  {
    field: "Actions",
    header: "Actions",
    align: "end",
    width: "18rem",
    render: (row) => <RowActions row={row} />,
  },
];

export function AdminQuotaRequestsDataTable({ rows }: { rows: readonly AdminQuotaRequestRow[] }) {
  const [filters, setFilters] = React.useState<Filters>(EMPTY_FILTERS);
  const [sort, setSort] = React.useState<SortState | null>({
    field: "SubmittedAt",
    direction: "desc",
  });
  const [page, setPage] = React.useState(1);

  const active = hasActive(filters);
  const filtered = React.useMemo(() => rows.filter((r) => matches(r, filters)), [rows, filters]);
  const sorted = React.useMemo(() => {
    if (sort === null) return filtered;

    return [...filtered].sort((a, b) => compare(a, b, sort));
  }, [filtered, sort]);
  const total = sorted.length;
  const pageRows = React.useMemo(() => {
    const start = (page - 1) * PAGE_SIZE;

    return sorted.slice(start, start + PAGE_SIZE);
  }, [sorted, page]);

  React.useEffect(() => {
    setPage(1);
  }, [filters, sort]);

  const clear = () => setFilters(EMPTY_FILTERS);

  const emptySlot = active ? (
    <EmptyState
      preset="search"
      headline="No quota requests match these filters"
      body="Widen the search or reset the status chip to see the full inbox."
      secondary={
        <button
          type="button"
          onClick={clear}
          className="focus-ring inline-flex h-9 items-center rounded-md border border-input px-3 text-sm font-medium surface-hover"
        >
          Clear filters
        </button>
      }
    />
  ) : (
    <EmptyState
      preset="box"
      headline="No quota requests"
      body="Resellers have not submitted any allowance changes yet."
    />
  );

  return (
    <>
      <FilterBar
        mode="live"
        hasActiveFilters={active}
        onClear={clear}
        ariaLabel="Quota requests filters"
      >
        <FilterText
          id="quota-requests-search"
          label="Search"
          value={filters.search}
          onChange={(v) => setFilters((prev) => ({ ...prev, search: v }))}
          placeholder="Reseller slug or ID"
          widthClass="w-64"
        />
        <FilterChipGroup<StatusFilter>
          name="quota-requests-status"
          label="Status"
          value={filters.status}
          options={STATUS_OPTIONS}
          onChange={(v) => setFilters((prev) => ({ ...prev, status: v }))}
        />
      </FilterBar>
      <div className="mt-6">
        <DataTable
          rows={pageRows}
          columns={columns}
          rowKey={(row) => String(row.QuotaRequestId)}
          page={page}
          pageSize={PAGE_SIZE}
          total={total}
          sort={sort}
          onSortChange={setSort}
          onPageChange={setPage}
          countNoun="request"
          caption="Cross-shard quota-request inbox"
          emptySlot={emptySlot}
        />
      </div>
    </>
  );
}

/**
 * Inline lineage-aware Approve/Deny controls. Mirrors the app-updates
 * Yank pattern established in v0.314.0: a two-stage confirm renders
 * a `<LineageBadge />` at the moment an admin authorizes a destructive
 * or approving mutation, so an impersonated session sees the acting
 * principal before the write commits.
 *
 * Only renders on Pending rows. Uses the fixed transport
 * (`?ResellerSlug=<row.ResellerSlug>`) so shard binding lands.
 */
function RowActions({ row }: { row: AdminQuotaRequestRow }) {
  if (row.Status !== QuotaRequestStatusType.Pending) {
    return <span className="text-xs text-muted-foreground">—</span>;
  }

  return <PendingActions row={row} />;
}

type Stage = "idle" | "approve" | "deny";

function PendingActions({ row }: { row: AdminQuotaRequestRow }) {
  const queryClient = useQueryClient();
  const [stage, setStage] = React.useState<Stage>("idle");
  const [denyReason, setDenyReason] = React.useState("");
  const [error, setError] = React.useState<string | null>(null);

  const invalidate = () => {
    void queryClient.invalidateQueries({
      queryKey: ["LaraApi", "Admin", "QuotaRequests"],
    });
  };

  const approveMutation = useMutation({
    mutationFn: () =>
      approveQuotaRequest(
        row.QuotaRequestId,
        row.ResellerSlug,
        {},
        `qr-approve-${row.QuotaRequestId}-${crypto.randomUUID()}`,
      ),
    onSuccess: () => {
      setStage("idle");
      invalidate();
    },
    onError: (err) => setError(formatLaraApiError(err)),
  });

  const denyMutation = useMutation({
    mutationFn: () =>
      denyQuotaRequest(
        row.QuotaRequestId,
        row.ResellerSlug,
        { Reason: denyReason.trim() },
        `qr-deny-${row.QuotaRequestId}-${crypto.randomUUID()}`,
      ),
    onSuccess: () => {
      setStage("idle");
      setDenyReason("");
      invalidate();
    },
    onError: (err) => setError(formatLaraApiError(err)),
  });

  const busy = approveMutation.isPending || denyMutation.isPending;

  if (stage === "idle") {
    return (
      <div className="flex items-center justify-end gap-2">
        <button
          type="button"
          onClick={() => {
            setError(null);
            setStage("approve");
          }}
          className="focus-ring inline-flex h-8 items-center gap-1.5 rounded-md bg-primary px-2.5 text-xs font-medium text-primary-foreground hover:bg-primary/90"
        >
          <Check aria-hidden="true" className="size-3.5" />
          Approve
        </button>
        <button
          type="button"
          onClick={() => {
            setError(null);
            setStage("deny");
          }}
          className="focus-ring inline-flex h-8 items-center gap-1.5 rounded-md border border-destructive/40 px-2.5 text-xs font-medium text-destructive hover:bg-destructive/5"
        >
          <X aria-hidden="true" className="size-3.5" />
          Deny
        </button>
      </div>
    );
  }

  if (stage === "approve") {
    return (
      <div className="flex flex-col items-end gap-2 rounded-md border border-primary/40 bg-primary/5 p-2 text-xs">
        <LineageBadge />
        <p className="max-w-[16rem] text-right">
          Approve +{row.RequestedDelta} for {row.ResellerSlug}? The reseller quota and ledger will
          update atomically.
        </p>
        <div className="flex items-center gap-2">
          <button
            type="button"
            onClick={() => setStage("idle")}
            disabled={busy}
            className="focus-ring inline-flex h-7 items-center rounded-md border border-input px-2 text-xs surface-hover"
          >
            Cancel
          </button>
          <button
            type="button"
            onClick={() => {
              setError(null);
              approveMutation.mutate();
            }}
            disabled={busy}
            className="focus-ring inline-flex h-7 items-center gap-1.5 rounded-md bg-primary px-2 text-xs font-semibold text-primary-foreground hover:bg-primary/90 disabled:opacity-60"
          >
            <Check aria-hidden="true" className="size-3.5" />
            {approveMutation.isPending ? "Approving..." : "Confirm approve"}
          </button>
        </div>
        {error === null ? null : (
          <p role="alert" className="max-w-[16rem] text-right text-destructive">
            {error}
          </p>
        )}
      </div>
    );
  }

  const reasonValid = denyReason.trim().length >= 1;

  return (
    <div className="flex flex-col items-end gap-2 rounded-md border border-destructive/40 bg-destructive/5 p-2 text-xs">
      <LineageBadge />
      <label className="flex w-64 flex-col gap-1 text-right">
        <span className="text-[11px] font-medium text-muted-foreground">
          Denial reason (required)
        </span>
        <input
          type="text"
          value={denyReason}
          onChange={(e) => setDenyReason(e.target.value)}
          disabled={busy}
          maxLength={500}
          className="focus-ring h-8 rounded-md border border-input bg-background px-2 text-right text-xs"
          placeholder="Reason shown in audit log"
        />
      </label>
      <div className="flex items-center gap-2">
        <button
          type="button"
          onClick={() => {
            setStage("idle");
            setDenyReason("");
          }}
          disabled={busy}
          className="focus-ring inline-flex h-7 items-center rounded-md border border-input px-2 text-xs surface-hover"
        >
          Cancel
        </button>
        <button
          type="button"
          onClick={() => {
            setError(null);
            denyMutation.mutate();
          }}
          disabled={busy || !reasonValid}
          className="focus-ring inline-flex h-7 items-center gap-1.5 rounded-md bg-destructive px-2 text-xs font-semibold text-destructive-foreground hover:bg-destructive/90 disabled:opacity-60"
        >
          <X aria-hidden="true" className="size-3.5" />
          {denyMutation.isPending ? "Denying..." : "Confirm deny"}
        </button>
      </div>
      {error === null ? null : (
        <p role="alert" className="max-w-[16rem] text-right text-destructive">
          {error}
        </p>
      )}
    </div>
  );
}
