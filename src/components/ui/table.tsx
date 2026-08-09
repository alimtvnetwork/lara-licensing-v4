// spec/24-app-ui-design-system/24-component-table.md §2, §5, §6, §13.
// Primitive-level refit only. The DataTable orchestrator (URL state,
// column config, RecordCard fallback) is a follow-up (Plan 07 Step 20b).

import * as React from "react";
import { cn } from "@/lib/utils";
import { Icon } from "@/components/ui/icon";

const Table = React.forwardRef<HTMLTableElement, React.HTMLAttributes<HTMLTableElement>>(
  ({ className, ...props }, ref) => (
    <div className="relative w-full overflow-auto">
      <table
        ref={ref}
        className={cn(
          "w-full caption-bottom text-sm border-collapse",
          "[&_[data-align=end]]:text-right [&_[data-align=end]]:[font-variant-numeric:tabular-nums]",
          "[&_[data-align=center]]:text-center",
          className,
        )}
        {...props}
      />
    </div>
  ),
);
Table.displayName = "Table";

const TableHeader = React.forwardRef<
  HTMLTableSectionElement,
  React.HTMLAttributes<HTMLTableSectionElement>
>(({ className, ...props }, ref) => (
  <thead
    ref={ref}
    className={cn(
      // v0.500.0 (SS-02): sticky within the DataTable scroll container so
      // long lists keep the header anchored. `bg-card` matches the wrapper,
      // and a hairline bottom shadow replaces the row border to survive
      // sub-pixel rounding under sticky positioning.
      "sticky top-0 z-10 bg-[var(--card)]",
      "[&_tr]:border-b-0",
      "shadow-[inset_0_-1px_0_0_var(--border)]",
      className,
    )}
    {...props}
  />
));
TableHeader.displayName = "TableHeader";

const TableBody = React.forwardRef<
  HTMLTableSectionElement,
  React.HTMLAttributes<HTMLTableSectionElement>
>(({ className, ...props }, ref) => (
  <tbody
    ref={ref}
    className={cn("[&_tr:last-child]:border-0 aria-busy:opacity-60", className)}
    {...props}
  />
));
TableBody.displayName = "TableBody";

const TableFooter = React.forwardRef<
  HTMLTableSectionElement,
  React.HTMLAttributes<HTMLTableSectionElement>
>(({ className, ...props }, ref) => (
  <tfoot
    ref={ref}
    className={cn(
      // Spec §10: footer uses tabular-nums for the range indicator.
      "border-t border-border bg-[color-mix(in_oklab,var(--muted)_40%,transparent)]",
      "font-medium [font-variant-numeric:tabular-nums] [&>tr]:last:border-b-0",
      className,
    )}
    {...props}
  />
));
TableFooter.displayName = "TableFooter";

const TableRow = React.forwardRef<HTMLTableRowElement, React.HTMLAttributes<HTMLTableRowElement>>(
  ({ className, ...props }, ref) => (
    <tr
      ref={ref}
      className={cn(
        "border-b border-border transition-[background-color,box-shadow] duration-150",
        // v0.501.0 (SS-02, Plan 15 step 14): warmer accent-tinted hover
        // replaces the flat muted tint so rows respond with the same accent
        // family used by primary CTAs and focus rings. A hairline inset on
        // hover cues depth without shifting layout. Focus-within highlights
        // the active keyboard row so tab-through has a visible anchor.
        "hover:bg-[color-mix(in_oklab,var(--accent)_10%,transparent)]",
        "hover:shadow-[inset_0_0_0_1px_color-mix(in_oklab,var(--accent)_18%,transparent)]",
        "focus-within:bg-[color-mix(in_oklab,var(--accent)_12%,transparent)]",
        "data-[state=selected]:bg-[color-mix(in_oklab,var(--primary)_14%,transparent)]",
        "data-[state=selected]:shadow-[inset_0_0_0_1px_color-mix(in_oklab,var(--primary)_28%,transparent)]",
        className,
      )}
      {...props}
    />
  ),
);
TableRow.displayName = "TableRow";

type TableHeadProps = Omit<React.ThHTMLAttributes<HTMLTableCellElement>, "align"> & {
  // Spec §5. Cell alignment. `end` also enables tabular-nums (§5 numeric rule).
  // Native `align` on <th> is intentionally shadowed; consumers use data-align.
  align?: "start" | "end" | "center";
  // Spec §6. `aria-sort` MUST live on the <th>, not the button (§13).
  sort?: "asc" | "desc" | "none";
};

const TableHead = React.forwardRef<HTMLTableCellElement, TableHeadProps>(
  ({ className, align = "start", sort, ...props }, ref) => (
    <th
      ref={ref}
      scope="col"
      data-align={align}
      aria-sort={
        sort === "asc"
          ? "ascending"
          : sort === "desc"
            ? "descending"
            : sort === "none"
              ? "none"
              : undefined
      }
      className={cn(
        // Spec §2 header row height uses --space-* tokens.
        "h-[var(--space-12)] px-[var(--space-2)] align-middle",
        "text-xs font-medium uppercase tracking-wide text-muted-foreground",
        className,
      )}
      {...props}
    />
  ),
);
TableHead.displayName = "TableHead";

// Spec §6 + §13. Sortable header button. Parent <th> carries aria-sort; the
// button itself only announces the cycle intent via aria-label.
type TableSortButtonProps = React.ButtonHTMLAttributes<HTMLButtonElement> & {
  direction: "asc" | "desc" | "none";
};

const TableSortButton = React.forwardRef<HTMLButtonElement, TableSortButtonProps>(
  ({ direction, className, children, ...props }, ref) => (
    <button
      ref={ref}
      type="button"
      className={cn(
        "inline-flex items-center gap-[var(--space-1)] uppercase tracking-wide",
        "text-xs font-medium text-muted-foreground",
        "hover:text-foreground transition-[color,box-shadow] duration-150",
        // v0.500.0 (SS-02): align focus to --ring-focus-strong shared with
        // Button/Input/Dialog close, so the whole app renders one focus ring.
        "focus-visible:outline-none focus-visible:shadow-[var(--ring-focus-strong)]",
        "rounded-[var(--radius-sm)]",
        className,
      )}
      {...props}
    >
      {children}
      {direction === "asc" ? (
        <Icon concept="SortAscending" size="xs" />
      ) : direction === "desc" ? (
        <Icon concept="SortDescending" size="xs" />
      ) : null}
    </button>
  ),
);
TableSortButton.displayName = "TableSortButton";

type TableCellProps = Omit<React.TdHTMLAttributes<HTMLTableCellElement>, "align"> & {
  align?: "start" | "end" | "center";
};

const TableCell = React.forwardRef<HTMLTableCellElement, TableCellProps>(
  ({ className, align = "start", ...props }, ref) => (
    <td
      ref={ref}
      data-align={align}
      className={cn("p-[var(--space-2)] align-middle", className)}
      {...props}
    />
  ),
);
TableCell.displayName = "TableCell";

const TableCaption = React.forwardRef<
  HTMLTableCaptionElement,
  React.HTMLAttributes<HTMLTableCaptionElement>
>(({ className, ...props }, ref) => (
  <caption
    ref={ref}
    className={cn("mt-[var(--space-4)] text-sm text-muted-foreground", className)}
    {...props}
  />
));
TableCaption.displayName = "TableCaption";

export {
  Table,
  TableHeader,
  TableBody,
  TableFooter,
  TableHead,
  TableRow,
  TableCell,
  TableCaption,
  TableSortButton,
};
