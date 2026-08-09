"use client";

import * as React from "react";
import * as DropdownMenuPrimitive from "@radix-ui/react-dropdown-menu";
import { Check, ChevronRight, Circle } from "lucide-react";

import { cn } from "@/lib/utils";

const DropdownMenu = DropdownMenuPrimitive.Root;

const DropdownMenuTrigger = DropdownMenuPrimitive.Trigger;

const DropdownMenuGroup = DropdownMenuPrimitive.Group;

const DropdownMenuPortal = DropdownMenuPrimitive.Portal;

const DropdownMenuSub = DropdownMenuPrimitive.Sub;

const DropdownMenuRadioGroup = DropdownMenuPrimitive.RadioGroup;

/**
 * Refit to spec 24 §22.2 (Menu geometry) + §22.5 (destructive item handoff).
 * Container: --radius-md, --space-1 block padding / 0 inline, min-inline 200px,
 * max-inline 320px, max-block min(400px, 100vh - shell topbar - 2*space-4),
 * elevation-1. Items: --radius-sm, --space-2 inline+block padding, 40px hit
 * target, focus fill var(--accent) at --accent-foreground.
 *
 * Destructive items: pass `intent="destructive"` on <DropdownMenuItem>. Icon
 * and label render with var(--destructive). The item still fires onSelect; the
 * caller MUST open a confirmation Dialog (spec §5), NOT execute the mutation
 * directly. Enforced at review by linter-scripts/check-destructive-context.py
 * (Plan 06 Step 46 - not yet landed).
 */
const DropdownMenuSubTrigger = React.forwardRef<
  React.ElementRef<typeof DropdownMenuPrimitive.SubTrigger>,
  React.ComponentPropsWithoutRef<typeof DropdownMenuPrimitive.SubTrigger> & {
    inset?: boolean;
  }
>(({ className, inset, children, ...props }, ref) => (
  <DropdownMenuPrimitive.SubTrigger
    ref={ref}
    className={cn(
      "flex min-h-10 cursor-default select-none items-center gap-[var(--space-2)]",
      "rounded-[var(--radius-sm)] px-[var(--space-2)] py-[var(--space-2)] text-sm outline-none",
      "focus:bg-[var(--accent)] focus:text-[var(--accent-foreground)]",
      "data-[state=open]:bg-[var(--accent)] data-[state=open]:text-[var(--accent-foreground)]",
      "[&_svg]:pointer-events-none [&_svg]:size-4 [&_svg]:shrink-0",
      inset && "pl-[calc(var(--space-2)+1.5rem)]",
      className,
    )}
    {...props}
  >
    {children}
    <ChevronRight className="ml-auto" />
  </DropdownMenuPrimitive.SubTrigger>
));
DropdownMenuSubTrigger.displayName = DropdownMenuPrimitive.SubTrigger.displayName;

const menuContentClasses = cn(
  "z-50 min-w-[200px] max-w-[320px] overflow-y-auto overflow-x-hidden",
  "max-h-[min(400px,100vh-var(--shell-topbar,64px)-2*var(--space-4))]",
  // v0.499.0 (SS-01): compose the shared flyout recipe (hairline inset +
  // elevation-1 shadow) so menus sit in the same depth tier as popovers
  // and one step below dialog/sheet panels.
  "rounded-[var(--radius-md)]",
  "bg-[var(--popover)] text-[var(--popover-foreground)]",
  "p-[var(--space-1)]",
  "shadow-[var(--shadow-inset-hairline),var(--shadow-1)]",
  "data-[state=open]:animate-in data-[state=closed]:animate-out",
  "data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0",
  "data-[state=closed]:zoom-out-95 data-[state=open]:zoom-in-95",
  "data-[side=bottom]:slide-in-from-top-1 data-[side=left]:slide-in-from-right-1",
  "data-[side=right]:slide-in-from-left-1 data-[side=top]:slide-in-from-bottom-1",
  "motion-reduce:animate-none motion-reduce:transition-none",
);

const DropdownMenuSubContent = React.forwardRef<
  React.ElementRef<typeof DropdownMenuPrimitive.SubContent>,
  React.ComponentPropsWithoutRef<typeof DropdownMenuPrimitive.SubContent>
>(({ className, ...props }, ref) => (
  <DropdownMenuPrimitive.SubContent
    ref={ref}
    className={cn(
      menuContentClasses,
      "origin-(--radix-dropdown-menu-content-transform-origin)",
      className,
    )}
    {...props}
  />
));
DropdownMenuSubContent.displayName = DropdownMenuPrimitive.SubContent.displayName;

const DropdownMenuContent = React.forwardRef<
  React.ElementRef<typeof DropdownMenuPrimitive.Content>,
  React.ComponentPropsWithoutRef<typeof DropdownMenuPrimitive.Content>
>(({ className, sideOffset = 4, ...props }, ref) => (
  <DropdownMenuPrimitive.Portal>
    <DropdownMenuPrimitive.Content
      ref={ref}
      sideOffset={sideOffset}
      className={cn(
        menuContentClasses,
        "max-h-[var(--radix-dropdown-menu-content-available-height)]",
        "origin-(--radix-dropdown-menu-content-transform-origin)",
        className,
      )}
      {...props}
    />
  </DropdownMenuPrimitive.Portal>
));
DropdownMenuContent.displayName = DropdownMenuPrimitive.Content.displayName;

type MenuIntent = "neutral" | "destructive";

const DropdownMenuItem = React.forwardRef<
  React.ElementRef<typeof DropdownMenuPrimitive.Item>,
  React.ComponentPropsWithoutRef<typeof DropdownMenuPrimitive.Item> & {
    inset?: boolean;
    intent?: MenuIntent;
  }
