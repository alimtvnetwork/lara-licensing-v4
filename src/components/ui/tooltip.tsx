"use client";

import * as React from "react";
import * as TooltipPrimitive from "@radix-ui/react-tooltip";

import { cn } from "@/lib/utils";

const TooltipProvider = TooltipPrimitive.Provider;

const Tooltip = TooltipPrimitive.Root;

const TooltipTrigger = TooltipPrimitive.Trigger;

/**
 * Refit to spec 24 §22.3 (Tooltip is defined implicitly by menu-popover.md and
 * 20-component-choice.md §5 precondition-tooltip pattern). Uses card fill on
 * the elevation-1 shadow (not primary), --radius-sm, Body/S typography, and
 * the shared attach-and-detach motion. Never render interactive controls
 * inside a tooltip; use Popover for that (menu-popover.md §3).
 */
const TooltipContent = React.forwardRef<
  React.ElementRef<typeof TooltipPrimitive.Content>,
  React.ComponentPropsWithoutRef<typeof TooltipPrimitive.Content>
>(({ className, sideOffset = 6, ...props }, ref) => (
  <TooltipPrimitive.Portal>
    <TooltipPrimitive.Content
      ref={ref}
      sideOffset={sideOffset}
      className={cn(
        "z-50 max-w-[240px] overflow-hidden",
        "rounded-[var(--radius-sm)] border border-[var(--border)]",
        "bg-[var(--card)] text-[var(--foreground)]",
        "px-[var(--space-2)] py-[var(--space-1)]",
        "text-xs leading-tight",
        "shadow-[var(--shadow-1)]",
        "data-[state=open]:animate-in data-[state=closed]:animate-out",
        "data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0",
        "data-[state=closed]:zoom-out-95 data-[state=open]:zoom-in-95",
        "data-[side=bottom]:slide-in-from-top-1 data-[side=left]:slide-in-from-right-1",
        "data-[side=right]:slide-in-from-left-1 data-[side=top]:slide-in-from-bottom-1",
        "motion-reduce:animate-none motion-reduce:transition-none",
        "origin-(--radix-tooltip-content-transform-origin)",
        className,
      )}
      {...props}
    />
  </TooltipPrimitive.Portal>
));
TooltipContent.displayName = TooltipPrimitive.Content.displayName;

export { Tooltip, TooltipTrigger, TooltipContent, TooltipProvider };
