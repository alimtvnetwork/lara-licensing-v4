import * as React from "react";
import * as PopoverPrimitive from "@radix-ui/react-popover";

import { cn } from "@/lib/utils";

const Popover = PopoverPrimitive.Root;

const PopoverTrigger = PopoverPrimitive.Trigger;

const PopoverAnchor = PopoverPrimitive.Anchor;

/**
 * Refit to spec 24 §22.3 Popover contract: role=dialog WITHOUT aria-modal
 * (non-modal); elevation-1, --radius-md, card fill, --space-4 padding on all
 * sides, min-inline-size 240px, max-inline-size clamped against viewport with
 * shell gutters, internal scroll at --space-4 gutter allowance. Focus is NOT
 * trapped (AC-POP-002). Nested popovers are banned by menu-popover.md §11 and
 * §9; enforce that at call sites.
 */
const PopoverContent = React.forwardRef<
  React.ElementRef<typeof PopoverPrimitive.Content>,
  React.ComponentPropsWithoutRef<typeof PopoverPrimitive.Content>
>(({ className, align = "center", sideOffset = 4, ...props }, ref) => (
  <PopoverPrimitive.Portal>
    <PopoverPrimitive.Content
      ref={ref}
      align={align}
      sideOffset={sideOffset}
      className={cn(
        "z-50 outline-none",
        "min-w-[240px] max-w-[min(480px,100vw-2*var(--space-4))]",
        "max-h-[min(320px,100vh-2*var(--space-4))] overflow-y-auto",
        // v0.499.0 (SS-01): shared flyout recipe (hairline inset +
        // elevation-1) matches dropdown-menu; drop the redundant 1px border.
        "rounded-[var(--radius-md)]",
        "bg-[var(--popover)] text-[var(--popover-foreground)]",
        "p-[var(--space-4)]",
        "shadow-[var(--shadow-inset-hairline),var(--shadow-1)]",
        "data-[state=open]:animate-in data-[state=closed]:animate-out",
        "data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0",
        "data-[state=closed]:zoom-out-95 data-[state=open]:zoom-in-95",
        "data-[side=bottom]:slide-in-from-top-1 data-[side=left]:slide-in-from-right-1",
        "data-[side=right]:slide-in-from-left-1 data-[side=top]:slide-in-from-bottom-1",
        "motion-reduce:animate-none motion-reduce:transition-none",
        "origin-(--radix-popover-content-transform-origin)",
        className,
      )}
      {...props}
    />
  </PopoverPrimitive.Portal>
));
PopoverContent.displayName = PopoverPrimitive.Content.displayName;

export { Popover, PopoverTrigger, PopoverContent, PopoverAnchor };
