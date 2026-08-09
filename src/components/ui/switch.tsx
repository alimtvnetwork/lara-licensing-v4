import * as React from "react";
import * as SwitchPrimitives from "@radix-ui/react-switch";

import { cn } from "@/lib/utils";

/**
 * Switch refit per spec/24-app-ui-design-system/20-component-choice.md §3, §7.
 *
 * Geometry: track 32x20 px, thumb 16x16 px, radius --radius-full on both.
 * Unchecked track = var(--muted); checked track = var(--primary).
 * Focus ring wraps the track (2px var(--ring), 2px offset) matching
 * Input/Button (AC-CHC-007). `aria-busy=true` renders a muted-outline
 * track so callers signal in-flight optimistic mutations per §7.
 *
 * Switch is IMMEDIATE-EFFECT: never place inside a form with a submit
 * button; every change is a server mutation with an Idempotency-Key
 * (AC-CHC-002). Permission-gated Switches must be hidden, not disabled
 * (AC-CHC-005).
 */
const Switch = React.forwardRef<
  React.ElementRef<typeof SwitchPrimitives.Root>,
  React.ComponentPropsWithoutRef<typeof SwitchPrimitives.Root>
>(({ className, ...props }, ref) => (
  <SwitchPrimitives.Root
    ref={ref}
    className={cn(
      "peer inline-flex h-5 w-8 shrink-0 cursor-pointer items-center rounded-full",
      "border-2 border-transparent transition-colors",
      "focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[var(--ring)] focus-visible:ring-offset-2 focus-visible:ring-offset-[var(--background)]",
      "disabled:cursor-not-allowed disabled:opacity-50",
      "data-[state=unchecked]:bg-[var(--muted)]",
      "data-[state=checked]:bg-[var(--primary)]",
      "aria-[busy=true]:bg-[var(--card)] aria-[busy=true]:border-[var(--muted)]",
      "aria-[invalid=true]:ring-2 aria-[invalid=true]:ring-[var(--destructive)]",
      className,
    )}
    {...props}
  >
    <SwitchPrimitives.Thumb
      className={cn(
        "pointer-events-none block h-4 w-4 rounded-full bg-[var(--card)] shadow",
        "transition-transform",
        "data-[state=checked]:translate-x-3 data-[state=unchecked]:translate-x-0",
      )}
    />
  </SwitchPrimitives.Root>
));
Switch.displayName = SwitchPrimitives.Root.displayName;

export { Switch };
