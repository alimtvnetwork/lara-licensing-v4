import * as React from "react";

import { cn } from "@/lib/utils";

/**
 * Textarea primitive per spec 24-app-ui-design-system/18-component-input.md §11.
 *
 * Inherits every Input rule with deltas:
 * - min block-size 80px, max block-size 240px (internal scroll beyond).
 * - resize is vertical-only (AC-INP-009 bans both/horizontal).
 * - radius --radius-md, focus/invalid/disabled parity with Input.
 */
const Textarea = React.forwardRef<HTMLTextAreaElement, React.ComponentProps<"textarea">>(
  ({ className, readOnly, ...props }, ref) => {
    return (
      <textarea
        readOnly={readOnly}
        aria-readonly={readOnly ? "true" : undefined}
        className={cn(
          "flex min-h-[80px] max-h-[240px] w-full resize-y overflow-auto",
          "rounded-[var(--radius-md)] border border-[var(--border)]",
          "bg-transparent px-3 py-2 text-sm text-[var(--foreground)] shadow-sm",
          "transition-[color,background-color,border-color,box-shadow]",
          "duration-[var(--motion-duration-xs)] ease-[var(--motion-easing-standard)]",
          "placeholder:text-[var(--muted-foreground)]",
          // v0.497.0: focus uses --ring-focus-strong to match Input + primary CTA.
          "focus-visible:outline-none focus-visible:ring-0 focus-visible:ring-offset-0",
          "focus-visible:border-[var(--ring)] focus-visible:shadow-[var(--ring-focus-strong)]",
          "aria-[invalid=true]:border-[var(--destructive)]",
          "aria-[invalid=true]:focus-visible:shadow-[0_0_0_3px_color-mix(in_oklab,var(--destructive)_35%,transparent)]",
          "disabled:cursor-not-allowed disabled:opacity-50 disabled:bg-[var(--muted)]",
          "read-only:bg-[var(--muted)] read-only:cursor-default",
          className,
        )}
        ref={ref}
        {...props}
      />
    );
  },
);
Textarea.displayName = "Textarea";

export { Textarea };
