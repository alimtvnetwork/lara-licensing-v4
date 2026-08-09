"use client";

import * as React from "react";
import * as SelectPrimitive from "@radix-ui/react-select";
import { Check, ChevronsUpDown } from "lucide-react";

import { cn } from "@/lib/utils";

/**
 * Select refit per spec/24-app-ui-design-system/19-component-select.md.
 *
 * Trigger geometry (§3): inherits Input geometry (40 px block-size,
 * `--radius-md`, 1 px `var(--border)`), always renders a trailing
 * `ChevronsUpDown` glyph, uses `var(--muted-foreground)` for placeholder
 * (`data-[placeholder]`). Focus ring matches Input/Button/Choice exactly
 * (2 px `var(--ring)` at 2 px offset, AC-SEL-009).
 *
 * `aria-invalid=true` shifts border to `var(--destructive)` per §4 (error
 * timing inherits from Input via Field shell).
 *
 * Listbox (§8): `--radius-md`, elevation-1 shadow, min-width equals
 * trigger width, max block-size clamped to viewport. Motion uses the
 * `attach-and-detach` recipe with tailwindcss-animate primitives that
 * respect `prefers-reduced-motion` via the global motion-reduce zeroing.
 *
 * Option (§6): `data-disabled` becomes `aria-disabled`-equivalent for
 * deprecated entries; `pointer-events-none` is banned so tooltips stay
 * reachable (spec §6 last paragraph), so we only fade the opacity.
 */
const Select = SelectPrimitive.Root;
const SelectGroup = SelectPrimitive.Group;
const SelectValue = SelectPrimitive.Value;

const SelectTrigger = React.forwardRef<
  React.ElementRef<typeof SelectPrimitive.Trigger>,
  React.ComponentPropsWithoutRef<typeof SelectPrimitive.Trigger>
>(({ className, children, ...props }, ref) => (
  <SelectPrimitive.Trigger
    ref={ref}
    className={cn(
      "flex h-10 w-full items-center justify-between whitespace-nowrap cursor-pointer",
      "rounded-[var(--radius-md)] border border-[var(--border)] bg-[var(--card)] text-[var(--foreground)]",
      "px-3 text-sm",
      "transition-colors",
      "data-[placeholder]:text-[var(--muted-foreground)]",
      "hover:border-[color-mix(in_oklch,var(--border)_60%,var(--foreground))]",
      "focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[var(--ring)] focus-visible:ring-offset-2 focus-visible:ring-offset-[var(--background)]",
      "disabled:cursor-not-allowed disabled:opacity-50",
      "aria-[invalid=true]:border-[var(--destructive)]",
      "[&>span]:line-clamp-1",
      className,
    )}
    {...props}
  >
    {children}
    <SelectPrimitive.Icon asChild>
      <ChevronsUpDown className="h-4 w-4 text-[var(--muted-foreground)]" aria-hidden="true" />
    </SelectPrimitive.Icon>
  </SelectPrimitive.Trigger>
));
SelectTrigger.displayName = SelectPrimitive.Trigger.displayName;

const SelectContent = React.forwardRef<
  React.ElementRef<typeof SelectPrimitive.Content>,
  React.ComponentPropsWithoutRef<typeof SelectPrimitive.Content>
>(({ className, children, position = "popper", ...props }, ref) => (
  <SelectPrimitive.Portal>
    <SelectPrimitive.Content
      ref={ref}
      className={cn(
        "relative z-50 overflow-y-auto overflow-x-hidden",
        "min-w-[var(--radix-select-trigger-width)] max-h-[min(320px,var(--radix-select-content-available-height))]",
        "rounded-[var(--radius-md)] border border-[var(--border)] bg-[var(--popover)] text-[var(--popover-foreground)]",
        "shadow-[var(--shadow-1,0_4px_12px_rgba(0,0,0,0.12))]",
        "data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0 data-[state=closed]:zoom-out-95 data-[state=open]:zoom-in-95",
        "data-[side=bottom]:slide-in-from-top-1 data-[side=top]:slide-in-from-bottom-1",
        position === "popper" && "data-[side=bottom]:translate-y-1 data-[side=top]:-translate-y-1",
        className,
      )}
      position={position}
      sideOffset={4}
      {...props}
    >
      <SelectPrimitive.Viewport className="p-[var(--space-1)]">{children}</SelectPrimitive.Viewport>
    </SelectPrimitive.Content>
  </SelectPrimitive.Portal>
));
SelectContent.displayName = SelectPrimitive.Content.displayName;

const SelectLabel = React.forwardRef<
  React.ElementRef<typeof SelectPrimitive.Label>,
  React.ComponentPropsWithoutRef<typeof SelectPrimitive.Label>
>(({ className, ...props }, ref) => (
  <SelectPrimitive.Label
    ref={ref}
    className={cn("px-2 py-1.5 text-xs font-semibold text-[var(--muted-foreground)]", className)}
    {...props}
  />
));
SelectLabel.displayName = SelectPrimitive.Label.displayName;

const SelectItem = React.forwardRef<
  React.ElementRef<typeof SelectPrimitive.Item>,
  React.ComponentPropsWithoutRef<typeof SelectPrimitive.Item>
>(({ className, children, ...props }, ref) => (
  <SelectPrimitive.Item
    ref={ref}
    className={cn(
      "relative flex w-full select-none items-center rounded-[var(--radius-sm)] py-1.5 pl-2 pr-8 text-sm cursor-pointer outline-none",
      "focus:bg-[var(--accent)] focus:text-[var(--accent-foreground)]",
      "data-[disabled]:opacity-50 data-[disabled]:cursor-not-allowed",
      className,
    )}
    {...props}
  >
    <span className="absolute right-2 flex h-3.5 w-3.5 items-center justify-center">
      <SelectPrimitive.ItemIndicator>
        <Check className="h-4 w-4" aria-hidden="true" />
      </SelectPrimitive.ItemIndicator>
    </span>
    <SelectPrimitive.ItemText>{children}</SelectPrimitive.ItemText>
  </SelectPrimitive.Item>
));
SelectItem.displayName = SelectPrimitive.Item.displayName;

const SelectSeparator = React.forwardRef<
  React.ElementRef<typeof SelectPrimitive.Separator>,
  React.ComponentPropsWithoutRef<typeof SelectPrimitive.Separator>
>(({ className, ...props }, ref) => (
  <SelectPrimitive.Separator
    ref={ref}
    className={cn("-mx-1 my-1 h-px bg-[var(--border)]", className)}
    {...props}
  />
));
SelectSeparator.displayName = SelectPrimitive.Separator.displayName;

export {
  Select,
  SelectGroup,
  SelectValue,
  SelectTrigger,
  SelectContent,
  SelectLabel,
  SelectItem,
  SelectSeparator,
};
