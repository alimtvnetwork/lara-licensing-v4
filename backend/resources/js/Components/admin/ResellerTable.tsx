// Plan 06 step 66. Admin resellers list ported from the React SPA
// (src/components/admin/reseller-data-table.tsx) onto Inertia.
//
// ResellerController::index() returns the full bounded roster in one
// envelope, so search, status filtering, sorting, and pagination all run
// client-side here. When the backend gains server-side pagination this
// component swaps to query params without touching the page.

import * as React from "react";
import { Link } from "@inertiajs/react";
import { CheckCircle2, XCircle, Search } from "lucide-react";

import { EmptyState } from "@/Components/ui/EmptyState";
import { Button } from "@/Components/ui/Button";
import { cn } from "@/lib/utils";

export interface ResellerRow {
  ResellerId: number;
  ResellerName: string;
  ResellerSlug: string;
  ContactEmail: string;
  IsActive: boolean;
  CreatedAt: string;
  UpdatedAt: string;
}

const PAGE_SIZE = 25;

const dateFormatter = new Intl.DateTimeFormat(undefined, { dateStyle: "medium" });

type StatusFilter = "all" | "active" | "inactive";

const STATUS_OPTIONS: readonly { value: StatusFilter; label: string }[] = [
  { value: "all", label: "All" },
  { value: "active", label: "Active" },
  { value: "inactive", label: "Inactive" },
];

type SortField = "ResellerId" | "ResellerName" | "ContactEmail" | "IsActive" | "CreatedAt";
interface SortState {
  field: SortField;
  direction: "asc" | "desc";
}

interface Filters {
  search: string;
  status: StatusFilter;
}

const EMPTY_FILTERS: Filters = { search: "", status: "all" };

function hasActive(filters: Filters): boolean {
  return filters.search.trim() !== "" || filters.status !== "all";
}

function matches(reseller: ResellerRow, filters: Filters): boolean {
  if (filters.status === "active" && !reseller.IsActive) return false;
  if (filters.status === "inactive" && reseller.IsActive) return false;
  const term = filters.search.trim().toLowerCase();
  if (term === "") return true;
  return (
    reseller.ResellerName.toLowerCase().includes(term) ||
    reseller.ResellerSlug.toLowerCase().includes(term) ||
    reseller.ContactEmail.toLowerCase().includes(term)
  );
}

