// Plan 06 step 66. Admin users list ported from
// src/components/admin/user-data-table.tsx onto Inertia.
// UserController::index() returns a bounded roster, so filtering,
// sorting, and pagination run client-side.

import * as React from "react";
import { Link } from "@inertiajs/react";
import { CheckCircle2, XCircle, Search } from "lucide-react";

import { EmptyState } from "@/Components/ui/EmptyState";
import { Button } from "@/Components/ui/Button";
import { cn } from "@/lib/utils";

export interface UserRow {
  UserId: number;
  Email: string;
  TenantId: number | null;
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

type SortField = "UserId" | "Email" | "IsActive" | "CreatedAt";

function formatDate(value: string): string {
  const parsed = Date.parse(value);
  return Number.isNaN(parsed) ? "unknown" : dateFormatter.format(new Date(parsed));
}

function compare(a: UserRow, b: UserRow, field: SortField, dir: number): number {
  switch (field) {
    case "UserId":
      return (a.UserId - b.UserId) * dir;
    case "Email":
      return a.Email.localeCompare(b.Email) * dir;
    case "IsActive":
      return (Number(a.IsActive) - Number(b.IsActive)) * dir;
    case "CreatedAt":
      return (Date.parse(a.CreatedAt) - Date.parse(b.CreatedAt)) * dir;
    default:
      return 0;
  }
}

const COLUMNS: readonly { field: SortField; header: string; width?: string }[] = [
  { field: "UserId", header: "ID", width: "6rem" },
  { field: "Email", header: "Email" },
  { field: "IsActive", header: "Status" },
  { field: "CreatedAt", header: "Created" },
];

export function UserTable({ users }: { users: readonly UserRow[] }) {
  const [search, setSearch] = React.useState("");
  const [status, setStatus] = React.useState<StatusFilter>("all");
  const [sort, setSort] = React.useState<{ field: SortField; direction: "asc" | "desc" }>({
    field: "Email",
    direction: "asc",
  });
  const [page, setPage] = React.useState(1);

  const active = search.trim() !== "" || status !== "all";

  const rows = React.useMemo(() => {
    const term = search.trim().toLowerCase();
    const dir = sort.direction === "asc" ? 1 : -1;
    return users
      .filter((u) => {
        if (status === "active" && !u.IsActive) return false;
        if (status === "inactive" && u.IsActive) return false;
        if (term === "") return true;
        return u.Email.toLowerCase().includes(term) || String(u.UserId) === term;
      })
      .slice()
      .sort((a, b) => compare(a, b, sort.field, dir));
  }, [users, search, status, sort]);

  const total = rows.length;
  const pageCount = Math.max(1, Math.ceil(total / PAGE_SIZE));
  const pageRows = rows.slice((page - 1) * PAGE_SIZE, (page - 1) * PAGE_SIZE + PAGE_SIZE);

  React.useEffect(() => {
    setPage(1);
  }, [search, status, sort]);

  const clear = () => {
    setSearch("");
    setStatus("all");
  };

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
        aria-label="User filters"
        className="flex flex-wrap items-end gap-4 rounded-lg border border-border bg-card p-4"
      >
        <label className="flex flex-col gap-1.5 text-xs font-medium text-muted-foreground" htmlFor="user-search">
          Search
          <span className="relative">
            <Search aria-hidden="true" className="pointer-events-none absolute left-2.5 top-2.5 size-4 text-muted-foreground" />
            <input
              id="user-search"
              type="search"
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              placeholder="Email or user id"
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
                aria-pressed={status === opt.value}
                onClick={() => setStatus(opt.value)}
                className={cn(
                  "h-9 rounded-md border px-3 text-sm font-medium transition-colors",
                  status === opt.value
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
            <EmptyState
              preset={active ? "plain" : "box"}
              headline={active ? "No users match these filters" : "No users found"}
              body={
                active
                  ? "Widen the search or reset the status chips to see every account."
                  : "Create the first account to start assigning roles."
              }
              action={
                active ? (
                  <Button type="button" variant="outline" onClick={clear}>
                    Clear filters
                  </Button>
                ) : undefined
              }
            />
          </div>
        ) : (
          <table className="w-full border-collapse text-sm">
            <caption className="sr-only">Licensing Portal users</caption>
            <thead className="bg-muted/50">
              <tr>
                {COLUMNS.map((col) => (
                  <th
                    key={col.field}
                    scope="col"
                    style={col.width ? { width: col.width } : undefined}
                    aria-sort={
                      sort.field === col.field ? (sort.direction === "asc" ? "ascending" : "descending") : "none"
                    }
                    className="px-4 py-2 text-left text-xs font-semibold uppercase tracking-wide text-muted-foreground"
                  >
                    <button type="button" onClick={() => toggleSort(col.field)} className="inline-flex items-center gap-1 hover:text-foreground">
                      {col.header}
                      {sort.field === col.field && <span aria-hidden="true">{sort.direction === "asc" ? "\u2191" : "\u2193"}</span>}
                    </button>
                  </th>
                ))}
              </tr>
            </thead>
            <tbody>
              {pageRows.map((row) => (
                <tr key={row.UserId} className="border-t border-border hover:bg-muted/30">
                  <td className="px-4 py-2 font-mono text-xs">{row.UserId}</td>
                  <td className="px-4 py-2">
                    <Link href={`/admin/users/${row.UserId}`} className="font-medium text-foreground hover:underline">
                      {row.Email}
                    </Link>
                  </td>
                  <td className="px-4 py-2">
                    <span className="inline-flex items-center gap-1.5 text-xs font-medium">
                      {row.IsActive ? <CheckCircle2 aria-hidden="true" className="size-4" /> : <XCircle aria-hidden="true" className="size-4" />}
                      {row.IsActive ? "Active" : "Inactive"}
                    </span>
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
          {total} user{total === 1 ? "" : "s"}
        </span>
        {pageCount > 1 && (
          <span className="flex items-center gap-2">
            <Button type="button" variant="outline" size="sm" disabled={page <= 1} onClick={() => setPage((p) => p - 1)}>
              Previous
            </Button>
            <span>
              Page {page} of {pageCount}
            </span>
            <Button type="button" variant="outline" size="sm" disabled={page >= pageCount} onClick={() => setPage((p) => p + 1)}>
              Next
            </Button>
          </span>
        )}
      </div>
    </>
  );
}
