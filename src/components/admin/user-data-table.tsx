// Plan 09 step 36. Admin users list refit onto DataTable + FilterBar.
//
// Root cause this addresses: admin.users.tsx rendered a legacy scaffold
// <table> via user-list.tsx with no sort, no pagination, and no filter
// surface, diverging from the shared DataTable + FilterBar pattern
// established for resellers (v0.311.0) and audit (v0.311.0). Any list
// grew unbounded because USER_LIMIT=200 was cached raw into the DOM.
//
// Filters are live (mode="live") since the dataset is already in
// memory. Server-side pagination is a swap point when /Users gains
// cursor pagination.
//
// Role chips are intentionally NOT part of this filter surface: the
// /Users transport returns a bounded flat list without per-user roles
// (see src/lib/lara-user-role.ts). Adding a role facet here would
// require an N+1 fetch of /Admin/Users/:id/Roles per row, which is a
// backend concern (needs a joined /Users?WithRoles=1 projection or a
// dedicated /Admin/UserRoles index endpoint). Do not paper over that
// gap on the client.

import * as React from "react";
import { Link } from "@tanstack/react-router";
import { CheckCircle2, XCircle } from "lucide-react";

import { Badge } from "../ui/badge";
import { DataTable, type LaraColumn, type SortState } from "../ui/data-table";
import { EmptyState } from "../ui/empty-state";
import { FilterBar, FilterChipGroup, FilterText } from "../ui/filter-bar";
import type { LaraUser } from "../../lib/lara-user-role";

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

function matches(user: LaraUser, filters: Filters): boolean {
  if (filters.status === "active" && !user.IsActive) return false;
  if (filters.status === "inactive" && user.IsActive) return false;
  const term = filters.search.trim().toLowerCase();
  if (term === "") return true;
  if (user.Email.toLowerCase().includes(term)) return true;

  return String(user.UserId).includes(term);
}

function compare(a: LaraUser, b: LaraUser, sort: SortState): number {
  const dir = sort.direction === "asc" ? 1 : -1;
  switch (sort.field) {
    case "UserId":
      return (a.UserId - b.UserId) * dir;
    case "Email":
      return a.Email.localeCompare(b.Email) * dir;
    case "TenantId":
      return ((a.TenantId ?? 0) - (b.TenantId ?? 0)) * dir;
    case "IsActive":
      return (Number(a.IsActive) - Number(b.IsActive)) * dir;
    case "CreatedAt":
      return (Date.parse(a.CreatedAt) - Date.parse(b.CreatedAt)) * dir;
    default:
      return 0;
  }
}

const columns: readonly LaraColumn<LaraUser>[] = [
  {
    field: "UserId",
    header: "ID",
    sortable: true,
    width: "6rem",
    render: (row) => <span className="font-mono text-xs">{row.UserId}</span>,
  },
  {
    field: "Email",
    header: "Email",
    sortable: true,
    render: (row) => (
      <Link
        to="/admin/users/$userId"
        params={{ userId: row.UserId }}
        className="font-medium text-foreground hover:underline"
      >
        {row.Email}
      </Link>
    ),
  },
  {
    field: "TenantId",
    header: "Tenant",
    sortable: true,
    render: (row) => <span className="text-muted-foreground">{row.TenantId ?? "-"}</span>,
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
  // Plan 15 step 19 (v0.506.0): refit to Badge intent axis so the Active/Inactive
  // pill routes through `@utility chip[data-tone]` (v0.491.0) and stays parity
  // with resellers/audit tables. Icon retained as a leading affordance.
  const Icon = isActive ? CheckCircle2 : XCircle;

  return (
    <Badge intent={isActive ? "success" : "destructive"} size="sm" className="gap-1">
      <Icon aria-hidden="true" className="size-3" />
      {isActive ? "Active" : "Inactive"}
    </Badge>
  );
}

export function UserDataTable({ users }: { users: readonly LaraUser[] }) {
  const [filters, setFilters] = React.useState<Filters>(EMPTY_FILTERS);
  const [sort, setSort] = React.useState<SortState | null>({
    field: "Email",
    direction: "asc",
  });
  const [page, setPage] = React.useState(1);

  const active = hasActive(filters);
  const filtered = React.useMemo(() => users.filter((u) => matches(u, filters)), [users, filters]);
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
      headline="No users match these filters"
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
      headline="No users found"
      body="The API returned an empty user collection. Invite the first operator to populate this list."
    />
  );

  return (
    <>
      <FilterBar mode="live" hasActiveFilters={active} onClear={clear} ariaLabel="User filters">
        <FilterText
          id="user-search"
          label="Search"
          value={filters.search}
          onChange={(v) => setFilters((prev) => ({ ...prev, search: v }))}
          placeholder="Email or user ID"
          widthClass="w-64"
        />
        <FilterChipGroup<StatusFilter>
          name="user-status"
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
          rowKey={(row) => String(row.UserId)}
          page={page}
          pageSize={PAGE_SIZE}
          total={total}
          sort={sort}
          onSortChange={setSort}
          onPageChange={setPage}
          countNoun="user"
          caption="Licensing Portal users"
          emptySlot={emptySlot}
        />
      </div>
    </>
  );
}
