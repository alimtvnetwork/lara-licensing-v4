// Plan 09 step 33. Admin resellers list refit onto DataTable + FilterBar.
//
// Server currently returns a bounded array of 100 resellers via
// resellersQueryOptions (see src/lib/lara-reseller.ts). Until the
// backend gains server-side pagination we filter, sort, and paginate
// client-side and feed the results to DataTable. When pagination lands,
// this component swaps to server-side query params without touching the
// route.
//
// Filters: single search box (name or contact email) plus a status
// chip group (all, active, inactive). Both are live (mode="live") since
// the underlying dataset is in memory.

import * as React from "react";
import { Link } from "@tanstack/react-router";
import { CheckCircle2, XCircle } from "lucide-react";

import { DataTable, type LaraColumn, type SortState } from "../ui/data-table";
import { EmptyState } from "../ui/empty-state";
import { FilterBar, FilterChipGroup, FilterText } from "../ui/filter-bar";
import type { Reseller } from "../../lib/lara-reseller";

const PAGE_SIZE = 25;

const dateFormatter = new Intl.DateTimeFormat(undefined, { dateStyle: "medium" });

type StatusFilter = "all" | "active" | "inactive";

const STATUS_OPTIONS = [
  { value: "all", label: "All" },
  { value: "active", label: "Active" },
  { value: "inactive", label: "Inactive" },
] as const satisfies readonly { value: StatusFilter; label: string }[];

interface Filters {
  search: string;
  status: StatusFilter;
}

const EMPTY_FILTERS: Filters = { search: "", status: "all" };

function hasActive(filters: Filters): boolean {
  return filters.search.trim() !== "" || filters.status !== "all";
}

function matches(reseller: Reseller, filters: Filters): boolean {
  if (filters.status === "active" && !reseller.IsActive) return false;
  if (filters.status === "inactive" && reseller.IsActive) return false;
  const term = filters.search.trim().toLowerCase();
  if (term === "") return true;

  return (
    reseller.ResellerName.toLowerCase().includes(term) ||
    reseller.ContactEmail.toLowerCase().includes(term)
  );
}

function compare(a: Reseller, b: Reseller, sort: SortState): number {
  const dir = sort.direction === "asc" ? 1 : -1;
  switch (sort.field) {
    case "ResellerId":
      return (a.ResellerId - b.ResellerId) * dir;
    case "ResellerName":
      return a.ResellerName.localeCompare(b.ResellerName) * dir;
    case "ContactEmail":
      return a.ContactEmail.localeCompare(b.ContactEmail) * dir;
    case "IsActive":
      return (Number(a.IsActive) - Number(b.IsActive)) * dir;
    case "CreatedAt":
      return (Date.parse(a.CreatedAt) - Date.parse(b.CreatedAt)) * dir;
    default:
      return 0;
  }
}

const columns: readonly LaraColumn<Reseller>[] = [
  {
    field: "ResellerId",
    header: "ID",
    sortable: true,
    width: "6rem",
    render: (row) => <span className="font-mono text-xs">{row.ResellerId}</span>,
  },
  {
    field: "ResellerName",
    header: "Reseller",
    sortable: true,
    render: (row) => (
      <Link
        to="/admin/resellers/$resellerId"
        params={{ resellerId: row.ResellerId }}
        className="font-medium text-foreground hover:underline"
      >
        {row.ResellerName}
      </Link>
    ),
  },
  {
    field: "ContactEmail",
    header: "Contact",
    sortable: true,
    render: (row) => <span className="text-muted-foreground">{row.ContactEmail}</span>,
  },
  {
    field: "IsActive",
    header: "Status",
    sortable: true,
    render: (row) => <StatusChip isActive={row.IsActive} />,
  },
  {
    field: "CreatedAt",
    header: "Created",
    sortable: true,
    render: (row) => (
      <span className="text-muted-foreground" title={row.CreatedAt}>
        {dateFormatter.format(new Date(row.CreatedAt))}
      </span>
    ),
  },
];

function StatusChip({ isActive }: { isActive: boolean }) {
  const Icon = isActive ? CheckCircle2 : XCircle;

  return (
    <span className="inline-flex items-center gap-1.5 text-xs font-medium">
      <Icon aria-hidden="true" className="size-4" />
      {isActive ? "Active" : "Inactive"}
    </span>
  );
}

export function ResellerDataTable({ resellers }: { resellers: readonly Reseller[] }) {
  const [filters, setFilters] = React.useState<Filters>(EMPTY_FILTERS);
  const [sort, setSort] = React.useState<SortState | null>({
    field: "ResellerName",
    direction: "asc",
  });
  const [page, setPage] = React.useState(1);

  const active = hasActive(filters);
  const filtered = React.useMemo(
    () => resellers.filter((r) => matches(r, filters)),
    [resellers, filters],
  );
  const sorted = React.useMemo(() => {
    const isFailed = !sort;
    if (isFailed) return filtered;

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
      headline="No resellers match these filters"
      body="Widen the search or reset the status chip to see the full roster."
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
      headline="No resellers found"
      body="Create the first reseller organization to start issuing licenses at scale."
    />
  );

  return (
    <>
      <FilterBar mode="live" hasActiveFilters={active} onClear={clear} ariaLabel="Reseller filters">
        <FilterText
          id="reseller-search"
          label="Search"
          value={filters.search}
          onChange={(v) => setFilters((prev) => ({ ...prev, search: v }))}
          placeholder="Name or contact email"
          widthClass="w-64"
        />
        <FilterChipGroup<StatusFilter>
          name="reseller-status"
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
          rowKey={(row) => String(row.ResellerId)}
          page={page}
          pageSize={PAGE_SIZE}
          total={total}
          sort={sort}
          onSortChange={setSort}
          onPageChange={setPage}
          countNoun="reseller"
          caption="Licensing Portal resellers"
          emptySlot={emptySlot}
        />
      </div>
    </>
  );
}