function compare(a: ResellerRow, b: ResellerRow, sort: SortState): number {
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

const COLUMNS: readonly { field: SortField; header: string; width?: string }[] = [
  { field: "ResellerId", header: "ID", width: "6rem" },
  { field: "ResellerName", header: "Reseller" },
  { field: "ContactEmail", header: "Contact" },
  { field: "IsActive", header: "Status" },
  { field: "CreatedAt", header: "Created" },
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

function formatDate(value: string): string {
  const parsed = Date.parse(value);
  if (Number.isNaN(parsed)) return "unknown";
  return dateFormatter.format(new Date(parsed));
}

export function ResellerTable({ resellers }: { resellers: readonly ResellerRow[] }) {
  const [filters, setFilters] = React.useState<Filters>(EMPTY_FILTERS);
  const [sort, setSort] = React.useState<SortState>({ field: "ResellerName", direction: "asc" });
  const [page, setPage] = React.useState(1);

  const active = hasActive(filters);
  const filtered = React.useMemo(
    () => resellers.filter((r) => matches(r, filters)),
    [resellers, filters],
  );
  const sorted = React.useMemo(() => [...filtered].sort((a, b) => compare(a, b, sort)), [filtered, sort]);
  const total = sorted.length;
  const pageCount = Math.max(1, Math.ceil(total / PAGE_SIZE));
  const pageRows = React.useMemo(() => {
    const start = (page - 1) * PAGE_SIZE;
    return sorted.slice(start, start + PAGE_SIZE);
  }, [sorted, page]);

  React.useEffect(() => {
    setPage(1);
  }, [filters, sort]);

  const clear = () => setFilters(EMPTY_FILTERS);

  const toggleSort = (field: SortField) =>
    setSort((prev) =>
      prev.field === field
        ? { field, direction: prev.direction === "asc" ? "desc" : "asc" }
        : { field, direction: "asc" },
    );

  return (
    <>
      <div
        role="search"
        aria-label="Reseller filters"
        className="flex flex-wrap items-end gap-4 rounded-lg border border-border bg-card p-4"
      >
        <label className="flex flex-col gap-1.5 text-xs font-medium text-muted-foreground" htmlFor="reseller-search">
          Search
          <span className="relative">
            <Search aria-hidden="true" className="pointer-events-none absolute left-2.5 top-2.5 size-4 text-muted-foreground" />
            <input
              id="reseller-search"
              type="search"
              value={filters.search}
              onChange={(e) => setFilters((prev) => ({ ...prev, search: e.target.value }))}
              placeholder="Name, slug, or contact email"
              className="h-9 w-64 rounded-md border border-input bg-background pl-8 pr-3 text-sm text-foreground"
            />
          </span>
        </label>
        <fieldset className="flex flex-col gap-1.5">
          <legend className="text-xs font-medium text-muted-foreground">Status</legend>
          <div className="flex gap-1.5">
            {STATUS_OPTIONS.map((opt) => (
              <button
                key={opt.value}
                type="button"
                aria-pressed={filters.status === opt.value}
                onClick={() => setFilters((prev) => ({ ...prev, status: opt.value }))}
                className={cn(
                  "h-9 rounded-md border px-3 text-sm font-medium transition-colors",
                  filters.status === opt.value
                    ? "border-primary bg-primary text-primary-foreground"
                    : "border-input bg-background hover:bg-accent hover:text-accent-foreground",
                )}
              >
                {opt.label}
              </button>
            ))}
          </div>
        </fieldset>
        {active && (
          <Button type="button" variant="outline" onClick={clear}>
            Clear filters
          </Button>
        )}
      </div>

      <div className="mt-6 overflow-hidden rounded-lg border border-border">
        {pageRows.length === 0 ? (
          <div className="p-6">
            {active ? (
              <EmptyState
                headline="No resellers match these filters"
                body="Widen the search or reset the status chips to see the full roster."
                action={
                  <Button type="button" variant="outline" onClick={clear}>
                    Clear filters
                  </Button>
                }
              />
            ) : (
              <EmptyState
                preset="box"
                headline="No resellers found"
                body="Create the first reseller organization to start issuing licenses at scale."
              />
            )}
          </div>
        ) : (
          <table className="w-full border-collapse text-sm">
            <caption className="sr-only">Licensing Portal resellers</caption>
            <thead className="bg-muted/50">
              <tr>
                {COLUMNS.map((col) => (
                  <th
                    key={col.field}
                    scope="col"
                    style={col.width ? { width: col.width } : undefined}
                    aria-sort={
                      sort.field === col.field
                        ? sort.direction === "asc"
                          ? "ascending"
                          : "descending"
                        : "none"
                    }
                    className="px-4 py-2 text-left text-xs font-semibold uppercase tracking-wide text-muted-foreground"
                  >
                    <button
                      type="button"
                      onClick={() => toggleSort(col.field)}
                      className="inline-flex items-center gap-1 hover:text-foreground"
                    >
                      {col.header}
                      {sort.field === col.field && <span aria-hidden="true">{sort.direction === "asc" ? "\u2191" : "\u2193"}</span>}
                    </button>
                  </th>
                ))}
              </tr>
            </thead>
            <tbody>
              {pageRows.map((row) => (
                <tr key={row.ResellerId} className="border-t border-border hover:bg-muted/30">
                  <td className="px-4 py-2 font-mono text-xs">{row.ResellerId}</td>
                  <td className="px-4 py-2">
                    <Link
                      href={`/admin/resellers/${row.ResellerSlug}`}
                      className="font-medium text-foreground hover:underline"
                    >
                      {row.ResellerName}
                    </Link>
                  </td>
                  <td className="px-4 py-2 text-muted-foreground">{row.ContactEmail || "unknown"}</td>
                  <td className="px-4 py-2">
                    <StatusChip isActive={row.IsActive} />
                  </td>
                  <td className="px-4 py-2 text-muted-foreground" title={row.CreatedAt}>
                    {formatDate(row.CreatedAt)}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </div>

      <div className="mt-4 flex items-center justify-between text-sm text-muted-foreground">
        <span>
          {total} reseller{total === 1 ? "" : "s"}
        </span>
        {pageCount > 1 && (
          <span className="flex items-center gap-2">
            <Button type="button" variant="outline" size="sm" disabled={page <= 1} onClick={() => setPage((p) => p - 1)}>
              Previous
            </Button>
            <span>
              Page {page} of {pageCount}
            </span>
            <Button
              type="button"
              variant="outline"
              size="sm"
              disabled={page >= pageCount}
              onClick={() => setPage((p) => p + 1)}
            >
              Next
            </Button>
          </span>
        )}
      </div>
    </>
  );
}
