// Plan 09 step 44. Admin app-updates list refit onto DataTable + FilterBar.
//
// Root cause this addresses: `admin.app-updates.tsx` shipped in v0.294.0
// with a legacy scaffold `<table>` (no sort, no filter surface, no
// pagination cap) and a raw `window.confirm()` on the Yank action. The
// scaffold diverged from every other admin list (resellers/users/audit)
// and the `window.confirm()` bypassed the Spec 24 §7.5 lineage signal,
// leaving impersonated Admins with no on-screen audit cue before a
// destructive yank.
//
// Filters are live (mode="live") because the dataset is already fully in
// memory (bounded by AppUpdates.LimitPerProduct server-side). When the
// backend adds cursor pagination, swap to server-side by moving `filters`
// into query keys and dropping the client-side slice.

import * as React from "react";
import { useMutation, useQueryClient } from "@tanstack/react-query";
import { Ban } from "lucide-react";

import { DataTable, type LaraColumn, type SortState } from "../ui/data-table";
import { EmptyState } from "../ui/empty-state";
import { FilterBar, FilterChipGroup, FilterText } from "../ui/filter-bar";
import { StatusBadge } from "../shell/StatusBadge";
import { LineageBadge } from "./lineage-badge";
import { formatLaraApiError } from "../../lib/lara-api-error";
import { yankAppUpdate, type AppUpdate } from "../../lib/lara-app-updates";

const PAGE_SIZE = 25;

type StateFilter = "all" | "published" | "yanked";

const STATE_OPTIONS = [
  { value: "all", label: "All" },
  { value: "published", label: "Published" },
  { value: "yanked", label: "Yanked" },
] as const satisfies readonly { value: StateFilter; label: string }[];

interface Filters {
  search: string;
  state: StateFilter;
}

const EMPTY_FILTERS: Filters = { search: "", state: "all" };

function hasActive(filters: Filters): boolean {
  return filters.search.trim() !== "" || filters.state !== "all";
}

function matches(row: AppUpdate, filters: Filters): boolean {
  if (filters.state === "published" && row.IsYanked) return false;
  if (filters.state === "yanked" && !row.IsYanked) return false;
  const term = filters.search.trim().toLowerCase();
  if (term === "") return true;

  return (
    row.Version.toLowerCase().includes(term) ||
    row.MinRequiredVersion.toLowerCase().includes(term) ||
    row.Product.toLowerCase().includes(term)
  );
}

function compare(a: AppUpdate, b: AppUpdate, sort: SortState): number {
  const dir = sort.direction === "asc" ? 1 : -1;
  switch (sort.field) {
    case "Version":
      return a.Version.localeCompare(b.Version, undefined, { numeric: true }) * dir;
    case "IsYanked":
      return (Number(a.IsYanked) - Number(b.IsYanked)) * dir;
    case "MinRequiredVersion":
      return (
        a.MinRequiredVersion.localeCompare(b.MinRequiredVersion, undefined, {
          numeric: true,
        }) * dir
      );
    case "PublishedAt": {
      const av = a.PublishedAt === null ? 0 : Date.parse(a.PublishedAt);
      const bv = b.PublishedAt === null ? 0 : Date.parse(b.PublishedAt);

      return (av - bv) * dir;
    }
    default:
      return 0;
  }
}

