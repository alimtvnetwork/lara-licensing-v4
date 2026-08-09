import * as React from "react";
import * as CheckboxPrimitive from "@radix-ui/react-checkbox";
import { Check, Minus } from "lucide-react";

import { cn } from "@/lib/utils";

/**
 * Checkbox refit per spec/24-app-ui-design-system/20-component-choice.md §3-4.
 *
 * Geometry: visible control 20x20 px, radius --radius-sm, 1px --border,
 * fill --primary when checked, indeterminate glyph = horizontal bar.
 * Focus ring matches Input/Button (2px var(--ring), 2px offset).
 * aria-invalid=true shifts border to var(--destructive).
 *
 * Hit target: consumers MUST wrap the control in a 40x40 label row for
 * AC-CHC-008 (linter-scripts/check-click-target-floor.py). Standalone
 * usage in a table cell is fine because table row hit target is 40px.
 */
const Checkbox = React.forwardRef<
  React.ElementRef<typeof CheckboxPrimitive.Root>,
  React.ComponentPropsWithoutRef<typeof CheckboxPrimitive.Root>
>(({ className, ...props }, ref) => (
  <CheckboxPrimitive.Root
    ref={ref}
    className={cn(
      "peer inline-grid place-content-center shrink-0 cursor-pointer",
      "h-5 w-5 rounded-[var(--radius-sm)] border border-[var(--border)] bg-[var(--card)]",
      "transition-colors",
      "hover:border-[color-mix(in_oklch,var(--border)_60%,var(--foreground))]",
      "focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[var(--ring)] focus-visible:ring-offset-2 focus-visible:ring-offset-[var(--background)]",
      "disabled:cursor-not-allowed disabled:opacity-50",
      "data-[state=checked]:bg-[var(--primary)] data-[state=checked]:border-[var(--primary)] data-[state=checked]:text-[var(--primary-foreground)]",
      "data-[state=indeterminate]:bg-[var(--primary)] data-[state=indeterminate]:border-[var(--primary)] data-[state=indeterminate]:text-[var(--primary-foreground)]",
      "aria-[invalid=true]:border-[var(--destructive)]",
      className,
    )}
    {...props}
  >
    <CheckboxPrimitive.Indicator className="grid place-content-center text-current">
      {props.checked === "indeterminate" ? (
        <Minus className="h-3.5 w-3.5" aria-hidden="true" />
      ) : (
        <Check className="h-3.5 w-3.5" aria-hidden="true" />
      )}
    </CheckboxPrimitive.Indicator>
  </CheckboxPrimitive.Root>
));
Checkbox.displayName = CheckboxPrimitive.Root.displayName;

export { Checkbox };
