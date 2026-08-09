import * as React from "react";
import * as ContextMenuPrimitive from "@radix-ui/react-context-menu";
import { Check, ChevronRight, Circle } from "lucide-react";

import { cn } from "@/lib/utils";

const ContextMenu = ContextMenuPrimitive.Root;

const ContextMenuTrigger = ContextMenuPrimitive.Trigger;

const ContextMenuGroup = ContextMenuPrimitive.Group;

const ContextMenuPortal = ContextMenuPrimitive.Portal;

const ContextMenuSub = ContextMenuPrimitive.Sub;

const ContextMenuRadioGroup = ContextMenuPrimitive.RadioGroup;

/**
 * Refit parity with DropdownMenu (spec 24 §22). ContextMenu shares the Menu
 * container/item geometry; only the trigger surface differs (right-click
 * instead of button click). Destructive contract: pass intent="destructive"
 * to ContextMenuItem; caller MUST route through a confirmation Dialog.
 */
const ContextMenuSubTrigger = React.forwardRef<
  React.ElementRef<typeof ContextMenuPrimitive.SubTrigger>,
  React.ComponentPropsWithoutRef<typeof ContextMenuPrimitive.SubTrigger> & {
    inset?: boolean;
  }
>(({ className, inset, children, ...props }, ref) => (
  <ContextMenuPrimitive.SubTrigger
    ref={ref}
    className={cn(
      "flex min-h-10 cursor-default select-none items-center",
      "rounded-[var(--radius-sm)] px-[var(--space-2)] py-[var(--space-2)] text-sm outline-none",
      "focus:bg-[var(--accent)] focus:text-[var(--accent-foreground)]",
      "data-[state=open]:bg-[var(--accent)] data-[state=open]:text-[var(--accent-foreground)]",
      inset && "pl-[calc(var(--space-2)+1.5rem)]",
      className,
    )}
    {...props}
  >
    {children}
    <ChevronRight className="ml-auto h-4 w-4" />
  </ContextMenuPrimitive.SubTrigger>
));
ContextMenuSubTrigger.displayName = ContextMenuPrimitive.SubTrigger.displayName;

const menuContentClasses = cn(
  "z-50 min-w-[200px] max-w-[320px] overflow-y-auto overflow-x-hidden",
  "max-h-[min(400px,100vh-var(--shell-topbar,64px)-2*var(--space-4))]",
  "rounded-[var(--radius-md)] border border-[var(--border)]",
  "bg-[var(--popover)] text-[var(--popover-foreground)]",
  "p-[var(--space-1)]",
  "shadow-[var(--shadow-1)]",
  "data-[state=open]:animate-in data-[state=closed]:animate-out",
  "data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0",
  "data-[state=closed]:zoom-out-95 data-[state=open]:zoom-in-95",
  "data-[side=bottom]:slide-in-from-top-1 data-[side=left]:slide-in-from-right-1",
  "data-[side=right]:slide-in-from-left-1 data-[side=top]:slide-in-from-bottom-1",
  "motion-reduce:animate-none motion-reduce:transition-none",
);

const ContextMenuSubContent = React.forwardRef<
  React.ElementRef<typeof ContextMenuPrimitive.SubContent>,
  React.ComponentPropsWithoutRef<typeof ContextMenuPrimitive.SubContent>
>(({ className, ...props }, ref) => (
  <ContextMenuPrimitive.SubContent
    ref={ref}
    className={cn(
      menuContentClasses,
      "origin-(--radix-context-menu-content-transform-origin)",
      className,
    )}
    {...props}
  />
));
ContextMenuSubContent.displayName = ContextMenuPrimitive.SubContent.displayName;

const ContextMenuContent = React.forwardRef<
  React.ElementRef<typeof ContextMenuPrimitive.Content>,
  React.ComponentPropsWithoutRef<typeof ContextMenuPrimitive.Content>
>(({ className, ...props }, ref) => (
  <ContextMenuPrimitive.Portal>
    <ContextMenuPrimitive.Content
      ref={ref}
      className={cn(
        menuContentClasses,
        "max-h-(--radix-context-menu-content-available-height)",
        "origin-(--radix-context-menu-content-transform-origin)",
        className,
      )}
      {...props}
    />
  </ContextMenuPrimitive.Portal>
));
ContextMenuContent.displayName = ContextMenuPrimitive.Content.displayName;

type MenuIntent = "neutral" | "destructive";

const ContextMenuItem = React.forwardRef<
  React.ElementRef<typeof ContextMenuPrimitive.Item>,
  React.ComponentPropsWithoutRef<typeof ContextMenuPrimitive.Item> & {
    inset?: boolean;
    intent?: MenuIntent;
  }
>(({ className, inset, intent = "neutral", ...props }, ref) => (
  <ContextMenuPrimitive.Item
    ref={ref}
    data-intent={intent}
    className={cn(
      "relative flex min-h-10 cursor-default select-none items-center gap-[var(--space-2)]",
      "rounded-[var(--radius-sm)] px-[var(--space-2)] py-[var(--space-2)] text-sm outline-none",
      "focus:bg-[var(--accent)] focus:text-[var(--accent-foreground)]",
      "data-[disabled]:pointer-events-none data-[disabled]:opacity-50",
      intent === "destructive" &&
        "text-[var(--destructive)] focus:bg-[color-mix(in_oklab,var(--destructive)_12%,var(--popover))] focus:text-[var(--destructive)]",
      inset && "pl-[calc(var(--space-2)+1.5rem)]",
      className,
    )}
    {...props}
  />
));
ContextMenuItem.displayName = ContextMenuPrimitive.Item.displayName;

