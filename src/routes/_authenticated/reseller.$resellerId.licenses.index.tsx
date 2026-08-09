import * as React from "react";
import { useSuspenseQuery } from "@tanstack/react-query";
import { createFileRoute, Link } from "@tanstack/react-router";

import { PageHeader } from "../../components/shell/PageHeader";
import { EmptyState } from "../../components/ui/empty-state";
import { RoutePending, RouteErrorState } from "../../components/shell/RouteFallbacks";
import { DataTable, type LaraColumn, type SortState } from "../../components/ui/data-table";
import { FilterBar, FilterChipGroup, FilterText } from "../../components/ui/filter-bar";
import { meQueryOptions } from "../../lib/lara-me";
import {
  resellerLicenseListQueryOptions,
  type ResellerLicense,
} from "../../lib/lara-reseller-license";

/**
 * Plan 09 Step 42: reseller License list refit onto shared
 * FilterBar + DataTable primitives.
 *
 * Root cause this refit addresses: v0.278 shipped this route with a
 * bare `<DataTable pageSize=total>` (no sort, no pagination, no filter
 * surface), diverging from the admin resellers/users lists shipped in
 * v0.311.0-v0.312.0. Any reseller with >25 licenses saw an unbounded
 * scroll region and had no way to isolate active vs revoked rows on
 * screen, which is the primary lookup shape when investigating a
 * complaint.
 *
 * Row-scope: server-side `ShardBindingMiddleware`
 * (spec/23-app-db/10-reseller-shard-split-db.md) binds the query to
 * the caller's tenant so the URL never needs to disambiguate. The
 * identity gate below mirrors `reseller.$resellerId.quota-requests.tsx`
 * and blocks cross-tenant UI even though row-scope is authoritative
 * server-side.
 *
 * Filters are `live` (in-memory dataset capped at 100 by
 * `resellerLicenseListQueryOptions`). Status chip options are derived
 * from the observed `Status` values so the closed set stays honest as
 * the backend evolves.
 */
export const Route = createFileRoute("/_authenticated/reseller/$resellerId/licenses/")({
  ssr: false,
  parseParams: ({ resellerId }) => ({ resellerId: parseResellerId(resellerId) }),
  head: () => ({
    meta: [
      { title: "Licenses | Licensing Portal" },
      { name: "robots", content: "noindex,nofollow" },
    ],
  }),
  pendingComponent: () => (
    <RoutePending title="Licenses" description="Loading your license inventory." rows={6} />
  ),
  errorComponent: ({ error, reset }) => (
    <RouteErrorState title="Licenses" error={error} reset={reset} />
  ),
  component: ResellerLicensesPage,
});

const PAGE_SIZE = 25;

function parseResellerId(raw: string): number {
  const parsed = Number(raw);
  if (!Number.isInteger(parsed) || parsed <= 0) {
    throw new Error(`Invalid resellerId: ${raw}`);
  }

  return parsed;
}

function crumbsFor(resellerId: number) {
  return [
    { label: "Reseller", to: `/reseller/${resellerId}` },
    { label: `Reseller ${resellerId}`, identifier: true },
    { label: "Licenses" },
  ];
}

function ResellerLicensesPage() {
  const { resellerId } = Route.useParams();
  const { data: meRows } = useSuspenseQuery(meQueryOptions());
  const [me] = meRows;
  const isFailed = !me;
  if (isFailed) {
    throw new Error(
      "Users.Me returned an empty envelope; server invariant break per AC-API-USR-001",
    );
  }
  const isMismatch = me.RoleName === "Reseller" && me.ResellerId !== resellerId;

  return (
    <>
      <PageHeader
        title="Licenses"
        breadcrumbs={crumbsFor(resellerId)}
        description={
          isMismatch ? undefined : "Licenses issued on this reseller shard, most recent first."
        }
      />
      {isMismatch ? (
        <ForbiddenGate callerResellerId={me.ResellerId ?? null} urlResellerId={resellerId} />
      ) : (
        <LicensesTable resellerId={resellerId} />
      )}
    </>
  );
}

function ForbiddenGate(props: { callerResellerId: number | null; urlResellerId: number }) {
  return (
    <section
      role="alert"
      className="rounded-md border border-destructive/50 bg-destructive/5 p-5 text-sm"
    >
      <h2 className="text-base font-semibold text-destructive">Access denied</h2>
      <p className="mt-2 text-muted-foreground">
        Your account is scoped to reseller {props.callerResellerId ?? "(none)"}, but this page
        targets reseller {props.urlResellerId}. Row-scope enforcement per
        spec/21-app/40-permissions.md blocks this request server-side; this UI gate prevents the
        license inventory from mounting.
      </p>
    </section>
  );
}

