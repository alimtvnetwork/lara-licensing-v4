"use client";

import * as React from "react";
import * as DialogPrimitive from "@radix-ui/react-dialog";
import { X } from "lucide-react";

import { cn } from "@/lib/utils";

/**
 * Dialog refit per spec/24-app-ui-design-system/21-component-dialog.md.
 *
 * Overlay (§3): `color-mix(in oklab, var(--background) 40%, transparent)`
 * with `backdrop-filter: blur(4px)`. Container: elevation-2 shadow,
 * `--radius-lg`, `min(560px, 100vw - 2 * --space-4)` default max-inline,
 * `min(400px, ...)` for the destructive confirmation variant (`size="sm"`).
 * Header + footer are sticky within the container so long body content
 * scrolls without pushing the primary Button off-screen.
 *
 * Motion (§4): `attach-and-detach` recipe with opacity + block-translate
 * + scale via tailwindcss-animate primitives; `motion-reduce` zeroes the
 * translate/scale via the global rule in `src/styles.css` §prefers-reduced.
 *
 * X close button (§2): rendered by default; destructive confirmation
 * Dialogs pass `showClose={false}` per spec §5.
 */
const Dialog = DialogPrimitive.Root;
const DialogTrigger = DialogPrimitive.Trigger;
const DialogPortal = DialogPrimitive.Portal;
const DialogClose = DialogPrimitive.Close;

const DialogOverlay = React.forwardRef<
  React.ElementRef<typeof DialogPrimitive.Overlay>,
  React.ComponentPropsWithoutRef<typeof DialogPrimitive.Overlay>
>(({ className, ...props }, ref) => (
  <DialogPrimitive.Overlay
    ref={ref}
    className={cn(
      "fixed inset-0 z-50",
      // v0.498.0 (SS-01): deeper dim + stronger blur so the panel reads as
      // clearly elevated above the shell (matches the glass topbar recipe).
      "bg-[color-mix(in_oklab,var(--background)_52%,transparent)] backdrop-blur-[10px] backdrop-saturate-[140%]",
      "data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0",
      className,
    )}
    {...props}
  />
));
DialogOverlay.displayName = DialogPrimitive.Overlay.displayName;

type DialogSize = "sm" | "md" | "lg";

interface DialogContentProps extends React.ComponentPropsWithoutRef<
  typeof DialogPrimitive.Content
> {
  /**
   * Size preset per spec §3. `sm` (400px) is the destructive confirmation
   * variant; callers using `sm` MUST also pass `showClose={false}` and
   * `role="alertdialog"` via the wrapper (see `alert-dialog.tsx`).
   */
  size?: DialogSize;
  /** Render the header X close button. Forbidden on destructive Dialogs (spec §5, AC-DLG-013). */
  showClose?: boolean;
}

const SIZE_TO_CLASS: Record<DialogSize, string> = {
  sm: "max-w-[min(400px,calc(100vw-2*var(--space-4)))]",
  md: "max-w-[min(560px,calc(100vw-2*var(--space-4)))]",
  lg: "max-w-[min(720px,calc(100vw-2*var(--space-4)))]",
};

const DialogContent = React.forwardRef<
  React.ElementRef<typeof DialogPrimitive.Content>,
  DialogContentProps