export function AppUpdatesDataTable({ rows }: { rows: readonly AppUpdate[] }) {
  const [filters, setFilters] = React.useState<Filters>(EMPTY_FILTERS);
  const [sort, setSort] = React.useState<SortState | null>({
    field: "PublishedAt",
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

  const columns: readonly LaraColumn<AppUpdate>[] = React.useMemo(
    () => [
      {
        field: "Version",
        header: "Version",
        sortable: true,
        render: (row) => <span className="font-mono text-sm">{row.Version}</span>,
      },
      {
        field: "IsYanked",
        header: "State",
        sortable: true,
        render: (row) => (
          <StatusBadge
            tone={row.IsYanked ? "destructive" : "success"}
            label={row.IsYanked ? "Yanked" : "Published"}
          />
        ),
      },
      {
        field: "MinRequiredVersion",
        header: "Min required",
        sortable: true,
        render: (row) => (
          <span className="font-mono text-xs text-muted-foreground">{row.MinRequiredVersion}</span>
        ),
      },
      {
        field: "PublishedAt",
        header: "Published",
        sortable: true,
        render: (row) => (
          <span className="font-mono text-xs text-muted-foreground">{row.PublishedAt ?? "-"}</span>
        ),
      },
      {
        field: "Assets",
        header: "Assets",
        render: (row) => <AssetsCell row={row} />,
      },
      {
        field: "actions",
        header: "Actions",
        align: "end",
        render: (row) =>
          row.IsYanked ? null : <YankControl product={row.Product} version={row.Version} />,
      },
    ],
    [],
  );

  const emptySlot = active ? (
    <EmptyState
      preset="search"
      headline="No builds match these filters"
      body="Widen the search or reset the state chip to see the full publish history."
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
      headline="No builds published yet"
      body="Publish your first lara-cli release to make it visible to Stable-channel clients."
    />
  );

  return (
    <>
      <FilterBar
        mode="live"
        hasActiveFilters={active}
        onClear={clear}
        ariaLabel="App updates filters"
      >
        <FilterText
          id="app-updates-search"
          label="Search"
          value={filters.search}
          onChange={(v) => setFilters((prev) => ({ ...prev, search: v }))}
          placeholder="Version or product"
          widthClass="w-64"
        />
        <FilterChipGroup<StateFilter>
          name="app-updates-state"
          label="State"
          value={filters.state}
          options={STATE_OPTIONS}
          onChange={(v) => setFilters((prev) => ({ ...prev, state: v }))}
        />
      </FilterBar>
      <div className="mt-6">
        <DataTable
          rows={pageRows}
          columns={columns}
          rowKey={(row) => String(row.AppUpdateId)}
          page={page}
          pageSize={PAGE_SIZE}
          total={total}
          sort={sort}
          onSortChange={setSort}
          onPageChange={setPage}
          countNoun="update"
          caption="lara-cli publish history"
          emptySlot={emptySlot}
        />
      </div>
    </>
  );
}

function AssetsCell({ row }: { row: AppUpdate }) {
  if (row.Assets.length === 0) {
    return <span className="text-xs text-muted-foreground">No finalized assets</span>;
  }

  return (
    <ul className="space-y-0.5 text-xs">
      {row.Assets.map((a) => (
        <li key={a.Platform} className="font-mono">
          {a.Platform}{" "}
          <span className="text-muted-foreground">
            ({(a.SizeBytes / 1024).toFixed(1)} KiB, sha256 {a.Sha256.slice(0, 12)}...)
          </span>
          {a.HasSignature ? <span className="ml-1 text-primary">signed</span> : null}
        </li>
      ))}
    </ul>
  );
}

/**
 * Inline lineage-aware yank confirm. Mirrors the reseller-edit-form
 * pattern established in v0.288.0 so an impersonated Admin sees the
 * acting principal at the moment they authorize a destructive
 * self-update mutation.
 */
function YankControl({ product, version }: { product: string; version: string }) {
  const queryClient = useQueryClient();
  const [confirming, setConfirming] = React.useState(false);
  const [error, setError] = React.useState<string | null>(null);
  const mutation = useMutation({
    mutationFn: () =>
      yankAppUpdate({
        Product: product,
        Version: version,
        IdempotencyKey: `yank-${product}-${version}-${crypto.randomUUID()}`,
      }),
    onSuccess: () => {
      setConfirming(false);
      void queryClient.invalidateQueries({
        queryKey: ["LaraApi", "Admin", "AppUpdates"],
      });
    },
    onError: (err) => setError(formatLaraApiError(err)),
  });

  const isFailed = !confirming;
  if (isFailed) {
    return (
      <button
        type="button"
        onClick={() => {
          setError(null);
          setConfirming(true);
        }}
        className="focus-ring inline-flex h-8 items-center gap-1.5 rounded-md border border-destructive/40 px-2.5 text-xs font-medium text-destructive hover:bg-destructive/5"
      >
        <Ban aria-hidden="true" className="size-3.5" />
        Yank
      </button>
    );
  }

  return (
    <div className="flex flex-col items-end gap-2 rounded-md border border-destructive/40 bg-destructive/5 p-2 text-xs">
      <LineageBadge />
      <p className="max-w-[16rem] text-right text-destructive">
        Yank {product} {version}? Clients that already downloaded remain unaffected; new manifest
        reads will skip this version.
      </p>
      <div className="flex items-center gap-2">
        <button
          type="button"
          onClick={() => {
            setError(null);
            setConfirming(false);
          }}
          disabled={mutation.isPending}
          className="focus-ring inline-flex h-7 items-center rounded-md border border-input px-2 text-xs surface-hover"
        >
          Cancel
        </button>
        <button
          type="button"
          onClick={() => {
            setError(null);
            mutation.mutate();
          }}
          disabled={mutation.isPending}
          className="focus-ring inline-flex h-7 items-center gap-1.5 rounded-md bg-destructive px-2 text-xs font-semibold text-destructive-foreground hover:bg-destructive/90 disabled:opacity-60"
        >
          <Ban aria-hidden="true" className="size-3.5" />
          {mutation.isPending ? "Yanking..." : "Confirm yank"}
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
