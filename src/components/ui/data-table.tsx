// spec/24-app-ui-design-system/24-component-table.md §2..§10.
// DataTable orchestrator. Server pagination, single-column sort,
// typed column config, empty/loading/error slots, sticky header, and
// pagination footer. URL state wiring is opt-in via useDataTableSearch
// (see src/hooks/use-data-table-search.ts).
//
// Non-goals in this cut (deferred to later Plan 07 steps):
//   filter chips, column visibility menu, RecordCard fallback, refresh
//   button. These land alongside the blueprint route rewrites so the
//   diff stays reviewable.

import * as React from "react";
import {
  Table,
  TableBody,
  TableCell,
  TableFooter,
  TableHead,
  TableHeader,
  TableRow,
  TableSortButton,
} from "@/components/ui/table";
import { cn } from "@/lib/utils";
import { pluralCount, type PluralNoun } from "@/lib/copy";

export type ColumnAlign = "start" | "end" | "center";

export interface LaraColumn<Row> {
  field: string;
  header: string;
  align?: ColumnAlign;
  sortable?: boolean;
  width?: string;
  render: (row: Row) => React.ReactNode;
}

export type SortDirection = "asc" | "desc" | "none";

export interface SortState {
  field: string;
  direction: Exclude<SortDirection, "none">;
}

export interface DataTableProps<Row> {
  rows: readonly Row[];
  columns: readonly LaraColumn<Row>[];
  rowKey: (row: Row) => string;
  page: number;
  pageSize: number;
  total: number;
  sort?: SortState | null;
  onSortChange?: (next: SortState | null) => void;
  onPageChange: (page: number) => void;
  status?: "idle" | "loading" | "error";
  emptySlot?: React.ReactNode;
  errorSlot?: React.ReactNode;
  countNoun: PluralNoun;
  caption?: string;
}

function directionFor<Row>(
  col: LaraColumn<Row>,
  sort: SortState | null | undefined,
): SortDirection {
  const isFailed = !col.sortable;
  if (isFailed) return "none";
  if (!sort || sort.field !== col.field) return "none";
  return sort.direction;
}

function cycleSort(current: SortDirection): SortDirection {
  if (current === "none") return "asc";
  if (current === "asc") return "desc";
  return "none";
}

function nextSortState(field: string, current: SortDirection): SortState | null {
  const next = cycleSort(current);
  if (next === "none") return null;
  return { field, direction: next };
}

function pageRange(page: number, pageSize: number, total: number) {
  if (total === 0) return { from: 0, to: 0 };
  const from = (page - 1) * pageSize + 1;
  const to = Math.min(page * pageSize, total);
  return { from, to };
}

function isLastPage(page: number, pageSize: number, total: number): boolean {
  return page * pageSize >= total;
}