>(({ className, children, size = "md", showClose = true, ...props }, ref) => (
  <DialogPortal>
    <DialogOverlay />
    <DialogPrimitive.Content
      ref={ref}
      className={cn(
        "fixed left-1/2 top-1/2 z-50 grid w-full -translate-x-1/2 -translate-y-1/2",
        SIZE_TO_CLASS[size],
        "max-h-[calc(100vh-2*var(--space-4))]",
        // v0.498.0 (SS-01): compose the surface-elevated recipe (hairline
        // inset + elevation-2 shadow) and keep the legacy fallback for
        // browsers without color-mix. Border is dropped in favor of the
        // inset hairline so the panel edge reads as one continuous surface.
        "rounded-[var(--radius-lg)] bg-[var(--card)] text-[var(--foreground)]",
        "shadow-[var(--shadow-inset-hairline),var(--shadow-2,0_20px_56px_rgba(0,0,0,0.32))]",
        "data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0 data-[state=closed]:zoom-out-95 data-[state=open]:zoom-in-95",
        className,
      )}
      {...props}
    >
      {children}
      {showClose ? (
        <DialogPrimitive.Close
          aria-label="Close"
          className={cn(
            "absolute right-3 top-3 grid place-content-center h-8 w-8 rounded-[var(--radius-sm)]",
            "text-[var(--muted-foreground)] hover:text-[var(--foreground)] hover:bg-[color-mix(in_oklab,var(--foreground)_6%,transparent)]",
            "transition-[color,background-color,box-shadow] duration-150",
            "focus-visible:outline-none focus-visible:shadow-[var(--ring-focus-strong)]",
          )}
        >
          <X className="h-4 w-4" aria-hidden="true" />
        </DialogPrimitive.Close>
      ) : null}
    </DialogPrimitive.Content>
  </DialogPortal>
));
DialogContent.displayName = DialogPrimitive.Content.displayName;

/**
 * Header is sticky per spec §3 so long body content scrolls beneath it.
 * Padding: `--space-4` inline + block.
 */
const DialogHeader = ({ className, ...props }: React.HTMLAttributes<HTMLDivElement>) => (
  <div
    className={cn(
      "sticky top-0 z-10 bg-[var(--card)]",
      "flex flex-col gap-1 px-[var(--space-4)] py-[var(--space-4)]",
      "border-b border-[var(--border)]",
      className,
    )}
    {...props}
  />
);
DialogHeader.displayName = "DialogHeader";

/**
 * Footer is sticky per spec §3, primary Button inline-end. Layout uses
 * flex-row-reverse so the primary sits at the inline-end regardless of
 * the DOM order the caller writes (matches spec §2 anatomy).
 */
const DialogFooter = ({ className, ...props }: React.HTMLAttributes<HTMLDivElement>) => (
  <div
    className={cn(
      "sticky bottom-0 z-10 bg-[var(--card)]",
      "flex flex-row-reverse items-center gap-[var(--space-2)] px-[var(--space-4)] py-[var(--space-4)]",
      "border-t border-[var(--border)]",
      className,
    )}
    {...props}
  />
);
DialogFooter.displayName = "DialogFooter";

const DialogBody = ({ className, ...props }: React.HTMLAttributes<HTMLDivElement>) => (
  <div
    className={cn("overflow-y-auto px-[var(--space-4)] py-[var(--space-4)]", className)}
    {...props}
  />
);
DialogBody.displayName = "DialogBody";

const DialogTitle = React.forwardRef<
  React.ElementRef<typeof DialogPrimitive.Title>,
  React.ComponentPropsWithoutRef<typeof DialogPrimitive.Title>
>(({ className, ...props }, ref) => (
  <DialogPrimitive.Title
    ref={ref}
    className={cn(
      "text-base font-semibold leading-tight tracking-tight text-[var(--foreground)]",
      className,
    )}
    {...props}
  />
));
DialogTitle.displayName = DialogPrimitive.Title.displayName;

const DialogDescription = React.forwardRef<
  React.ElementRef<typeof DialogPrimitive.Description>,
  React.ComponentPropsWithoutRef<typeof DialogPrimitive.Description>
>(({ className, ...props }, ref) => (
  <DialogPrimitive.Description
    ref={ref}
    className={cn("text-sm text-[var(--muted-foreground)]", className)}
    {...props}
  />
));
DialogDescription.displayName = DialogPrimitive.Description.displayName;

export {
  Dialog,
  DialogPortal,
  DialogOverlay,
  DialogTrigger,
  DialogClose,
  DialogContent,
  DialogHeader,
  DialogBody,
  DialogFooter,
  DialogTitle,
  DialogDescription,
};
