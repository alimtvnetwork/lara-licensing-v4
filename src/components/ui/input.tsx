import * as React from "react";

import { cn } from "@/lib/utils";

/**
 * Input primitive per spec 24-app-ui-design-system/18-component-input.md.
 *
 * Geometry (spec §2): block-size 40px, inline padding --space-3, border 1px
 * solid var(--border), radius --radius-md, Body/M typography.
 *
 * States (spec §5):
 * - focus-visible: 2px outline in var(--ring) with 2px offset (matches Button
 *   focus ring, AC-INP-010).
 * - aria-invalid="true": border shifts to var(--destructive).
 * - disabled: opacity 0.5, cursor not-allowed, muted background.
 * - readOnly: muted background, cursor default, aria-readonly="true".
 *
 * Password variant (spec §8) is rendered with var(--font-mono) via
 * data-attribute selector so the primitive stays token-driven.
 *
 * The primitive is a bare <input>; the Field composition wrapper that
 * carries label + helper + error nodes (spec §3) lives in
 * `src/components/ui/field.tsx` and is the sanctioned way to compose an
 * Input inside a form (AC-INP-001, AC-INP-003).
 */

const Input = React.forwardRef<HTMLInputElement, React.ComponentProps<"input">>(
  ({ className, type, readOnly, ...props }, ref) => {
    return (
      <input
        type={type}
        readOnly={readOnly}
        aria-readonly={readOnly ? "true" : undefined}
        data-input-type={type}
        className={cn(
          "flex h-10 w-full rounded-[var(--radius-md)] border border-[var(--border)]",
          "bg-transparent px-3 py-2 text-sm text-[var(--foreground)] shadow-sm",
          "transition-[color,background-color,border-color,box-shadow]",
          "duration-[var(--motion-duration-xs)] ease-[var(--motion-easing-standard)]",
          "placeholder:text-[var(--muted-foreground)]",
          "file:border-0 file:bg-transparent file:text-sm file:font-medium file:text-[var(--foreground)]",
          // v0.497.0: focus uses --ring-focus-strong box-shadow to match the primary
          // CTA (v0.495.0). The default 2-ring pair is disabled so we do not double up.
          "focus-visible:outline-none focus-visible:ring-0 focus-visible:ring-offset-0",
          "focus-visible:border-[var(--ring)] focus-visible:shadow-[var(--ring-focus-strong)]",
          "aria-[invalid=true]:border-[var(--destructive)]",
          "aria-[invalid=true]:focus-visible:shadow-[0_0_0_3px_color-mix(in_oklab,var(--destructive)_35%,transparent)]",
          "disabled:cursor-not-allowed disabled:opacity-50 disabled:bg-[var(--muted)]",
          "read-only:bg-[var(--muted)] read-only:cursor-default",
          "data-[input-type=password]:font-mono",
          className,
        )}
        ref={ref}
        {...props}
      />
    );
  },
);
Input.displayName = "Input";

export { Input };
