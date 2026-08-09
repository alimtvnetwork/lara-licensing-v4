// Plan 09 step 45 (v0.316.0). Reseller-facing quota-requests table.
//
// Root cause this addresses: the reseller quota-requests page still
// rendered `<QuotaRequestList>` (raw `<ul>`, no filter chips, no
// sortable columns, no ID search) even though the admin cross-shard
// inbox has been on the FilterBar + DataTable convergence since
// v0.314. Two mental models for the same domain object => every
// future column/filter is doubled work.
//
// This component mirrors `AdminQuotaRequestsDataTable`'s shape but
// omits the ResellerSlug column and lineage-aware Approve/Deny (out
// of scope for reseller RBAC). Pending rows expose a Cancel action
// that calls `cancelQuotaRequest` with a fresh Idempotency-Key.

import * as React from "react";
import { useMutation, useQueryClient } from "@tanstack/react-query";
import { X } from "lucide-react";

import { DataTable, type LaraColumn, type SortState } from "../ui/data-table";
import { EmptyState } from "../ui/empty-state";
import { FilterBar, FilterChipGroup, FilterText } from "../ui/filter-bar";
import { StatusBadge } from "../shell/StatusBadge";
import { formatLaraApiError } from "../../lib/lara-api-error";
import {
  cancelQuotaRequest,
  quotaRequestListQueryOptions,
  QuotaRequestStatusType,
  type QuotaRequest,
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

// Status tone now sourced from the closed-set QuotaRequestStatusBadge
// registry (spec 24 §25.4); no local tone map, so drift is impossible.

interface Filters {
  search: string;
  status: StatusFilter;
}

const EMPTY_FILTERS: Filters = { search: "", status: "All" };

function hasActive(f: Filters): boolean {
  return f.search.trim() !== "" || f.status !== "All";
}

function matches(row: QuotaRequest, f: Filters): boolean {
  if (f.status !== "All" && row.Status !== f.status) return false;
  const term = f.search.trim().toLowerCase();
  if (term === "") return true;
  if (String(row.QuotaRequestId).includes(term)) return true;
  if (String(row.LicenseCategoryId).includes(term)) return true;
  if (String(row.LicenseTierId).includes(term)) return true;

  return false;
}

function compare(a: QuotaRequest, b: QuotaRequest, sort: SortState): number {
  const dir = sort.direction === "asc" ? 1 : -1;
  switch (sort.field) {
    case "QuotaRequestId":
      return (a.QuotaRequestId - b.QuotaRequestId) * dir;
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

const columns: readonly LaraColumn<QuotaRequest>[] = [
  {
    field: "QuotaRequestId",
    header: "ID",
    sortable: true,
    width: "5rem",
    render: (row) => <span className="font-mono text-xs">{row.QuotaRequestId}</span>,
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
    width: "12rem",
    render: (row) => <RowActions row={row} />,
  },
];

export function ResellerQuotaRequestsDataTable({ rows }: { rows: readonly QuotaRequest[] }) {
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
      body="Widen the search or reset the status chip to see every request."
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
      headline="No quota requests yet"
      body="Submit a request above; it will appear here as Pending until an admin decides."
    />
  );

  return (
    <>
      <FilterBar
        mode="live"
        hasActiveFilters={active}
        onClear={clear}
        ariaLabel="Reseller quota-requests filters"
      >
        <FilterText
          id="reseller-quota-requests-search"
          label="Search"
          value={filters.search}
          onChange={(v) => setFilters((prev) => ({ ...prev, search: v }))}
          placeholder="Request / category / tier ID"
          widthClass="w-64"
        />
        <FilterChipGroup<StatusFilter>
          name="reseller-quota-requests-status"
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
          caption="Your quota requests"
          emptySlot={emptySlot}
        />
      </div>
    </>
  );
}

function RowActions({ row }: { row: QuotaRequest }) {
  if (row.Status !== QuotaRequestStatusType.Pending) {
    return <span className="text-xs text-muted-foreground">-</span>;
  }

  return <PendingCancel row={row} />;
}

type Stage = "idle" | "confirm";

function PendingCancel({ row }: { row: QuotaRequest }) {
  const queryClient = useQueryClient();
  const [stage, setStage] = React.useState<Stage>("idle");
  const [error, setError] = React.useState<string | null>(null);

  const cancel = useMutation({
    mutationFn: () =>
      cancelQuotaRequest(
        row.QuotaRequestId,
        `qr-cancel-${row.QuotaRequestId}-${crypto.randomUUID()}`,
      ),
    onSuccess: () => {
      setStage("idle");
      void queryClient.invalidateQueries({
        queryKey: quotaRequestListQueryOptions(row.ResellerId).queryKey,
      });
    },
    onError: (err) => setError(formatLaraApiError(err)),
  });

  if (stage === "idle") {
    return (
      <div className="flex items-center justify-end">
        <button
          type="button"
          onClick={() => {
            setError(null);
            setStage("confirm");
          }}
          className="focus-ring inline-flex h-8 items-center gap-1.5 rounded-md border border-input px-2.5 text-xs font-medium surface-hover"
        >
          <X aria-hidden="true" className="size-3.5" />
          Cancel
        </button>
      </div>
    );
  }

  return (
    <div className="flex flex-col items-end gap-2 rounded-md border border-border bg-muted/40 p-2 text-xs">
      <p className="max-w-[14rem] text-right">
        Cancel request #{row.QuotaRequestId}? The status flips to Cancelled and cannot be reopened.
      </p>
      <div className="flex items-center gap-2">
        <button
          type="button"
          onClick={() => setStage("idle")}
          disabled={cancel.isPending}
          className="focus-ring inline-flex h-7 items-center rounded-md border border-input px-2 text-xs surface-hover"
        >
          Keep
        </button>
        <button
          type="button"
          onClick={() => {
            setError(null);
            cancel.mutate();
          }}
          disabled={cancel.isPending}
          className="focus-ring inline-flex h-7 items-center gap-1.5 rounded-md bg-foreground px-2 text-xs font-semibold text-background hover:opacity-90 disabled:opacity-60"
        >
          <X aria-hidden="true" className="size-3.5" />
          {cancel.isPending ? "Cancelling..." : "Confirm cancel"}
        </button>
      </div>
      {error === null ? null : (
        <p role="alert" className="max-w-[14rem] text-right text-destructive">
          {error}
        </p>
      )}
    </div>
  );
}
