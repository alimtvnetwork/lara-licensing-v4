import * as React from "react";
import * as RadioGroupPrimitive from "@radix-ui/react-radio-group";

import { cn } from "@/lib/utils";

/**
 * RadioGroup refit per spec/24-app-ui-design-system/20-component-choice.md §3-4, §6.
 *
 * Item geometry: 20x20 px, radius 50%, 1px --border, fill --primary on
 * check with a centered 8px dot. Focus ring / invalid / disabled rules
 * mirror Checkbox and Input for exact ring parity (AC-CHC-007).
 *
 * `RadioGroup` sets `role="radiogroup"`; consumers MUST wrap it in a
 * `<fieldset><legend>` in form context (AC-CHC-009).
 */
const RadioGroup = React.forwardRef<
  React.ElementRef<typeof RadioGroupPrimitive.Root>,
  React.ComponentPropsWithoutRef<typeof RadioGroupPrimitive.Root>
>(({ className, ...props }, ref) => (
  <RadioGroupPrimitive.Root
    ref={ref}
    className={cn("grid gap-[var(--space-2)]", className)}
    {...props}
  />
));
RadioGroup.displayName = RadioGroupPrimitive.Root.displayName;

const RadioGroupItem = React.forwardRef<
  React.ElementRef<typeof RadioGroupPrimitive.Item>,
  React.ComponentPropsWithoutRef<typeof RadioGroupPrimitive.Item>
>(({ className, ...props }, ref) => (
  <RadioGroupPrimitive.Item
    ref={ref}
    className={cn(
      "aspect-square h-5 w-5 shrink-0 rounded-full cursor-pointer",
      "border border-[var(--border)] bg-[var(--card)]",
      "transition-colors",
      "hover:border-[color-mix(in_oklch,var(--border)_60%,var(--foreground))]",
      "focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[var(--ring)] focus-visible:ring-offset-2 focus-visible:ring-offset-[var(--background)]",
      "disabled:cursor-not-allowed disabled:opacity-50",
      "data-[state=checked]:border-[var(--primary)]",
      "aria-[invalid=true]:border-[var(--destructive)]",
      className,
    )}
    {...props}
  >
    <RadioGroupPrimitive.Indicator className="grid h-full w-full place-content-center">
      <span aria-hidden="true" className="block h-2 w-2 rounded-full bg-[var(--primary)]" />
    </RadioGroupPrimitive.Indicator>
  </RadioGroupPrimitive.Item>
));
RadioGroupItem.displayName = RadioGroupPrimitive.Item.displayName;

export { RadioGroup, RadioGroupItem };