const COLUMNS: readonly LaraColumn<ResellerLicense>[] = [
  {
    field: "LicenseKey",
    header: "License key",
    sortable: true,
    render: (row) => <span className="font-mono text-xs">{row.LicenseKey}</span>,
  },
  { field: "TierName", header: "Tier", sortable: true, render: (row) => row.TierName },
  {
    field: "EnvironmentName",
    header: "Environment",
    sortable: true,
    render: (row) => row.EnvironmentName,
  },
  {
    field: "ProductVersion",
    header: "Product",
    sortable: true,
    render: (row) => row.ProductVersion,
  },
  {
    field: "Status",
    header: "Status",
    sortable: true,
    render: (row) => <StatusPill status={row.Status} />,
  },
  {
    field: "IssuedAt",
    header: "Issued",
    sortable: true,
    render: (row) => formatDate(row.IssuedAt),
  },
  {
    field: "ExpiresAt",
    header: "Expires",
    sortable: true,
    render: (row) =>
      row.ExpiresAt === "" ? (
        <span className="text-muted-foreground">Never</span>
      ) : (
        formatDate(row.ExpiresAt)
      ),
  },
  {
    field: "actions",
    header: "",
    align: "end",
    render: (row) => (
      <Link
        to="/reseller/$resellerId/licenses/$licenseKey"
        params={{ resellerId: row.ResellerId, licenseKey: row.LicenseKey }}
        className="text-sm font-medium text-primary underline-offset-4 hover:underline"
      >
        View
      </Link>
    ),
  },
];

type StatusFilter = "all" | string;

interface Filters {
  search: string;
  status: StatusFilter;
}

const EMPTY_FILTERS: Filters = { search: "", status: "all" };

function hasActive(filters: Filters): boolean {
  return filters.search.trim() !== "" || filters.status !== "all";
}

function matches(row: ResellerLicense, filters: Filters): boolean {
  if (filters.status !== "all" && row.Status !== filters.status) return false;
  const term = filters.search.trim().toLowerCase();
  if (term === "") return true;

  return (
    row.LicenseKey.toLowerCase().includes(term) ||
    row.ProductVersion.toLowerCase().includes(term) ||
    row.TierName.toLowerCase().includes(term) ||
    row.EnvironmentName.toLowerCase().includes(term)
  );
}

function compareRows(a: ResellerLicense, b: ResellerLicense, sort: SortState): number {
  const dir = sort.direction === "asc" ? 1 : -1;
  const key = sort.field as keyof ResellerLicense;
  const va = a[key];
  const vb = b[key];
  if (typeof va === "number" && typeof vb === "number") return (va - vb) * dir;

  return String(va ?? "").localeCompare(String(vb ?? "")) * dir;
}

function LicensesTable({ resellerId }: { resellerId: number }) {
  const { data: rows } = useSuspenseQuery(resellerLicenseListQueryOptions(resellerId));
  const [filters, setFilters] = React.useState<Filters>(EMPTY_FILTERS);
  const [sort, setSort] = React.useState<SortState | null>({
    field: "IssuedAt",
    direction: "desc",
  });
  const [page, setPage] = React.useState(1);

  // Status options derived from observed values so the closed set stays honest.
  const statusOptions = React.useMemo(() => {
    const seen = new Set<string>();
    for (const row of rows) if (row.Status !== "") seen.add(row.Status);
    const list = [...seen].sort();

    return [
      { value: "all" as StatusFilter, label: "All" },
      ...list.map((s) => ({ value: s as StatusFilter, label: s })),
    ];
  }, [rows]);

  const active = hasActive(filters);
  const filtered = React.useMemo(() => rows.filter((r) => matches(r, filters)), [rows, filters]);
  const sorted = React.useMemo(() => {
    const isFailed = !sort;
    if (isFailed) return filtered;

    return [...filtered].sort((a, b) => compareRows(a, b, sort));
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
      headline="No licenses match these filters"
      body="Widen the search or reset the status chip to see the full inventory."
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
      headline="No licenses yet"
      body="Licenses you issue on this reseller shard will appear here."
    />
  );

  return (
    <>
      <FilterBar mode="live" hasActiveFilters={active} onClear={clear} ariaLabel="License filters">
        <FilterText
          id="license-search"
          label="Search"
          value={filters.search}
          onChange={(v) => setFilters((prev) => ({ ...prev, search: v }))}
          placeholder="Key, product, tier, environment"
          widthClass="w-72"
        />
        <FilterChipGroup<StatusFilter>
          name="license-status"
          label="Status"
          value={filters.status}
          options={statusOptions}
          onChange={(v) => setFilters((prev) => ({ ...prev, status: v }))}
        />
      </FilterBar>
      <div className="mt-6">
        <DataTable
          rows={pageRows}
          columns={COLUMNS}
          rowKey={(row) => row.LicenseKey}
          page={page}
          pageSize={PAGE_SIZE}
          total={total}
          sort={sort}
          onSortChange={setSort}
          onPageChange={setPage}
          countNoun="license"
          caption="Reseller licenses"
          emptySlot={emptySlot}
        />
      </div>
    </>
  );
}

function StatusPill({ status }: { status: string }) {
  const tone =
    status === "Active"
      ? "bg-emerald-100 text-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-200"
      : status === "Revoked"
        ? "bg-destructive/10 text-destructive"
        : "bg-muted text-muted-foreground";

  return (
    <span
      className={`inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ${tone}`}
    >
      {status || "Unknown"}
    </span>
  );
}

function formatDate(iso: string): string {
  if (iso === "") return "";
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return iso;

  return d.toISOString().slice(0, 10);
}
