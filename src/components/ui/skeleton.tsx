// Plan 09 step 93. Fluid Skeleton primitive.
//
// Root cause of the pre-v0.305.0 shape: the stock shadcn one-liner used
// `bg-primary/10` which shifts hue with the accent, has no size presets,
// and cannot be composed into row/stat/card placeholders. Fluid variants
// below anchor the surface color to `--muted` (neutral, palette-safe) and
// expose the four presets every list/detail loading state actually needs.

import type { HTMLAttributes } from "react";

import { cn } from "@/lib/utils";

export type SkeletonVariant = "text" | "title" | "avatar" | "row" | "stat" | "card";

const VARIANT_CLASSES: Record<SkeletonVariant, string> = {
  text: "h-3.5 w-full rounded-sm",
  title: "h-6 w-2/3 rounded-md",
  avatar: "h-9 w-9 rounded-full",
  row: "h-10 w-full rounded-md",
  stat: "h-24 w-full rounded-lg",
  card: "h-40 w-full rounded-lg",
};

export type SkeletonProps = HTMLAttributes<HTMLDivElement> & {
  /** Preset silhouette. Defaults to `text`. */
  variant?: SkeletonVariant;
};

/**
 * Fluid Skeleton. `variant` picks a preset silhouette; `className` can override.
 * `role="status"` + `aria-live="polite"` announce hydration to assistive tech
 * once, without spamming updates as sibling skeletons mount.
 */
export function Skeleton({ variant = "text", className, ...rest }: SkeletonProps) {
  return (
    <div
      role="status"
      aria-live="polite"
      aria-label="Loading"
      className={cn(
        "relative overflow-hidden bg-muted/60 shimmer",
        VARIANT_CLASSES[variant],
        className,
      )}
      {...rest}
    />
  );
}

/** Convenience composition: a row of N text skeletons stacked vertically. */
export function SkeletonList({ rows = 3, className }: { rows?: number; className?: string }) {
  return (
    <div className={cn("grid gap-2", className)} data-testid="skeleton-list">
      {Array.from({ length: rows }).map((_, i) => (
        <Skeleton key={i} variant="row" />
      ))}
    </div>
  );
}
