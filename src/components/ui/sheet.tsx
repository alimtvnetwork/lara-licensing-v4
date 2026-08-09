"use client";

import * as React from "react";
import * as SheetPrimitive from "@radix-ui/react-dialog";
import { cva, type VariantProps } from "class-variance-authority";
import { X } from "lucide-react";

import { cn } from "@/lib/utils";

/**
 * Sheet refit per spec/24-app-ui-design-system/21-component-dialog.md §6.
 *
 * Edge-attached drawer for edit panels and long secondary flows. Default
 * side = "right" (inline-end); other sides are supported for legacy
 * shadcn call sites but the destructive-contract variant (§5) belongs in
 * `alert-dialog.tsx`, never a Sheet.
 *
 * Geometry: `clamp(360px, 40vw, 640px)` default, `clamp(480px, 55vw,
 * 900px)` for `size="lg"`. `--radius-lg` on the inline-start edge only
 * (inline-end flush). Elevation-2 shadow. Only ONE Sheet MAY be open at
 * a time (AC-DLG-010) - enforcement lives in the caller / route layer.
 */
const Sheet = SheetPrimitive.Root;
const SheetTrigger = SheetPrimitive.Trigger;
const SheetClose = SheetPrimitive.Close;
const SheetPortal = SheetPrimitive.Portal;

const SheetOverlay = React.forwardRef<
  React.ElementRef<typeof SheetPrimitive.Overlay>,
  React.ComponentPropsWithoutRef<typeof SheetPrimitive.Overlay>
>(({ className, ...props }, ref) => (
  <SheetPrimitive.Overlay
    ref={ref}
    className={cn(
      "fixed inset-0 z-50",
      // v0.498.0 (SS-01): match dialog overlay depth so nested modal
      // surfaces read consistently across the app.
      "bg-[color-mix(in_oklab,var(--background)_52%,transparent)] backdrop-blur-[10px] backdrop-saturate-[140%]",
      "data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0",
      className,
    )}
    {...props}
  />
));
SheetOverlay.displayName = SheetPrimitive.Overlay.displayName;

const sheetVariants = cva(
  cn(
    "fixed z-50 flex flex-col bg-[var(--card)] text-[var(--foreground)]",
    // v0.498.0 (SS-01): elevation-2 + inset hairline to match dialog panel.
    "shadow-[var(--shadow-inset-hairline),var(--shadow-2,0_20px_56px_rgba(0,0,0,0.32))]",
    "data-[state=open]:animate-in data-[state=closed]:animate-out",
  ),

  {
    variants: {
      side: {
        right: cn(
          "inset-y-0 right-0 h-full border-l border-[var(--border)]",
          "rounded-l-[var(--radius-lg)]",
          "data-[state=closed]:slide-out-to-right data-[state=open]:slide-in-from-right",
        ),
        left: cn(
          "inset-y-0 left-0 h-full border-r border-[var(--border)]",
          "rounded-r-[var(--radius-lg)]",
          "data-[state=closed]:slide-out-to-left data-[state=open]:slide-in-from-left",
        ),
        top: cn(
          "inset-x-0 top-0 border-b border-[var(--border)]",
          "rounded-b-[var(--radius-lg)]",
          "data-[state=closed]:slide-out-to-top data-[state=open]:slide-in-from-top",
        ),
        bottom: cn(
          "inset-x-0 bottom-0 border-t border-[var(--border)]",
          "rounded-t-[var(--radius-lg)]",
          "data-[state=closed]:slide-out-to-bottom data-[state=open]:slide-in-from-bottom",
        ),
      },
      size: {
        md: "w-[clamp(360px,40vw,640px)]",
        lg: "w-[clamp(480px,55vw,900px)]",
      },
    },
    compoundVariants: [
      { side: "top", size: "md", class: "w-full h-[40vh]" },
      { side: "top", size: "lg", class: "w-full h-[55vh]" },
      { side: "bottom", size: "md", class: "w-full h-[40vh]" },
      { side: "bottom", size: "lg", class: "w-full h-[55vh]" },
    ],
    defaultVariants: { side: "right", size: "md" },
  },
);

interface SheetContentProps
  extends
    React.ComponentPropsWithoutRef<typeof SheetPrimitive.Content>,
    VariantProps<typeof sheetVariants> {}

const SheetContent = React.forwardRef<
  React.ElementRef<typeof SheetPrimitive.Content>,
  SheetContentProps
>(({ side, size, className, children, ...props }, ref) => (
  <SheetPortal>
    <SheetOverlay />
    <SheetPrimitive.Content
      ref={ref}
      className={cn(sheetVariants({ side, size }), className)}
      {...props}
    >
      <SheetPrimitive.Close
        aria-label="Close"
        className={cn(
          "absolute right-3 top-3 grid place-content-center h-8 w-8 rounded-[var(--radius-sm)]",
          "text-[var(--muted-foreground)] hover:text-[var(--foreground)] hover:bg-[color-mix(in_oklab,var(--foreground)_6%,transparent)]",
          "transition-[color,background-color,box-shadow] duration-150",
          "focus-visible:outline-none focus-visible:shadow-[var(--ring-focus-strong)]",
        )}
      >
        <X className="h-4 w-4" aria-hidden="true" />
      </SheetPrimitive.Close>

      {children}
    </SheetPrimitive.Content>
  </SheetPortal>
));
SheetContent.displayName = SheetPrimitive.Content.displayName;

const SheetHeader = ({ className, ...props }: React.HTMLAttributes<HTMLDivElement>) => (
  <div
    className={cn(
      "flex flex-col gap-1 px-[var(--space-4)] py-[var(--space-4)]",
      "border-b border-[var(--border)]",
      className,
    )}
    {...props}
  />
);
SheetHeader.displayName = "SheetHeader";

const SheetBody = ({ className, ...props }: React.HTMLAttributes<HTMLDivElement>) => (
  <div
    className={cn("flex-1 overflow-y-auto px-[var(--space-4)] py-[var(--space-4)]", className)}
    {...props}
  />
);
SheetBody.displayName = "SheetBody";

const SheetFooter = ({ className, ...props }: React.HTMLAttributes<HTMLDivElement>) => (
  <div
    className={cn(
      "flex flex-row-reverse items-center gap-[var(--space-2)] px-[var(--space-4)] py-[var(--space-4)]",
      "border-t border-[var(--border)]",
      className,
    )}
    {...props}
  />
);
SheetFooter.displayName = "SheetFooter";

const SheetTitle = React.forwardRef<
  React.ElementRef<typeof SheetPrimitive.Title>,
  React.ComponentPropsWithoutRef<typeof SheetPrimitive.Title>
>(({ className, ...props }, ref) => (
  <SheetPrimitive.Title
    ref={ref}
    className={cn("text-base font-semibold leading-tight text-[var(--foreground)]", className)}
    {...props}
  />
));
SheetTitle.displayName = SheetPrimitive.Title.displayName;

const SheetDescription = React.forwardRef<
  React.ElementRef<typeof SheetPrimitive.Description>,
  React.ComponentPropsWithoutRef<typeof SheetPrimitive.Description>
>(({ className, ...props }, ref) => (
  <SheetPrimitive.Description
    ref={ref}
    className={cn("text-sm text-[var(--muted-foreground)]", className)}
    {...props}
  />
));
SheetDescription.displayName = SheetPrimitive.Description.displayName;

export {
  Sheet,
  SheetPortal,
  SheetOverlay,
  SheetTrigger,
  SheetClose,
  SheetContent,
  SheetHeader,
  SheetBody,
  SheetFooter,
  SheetTitle,
  SheetDescription,
};
