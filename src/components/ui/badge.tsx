import * as React from "react";
import { cva, type VariantProps } from "class-variance-authority";

import { cn } from "@/lib/utils";

/**
 * Badge primitive per spec 24-app-ui-design-system/25-component-badge-status.md.
 *
 * Plan 15 step 9 (v0.496.0): badge no longer hand-rolls color-mix recipes.
 * The `@utility chip` (styles.css v0.491.0) owns the tone -> color mapping
 * via `data-tone`, so the closed status-color set (AC-ADS-022) lives in
 * exactly one place. This component picks the right `data-tone` from the
 * intent axis and lets CSS resolve colors, borders, and radii.
 *
 * Geometry (§3): height 24px (`sm`: 20px), inline padding 8px, gap 4px.
 * Tone (§2) closed-set: neutral | info | success | warning | destructive |
 * accent. `info` maps to the primary tone on the chip utility since both
 * use the primary color anchor; keeping the intent name preserves the
 * semantic axis for call sites and StatusBadge composition (AC-BDG-003).
 *
 * Legacy shadcn variants (`default | secondary | destructive | outline`)
 * are preserved as compat shims; they layer classes AFTER `chip` so they
 * override the tone tokens for unmigrated call sites.
 */
const INTENT_TO_TONE = {
  neutral: undefined,
  info: "primary",
  success: "success",
  warning: "warning",
  destructive: "destructive",
  accent: "accent",
} as const;

const badgeVariants = cva(
  cn("chip", "focus:outline-none focus:ring-2 focus:ring-[var(--ring)] focus:ring-offset-2"),
  {
    variants: {
      intent: {
        neutral: "",
        info: "",
        success: "",
        warning: "",
        destructive: "",
        accent: "",
      },
      size: {
        sm: "h-5 text-[11px] px-1.5",
        md: "h-6 text-xs",
      },
      // Legacy shadcn shim: appended after `chip` so it wins for compat.
      variant: {
        default: "text-[var(--primary-foreground)] bg-[var(--primary)] border-transparent",
        secondary: "text-[var(--secondary-foreground)] bg-[var(--secondary)] border-transparent",
        destructive:
          "text-[var(--destructive-foreground,var(--card))] bg-[var(--destructive)] border-transparent",
        outline: "border border-[var(--border)] bg-transparent text-[var(--foreground)]",
      },
    },
    defaultVariants: {
      intent: "neutral",
      size: "md",
    },
  },
);

export type BadgeIntent = NonNullable<VariantProps<typeof badgeVariants>["intent"]>;

export interface BadgeProps
  extends React.HTMLAttributes<HTMLSpanElement>, VariantProps<typeof badgeVariants> {}

function Badge({ className, intent, size, variant, ...props }: BadgeProps) {
  const tone = intent ? INTENT_TO_TONE[intent] : undefined;
  return (
    <span
      data-tone={tone}
      className={cn(badgeVariants({ intent, size, variant }), className)}
      {...props}
    />
  );
}

export { Badge, badgeVariants };