const ContextMenuCheckboxItem = React.forwardRef<
  React.ElementRef<typeof ContextMenuPrimitive.CheckboxItem>,
  React.ComponentPropsWithoutRef<typeof ContextMenuPrimitive.CheckboxItem>
>(({ className, children, checked, ...props }, ref) => (
  <ContextMenuPrimitive.CheckboxItem
    ref={ref}
    className={cn(
      "relative flex min-h-10 cursor-default select-none items-center",
      "rounded-[var(--radius-sm)] py-[var(--space-2)] pl-[calc(var(--space-2)+1.5rem)] pr-[var(--space-2)] text-sm outline-none",
      "focus:bg-[var(--accent)] focus:text-[var(--accent-foreground)]",
      "data-[disabled]:pointer-events-none data-[disabled]:opacity-50",
      className,
    )}
    checked={checked}
    {...props}
  >
    <span className="absolute left-[var(--space-2)] flex h-3.5 w-3.5 items-center justify-center">
      <ContextMenuPrimitive.ItemIndicator>
        <Check className="h-4 w-4" />
      </ContextMenuPrimitive.ItemIndicator>
    </span>
    {children}
  </ContextMenuPrimitive.CheckboxItem>
));
ContextMenuCheckboxItem.displayName = ContextMenuPrimitive.CheckboxItem.displayName;

const ContextMenuRadioItem = React.forwardRef<
  React.ElementRef<typeof ContextMenuPrimitive.RadioItem>,
  React.ComponentPropsWithoutRef<typeof ContextMenuPrimitive.RadioItem>
>(({ className, children, ...props }, ref) => (
  <ContextMenuPrimitive.RadioItem
    ref={ref}
    className={cn(
      "relative flex min-h-10 cursor-default select-none items-center",
      "rounded-[var(--radius-sm)] py-[var(--space-2)] pl-[calc(var(--space-2)+1.5rem)] pr-[var(--space-2)] text-sm outline-none",
      "focus:bg-[var(--accent)] focus:text-[var(--accent-foreground)]",
      "data-[disabled]:pointer-events-none data-[disabled]:opacity-50",
      className,
    )}
    {...props}
  >
    <span className="absolute left-[var(--space-2)] flex h-3.5 w-3.5 items-center justify-center">
      <ContextMenuPrimitive.ItemIndicator>
        <Circle className="h-4 w-4 fill-current" />
      </ContextMenuPrimitive.ItemIndicator>
    </span>
    {children}
  </ContextMenuPrimitive.RadioItem>
));
ContextMenuRadioItem.displayName = ContextMenuPrimitive.RadioItem.displayName;

const ContextMenuLabel = React.forwardRef<
  React.ElementRef<typeof ContextMenuPrimitive.Label>,
  React.ComponentPropsWithoutRef<typeof ContextMenuPrimitive.Label> & {
    inset?: boolean;
  }
>(({ className, inset, ...props }, ref) => (
  <ContextMenuPrimitive.Label
    ref={ref}
    className={cn(
      "px-[var(--space-2)] py-[var(--space-1)] text-xs font-semibold uppercase tracking-wide text-[var(--muted-foreground)]",
      inset && "pl-[calc(var(--space-2)+1.5rem)]",
      className,
    )}
    {...props}
  />
));
ContextMenuLabel.displayName = ContextMenuPrimitive.Label.displayName;

const ContextMenuSeparator = React.forwardRef<
  React.ElementRef<typeof ContextMenuPrimitive.Separator>,
  React.ComponentPropsWithoutRef<typeof ContextMenuPrimitive.Separator>
>(({ className, ...props }, ref) => (
  <ContextMenuPrimitive.Separator
    ref={ref}
    className={cn("-mx-[var(--space-1)] my-[var(--space-1)] h-px bg-[var(--border)]", className)}
    {...props}
  />
));
ContextMenuSeparator.displayName = ContextMenuPrimitive.Separator.displayName;

const ContextMenuShortcut = ({ className, ...props }: React.HTMLAttributes<HTMLSpanElement>) => {
  return (
    <span
      className={cn("ml-auto text-xs tracking-widest text-[var(--muted-foreground)]", className)}
      {...props}
    />
  );
};
ContextMenuShortcut.displayName = "ContextMenuShortcut";

export {
  ContextMenu,
  ContextMenuTrigger,
  ContextMenuContent,
  ContextMenuItem,
  ContextMenuCheckboxItem,
  ContextMenuRadioItem,
  ContextMenuLabel,
  ContextMenuSeparator,
  ContextMenuShortcut,
  ContextMenuGroup,
  ContextMenuPortal,
  ContextMenuSub,
  ContextMenuSubContent,
  ContextMenuSubTrigger,
  ContextMenuRadioGroup,
};