export function DataTable<Row>(props: DataTableProps<Row>) {
  const {
    rows,
    columns,
    rowKey,
    page,
    pageSize,
    total,
    sort,
    onSortChange,
    onPageChange,
    status = "idle",
    emptySlot,
    errorSlot,
    countNoun,
    caption,
  } = props;
  const handleSort = (col: LaraColumn<Row>) => {
    if (!col.sortable || !onSortChange) return;
    onSortChange(nextSortState(col.field, directionFor(col, sort)));
  };
  const isEmpty = status === "idle" && rows.length === 0;
  const isError = status === "error";
  const busy = status === "loading";
  return (
    <div
      className={cn(
        // v0.500.0 (SS-02): compose the shared elevated-surface recipe
        // (hairline inset + elevation-1) so DataTables adopt the same
        // depth tier as flyouts and one below dialog/sheet panels.
        // `overflow-hidden` clips the inner scroll container to the
        // rounded corners so the sticky <thead> stays inside the shell.
        "rounded-[var(--radius-lg)] bg-[var(--card)] overflow-hidden",
        "shadow-[var(--shadow-inset-hairline),var(--shadow-1)]",
      )}
      data-testid="data-table"
    >
      <Table>
        {caption ? (
          <caption className="sr-only" data-testid="data-table-caption">
            {caption}
          </caption>
        ) : null}
        <TableHeader>
          <TableRow>
            {columns.map((col) => (
              <TableHead
                key={col.field}
                align={col.align ?? "start"}
                sort={directionFor(col, sort)}
                style={col.width ? { width: col.width } : undefined}
              >
                {col.sortable ? (
                  <TableSortButton
                    direction={directionFor(col, sort)}
                    onClick={() => handleSort(col)}
                    aria-label={`Sort by ${col.header}`}
                  >
                    {col.header}
                  </TableSortButton>
                ) : (
                  col.header
                )}
              </TableHead>
            ))}
          </TableRow>
        </TableHeader>
        <TableBody aria-busy={busy || undefined}>
          {isError ? (
            <DataTableStateRow columns={columns.length}>
              {errorSlot ?? "Failed to load."}
            </DataTableStateRow>
          ) : isEmpty ? (
            <DataTableStateRow columns={columns.length}>
              {emptySlot ?? "No results."}
            </DataTableStateRow>
          ) : (
            rows.map((row) => (
              <TableRow key={rowKey(row)} data-row-key={rowKey(row)}>
                {columns.map((col) => (
                  <TableCell key={col.field} align={col.align ?? "start"}>
                    {col.render(row)}
                  </TableCell>
                ))}
              </TableRow>
            ))
          )}
        </TableBody>
        <TableFooter>
          <TableRow>
            <TableCell colSpan={columns.length}>
              <DataTableFooterBar
                page={page}
                pageSize={pageSize}
                total={total}
                countNoun={countNoun}
                onPageChange={onPageChange}
              />
            </TableCell>
          </TableRow>
        </TableFooter>
      </Table>
    </div>
  );
}

interface DataTableStateRowProps {
  columns: number;
  children: React.ReactNode;
}

function DataTableStateRow(props: DataTableStateRowProps) {
  return (
    <TableRow data-testid="data-table-state">
      <TableCell
        colSpan={props.columns}
        align="center"
        className={cn("text-muted-foreground", "py-[var(--space-8)]")}
      >
        {props.children}
      </TableCell>
    </TableRow>
  );
}

interface FooterBarProps {
  page: number;
  pageSize: number;
  total: number;
  countNoun: PluralNoun;
  onPageChange: (page: number) => void;
}

function DataTableFooterBar(props: FooterBarProps) {
  const { page, pageSize, total, countNoun, onPageChange } = props;
  const range = pageRange(page, pageSize, total);
  const disablePrev = page <= 1;
  const disableNext = isLastPage(page, pageSize, total);
  return (
    <div
      className="flex items-center justify-between gap-[var(--space-4)] text-xs text-muted-foreground"
      data-testid="data-table-footer"
    >
      <span>
        {total === 0
          ? pluralCount(0, countNoun)
          : `${range.from} to ${range.to} of ${pluralCount(total, countNoun)}`}
      </span>
      <div className="flex items-center gap-[var(--space-2)]">
        <FooterPageButton
          disabled={disablePrev}
          onClick={() => onPageChange(page - 1)}
          label="Previous page"
        >
          Prev
        </FooterPageButton>
        <FooterPageButton
          disabled={disableNext}
          onClick={() => onPageChange(page + 1)}
          label="Next page"
        >
          Next
        </FooterPageButton>
      </div>
    </div>
  );
}

interface FooterPageButtonProps extends React.ButtonHTMLAttributes<HTMLButtonElement> {
  label: string;
}

function FooterPageButton({ label, children, className, ...rest }: FooterPageButtonProps) {
  return (
    <button
      type="button"
      aria-label={label}
      className={cn(
        "inline-flex h-[var(--space-8)] items-center rounded-[var(--radius-sm)]",
        "border border-border bg-background px-[var(--space-2)] text-xs",
        "text-foreground hover:bg-muted disabled:opacity-50 disabled:cursor-not-allowed",
        "transition-[color,background-color,box-shadow] duration-150",
        // v0.500.0 (SS-02): shared strong-ring focus token.
        "focus-visible:outline-none focus-visible:shadow-[var(--ring-focus-strong)]",
        className,
      )}
      {...rest}
    >
      {children}
    </button>
  );
}