>(({ className, inset, intent = "neutral", ...props }, ref) => (
  <DropdownMenuPrimitive.Item
    ref={ref}
    data-intent={intent}
    className={cn(
      "relative flex min-h-10 cursor-default select-none items-center gap-[var(--space-2)]",
      "rounded-[var(--radius-sm)] px-[var(--space-2)] py-[var(--space-2)] text-sm outline-none",
      "transition-colors",
      "focus:bg-[var(--accent)] focus:text-[var(--accent-foreground)]",
      "data-[disabled]:pointer-events-none data-[disabled]:opacity-50",
      "[&>svg]:size-4 [&>svg]:shrink-0",
      intent === "destructive" &&
        "text-[var(--destructive)] focus:bg-[color-mix(in_oklab,var(--destructive)_12%,var(--popover))] focus:text-[var(--destructive)]",
      inset && "pl-[calc(var(--space-2)+1.5rem)]",
      className,
    )}
    {...props}
  />
));
DropdownMenuItem.displayName = DropdownMenuPrimitive.Item.displayName;

const DropdownMenuCheckboxItem = React.forwardRef<
  React.ElementRef<typeof DropdownMenuPrimitive.CheckboxItem>,
  React.ComponentPropsWithoutRef<typeof DropdownMenuPrimitive.CheckboxItem>
>(({ className, children, checked, ...props }, ref) => (
  <DropdownMenuPrimitive.CheckboxItem
    ref={ref}
    className={cn(
      "relative flex min-h-10 cursor-default select-none items-center",
      "rounded-[var(--radius-sm)] py-[var(--space-2)] pl-[calc(var(--space-2)+1.5rem)] pr-[var(--space-2)] text-sm outline-none",
      "transition-colors",
      "focus:bg-[var(--accent)] focus:text-[var(--accent-foreground)]",
      "data-[disabled]:pointer-events-none data-[disabled]:opacity-50",
      className,
    )}
    checked={checked}
    {...props}
  >
    <span className="absolute left-[var(--space-2)] flex h-3.5 w-3.5 items-center justify-center">
      <DropdownMenuPrimitive.ItemIndicator>
        <Check className="h-4 w-4" />
      </DropdownMenuPrimitive.ItemIndicator>
    </span>
    {children}
  </DropdownMenuPrimitive.CheckboxItem>
));
DropdownMenuCheckboxItem.displayName = DropdownMenuPrimitive.CheckboxItem.displayName;

const DropdownMenuRadioItem = React.forwardRef<
  React.ElementRef<typeof DropdownMenuPrimitive.RadioItem>,
  React.ComponentPropsWithoutRef<typeof DropdownMenuPrimitive.RadioItem>
>(({ className, children, ...props }, ref) => (
  <DropdownMenuPrimitive.RadioItem
    ref={ref}
    className={cn(
      "relative flex min-h-10 cursor-default select-none items-center",
      "rounded-[var(--radius-sm)] py-[var(--space-2)] pl-[calc(var(--space-2)+1.5rem)] pr-[var(--space-2)] text-sm outline-none",
      "transition-colors",
      "focus:bg-[var(--accent)] focus:text-[var(--accent-foreground)]",
      "data-[disabled]:pointer-events-none data-[disabled]:opacity-50",
      className,
    )}
    {...props}
  >
    <span className="absolute left-[var(--space-2)] flex h-3.5 w-3.5 items-center justify-center">
      <DropdownMenuPrimitive.ItemIndicator>
        <Circle className="h-2 w-2 fill-current" />
      </DropdownMenuPrimitive.ItemIndicator>
    </span>
    {children}
  </DropdownMenuPrimitive.RadioItem>
));
DropdownMenuRadioItem.displayName = DropdownMenuPrimitive.RadioItem.displayName;

const DropdownMenuLabel = React.forwardRef<
  React.ElementRef<typeof DropdownMenuPrimitive.Label>,
  React.ComponentPropsWithoutRef<typeof DropdownMenuPrimitive.Label> & {
    inset?: boolean;
  }
>(({ className, inset, ...props }, ref) => (
  <DropdownMenuPrimitive.Label
    ref={ref}
    className={cn(
      "px-[var(--space-2)] py-[var(--space-1)] text-xs font-semibold uppercase tracking-wide text-[var(--muted-foreground)]",
      inset && "pl-[calc(var(--space-2)+1.5rem)]",
      className,
    )}
    {...props}
  />
));
DropdownMenuLabel.displayName = DropdownMenuPrimitive.Label.displayName;

const DropdownMenuSeparator = React.forwardRef<
  React.ElementRef<typeof DropdownMenuPrimitive.Separator>,
  React.ComponentPropsWithoutRef<typeof DropdownMenuPrimitive.Separator>
>(({ className, ...props }, ref) => (
  <DropdownMenuPrimitive.Separator
    ref={ref}
    className={cn("-mx-[var(--space-1)] my-[var(--space-1)] h-px bg-[var(--border)]", className)}
    {...props}
  />
));
DropdownMenuSeparator.displayName = DropdownMenuPrimitive.Separator.displayName;

const DropdownMenuShortcut = ({ className, ...props }: React.HTMLAttributes<HTMLSpanElement>) => {
  return (
    <span
      className={cn("ml-auto text-xs tracking-widest text-[var(--muted-foreground)]", className)}
      {...props}
    />
  );
};
DropdownMenuShortcut.displayName = "DropdownMenuShortcut";

export {
  DropdownMenu,
  DropdownMenuTrigger,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuCheckboxItem,
  DropdownMenuRadioItem,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuShortcut,
  DropdownMenuGroup,
  DropdownMenuPortal,
  DropdownMenuSub,
  DropdownMenuSubContent,
  DropdownMenuSubTrigger,
  DropdownMenuRadioGroup,
};
