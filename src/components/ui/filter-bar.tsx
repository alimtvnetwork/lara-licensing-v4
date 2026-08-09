// Plan 09 step 26. Shared filter-bar primitive extracted from
// admin.audit.tsx so every admin list route (resellers, users, licenses,
// serials, quota requests, audit, updates) can render a consistent
// filter surface without hand-rolling draft state + Apply/Clear layout.
//
// Two modes:
//   mode="submit" -> renders a <form>; fields are held in a caller-owned
//     draft, Apply commits, Clear resets. Used by audit + license lists
//     where filter changes trigger a new server-side query.
//   mode="live"   -> renders a <div>; no Apply button; each field change
//     is committed by the caller immediately. Used by client-side lists
//     (resellers) where filtering is in-memory.
//
// The primitive owns layout, tokens, focus semantics, and the Clear
// button copy. Callers own the field composition and applied state.

import * as React from "react";

import { cn } from "@/lib/utils";

export type FilterBarMode = "submit" | "live";

export interface FilterBarProps {
  mode?: FilterBarMode;
  hasActiveFilters: boolean;
  onClear: () => void;
  onApply?: () => void;
  children: React.ReactNode;
  className?: string;
  ariaLabel?: string;
}

/**
 * Container. Renders as `<form>` in submit mode with an Apply button, or
 * `<div role="group">` in live mode with only Clear. Clear is always
 * rendered but disabled when `hasActiveFilters` is false so its position
 * stays stable and screen readers can still discover it.
 */
export function FilterBar(props: FilterBarProps) {
  const {
    mode = "submit",
    hasActiveFilters,
    onClear,
    onApply,
    children,
    className,
    ariaLabel = "Filters",
  } = props;

  const controls = (
    <>
      {mode === "submit" ? (
        <button
          type="submit"
          className="focus-ring inline-flex h-10 items-center rounded-md bg-primary px-4 text-sm font-medium text-primary-foreground hover:bg-primary/90"
        >
          Apply
        </button>
      ) : null}
      <button
        type="button"
        onClick={onClear}
        disabled={!hasActiveFilters}
        className="focus-ring inline-flex h-10 items-center rounded-md border border-input px-4 text-sm font-medium surface-hover disabled:cursor-not-allowed disabled:opacity-50"
      >
        Clear
      </button>
    </>
  );

  const shell = "mt-6 flex flex-wrap items-end gap-3 rounded-md border border-border bg-card p-4";

  if (mode === "submit") {
    return (
      <form
        aria-label={ariaLabel}
        onSubmit={(event) => {
          event.preventDefault();
          onApply?.();
        }}
        className={cn(shell, className)}
        noValidate
      >
        {children}
        {controls}
      </form>
    );
  }

  return (
    <div role="group" aria-label={ariaLabel} className={cn(shell, className)}>
      {children}
      {controls}
    </div>
  );
}

/**
 * Text/numeric input with label, shared style with the audit primitive
 * it replaces. `inputMode` narrows the mobile keyboard for numeric ids.
 */
export interface FilterTextProps {
  id: string;
  label: string;
  value: string;
  onChange: (next: string) => void;
  placeholder?: string;
  inputMode?: "numeric" | "text";
  widthClass?: string;
}

export function FilterText(props: FilterTextProps) {
  const {
    id,
    label,
    value,
    onChange,
    placeholder,
    inputMode = "text",
    widthClass = "w-48",
  } = props;
  return (
    <div className="flex flex-col gap-1.5">
      <label htmlFor={id} className="text-xs font-medium text-muted-foreground">
        {label}
      </label>
      <input
        id={id}
        name={id}
        value={value}
        onChange={(event) => onChange(event.target.value)}
        placeholder={placeholder}
        inputMode={inputMode}
        autoComplete="off"
        spellCheck={false}
        className={cn(
          "h-10 rounded-md border border-input bg-background px-3 font-mono text-sm outline-none focus-visible:ring-2 focus-visible:ring-ring",
          widthClass,
        )}
      />
    </div>
  );
}

/**
 * Chip radio group. Exactly one option is active. Options are declared
 * by the caller so closed-set typing stays at the callsite.
 */
export interface FilterChipOption<TValue extends string> {
  value: TValue;
  label: string;
}

export interface FilterChipGroupProps<TValue extends string> {
  label: string;
  value: TValue;
  options: readonly FilterChipOption<TValue>[];
  onChange: (next: TValue) => void;
  name: string;
}

export function FilterChipGroup<TValue extends string>(props: FilterChipGroupProps<TValue>) {
  const { label, value, options, onChange, name } = props;
  return (
    <fieldset className="flex flex-col gap-1.5">
      <legend className="text-xs font-medium text-muted-foreground">{label}</legend>
      <div role="radiogroup" aria-label={label} className="flex flex-wrap gap-1.5">
        {options.map((option) => {
          const active = option.value === value;
          return (
            <label
              key={option.value}
              className={cn(
                "focus-ring inline-flex h-10 cursor-pointer items-center rounded-md border px-3 text-sm font-medium transition-colors",
                active
                  ? "border-primary bg-primary text-primary-foreground"
                  : "border-input bg-background text-foreground surface-hover",
              )}
            >
              <input
                type="radio"
                name={name}
                value={option.value}
                checked={active}
                onChange={() => onChange(option.value)}
                className="sr-only"
              />
              {option.label}
            </label>
          );
        })}
      </div>
    </fieldset>
  );
}
