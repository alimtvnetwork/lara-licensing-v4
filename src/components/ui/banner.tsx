import * as React from "react";
import { AlertTriangle, CheckCircle2, Info, X, XCircle, type LucideIcon } from "lucide-react";
import { cva, type VariantProps } from "class-variance-authority";

import { cn } from "@/lib/utils";

/**
 * Banner primitive per spec 24 §23.3. Persistent in-surface message that does
 * NOT auto-dismiss. Distinct from Toast (transient corner) and Alert (legacy).
 *
 * Rules (spec §23.3 + §23.7):
 * - Error and success variants are non-dismissible (AC-BAN-001). Only info
 *   and warning MAY render a dismiss X, opt-in via `dismissible` prop.
 * - role/aria-live derives from variant: info/success = status/polite,
 *   warning/error = alert/assertive (§23.3.3).
 * - Max 2 action Buttons. Enforce at call site: this component does not
 *   count children.
 * - Do NOT use for field-level errors (Field error slot) or for transient
 *   success (Toast). For RateLimited, <RetryAfterBanner> composes this
 *   primitive with warning intent and owns the Retry-After countdown.
 */

const bannerVariants = cva(
  cn(
    "relative flex w-full gap-[var(--space-3)]",
    "rounded-[var(--radius-md)] border",
    "px-[var(--space-4)] py-[var(--space-3)]",
    "text-sm",
  ),
  {
    variants: {
      intent: {
        info: cn(
          "border-[var(--info,var(--primary))]/40",
          "bg-[color-mix(in_oklab,var(--info,var(--primary))_8%,var(--background))]",
          "text-[var(--foreground)]",
        ),
        success: cn(
          "border-[var(--success,var(--primary))]/40",
          "bg-[color-mix(in_oklab,var(--success,var(--primary))_8%,var(--background))]",
          "text-[var(--foreground)]",
        ),
        warning: cn(
          "border-[var(--warning,var(--primary))]/50",
          "bg-[color-mix(in_oklab,var(--warning,var(--primary))_10%,var(--background))]",
          "text-[var(--foreground)]",
        ),
        error: cn(
          "border-[var(--destructive)]/50",
          "bg-[color-mix(in_oklab,var(--destructive)_10%,var(--background))]",
          "text-[var(--foreground)]",
        ),
      },
    },
    defaultVariants: { intent: "info" },
  },
);

const iconByIntent: Record<NonNullable<BannerIntent>, LucideIcon> = {
  info: Info,
  success: CheckCircle2,
  warning: AlertTriangle,
  error: XCircle,
};

const iconColorByIntent: Record<NonNullable<BannerIntent>, string> = {
  info: "text-[var(--info,var(--primary))]",
  success: "text-[var(--success,var(--primary))]",
  warning: "text-[var(--warning,var(--primary))]",
  error: "text-[var(--destructive)]",
};

type BannerIntent = "info" | "success" | "warning" | "error";
type BannerAria = { role: "status" | "alert"; "aria-live": "polite" | "assertive" };

function ariaForIntent(intent: BannerIntent): BannerAria {
  if (intent === "warning" || intent === "error") {
    return { role: "alert", "aria-live": "assertive" };
  }
  return { role: "status", "aria-live": "polite" };
}

export interface BannerProps
  extends React.HTMLAttributes<HTMLDivElement>, VariantProps<typeof bannerVariants> {
  intent?: BannerIntent;
  dismissible?: boolean;
  onDismiss?: () => void;
}

const Banner = React.forwardRef<HTMLDivElement, BannerProps>(function Banner(
  { intent = "info", dismissible = false, onDismiss, className, children, ...rest },
  ref,
) {
  if (dismissible && (intent === "error" || intent === "success")) {
    // AC-BAN-001: error/success Banners MUST NOT be dismissible.
    throw new Error(
      `Banner intent "${intent}" MUST NOT be dismissible (spec 24 §23.3.3, AC-BAN-001)`,
    );
  }
  const Icon = iconByIntent[intent];
  const aria = ariaForIntent(intent);
  return (
    <div
      ref={ref}
      className={cn(bannerVariants({ intent }), className)}
      data-banner-intent={intent}
      {...aria}
      {...rest}
    >
      <Icon aria-hidden className={cn("mt-0.5 h-4 w-4 shrink-0", iconColorByIntent[intent])} />
      <div className="flex-1">{children}</div>
      {dismissible ? (
        <button
          type="button"
          onClick={onDismiss}
          aria-label="Dismiss"
          className={cn(
            "shrink-0 rounded-[var(--radius-sm)] p-1",
            "text-[var(--muted-foreground)] hover:text-[var(--foreground)]",
            "focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[var(--ring)] focus-visible:ring-offset-2 focus-visible:ring-offset-[var(--background)]",
          )}
        >
          <X className="h-4 w-4" />
        </button>
      ) : null}
    </div>
  );
});

const BannerTitle = React.forwardRef<
  HTMLParagraphElement,
  React.HTMLAttributes<HTMLParagraphElement>
>(({ className, ...props }, ref) => (
  <p ref={ref} className={cn("font-medium leading-tight", className)} {...props} />
));
BannerTitle.displayName = "BannerTitle";

const BannerDescription = React.forwardRef<
  HTMLParagraphElement,
  React.HTMLAttributes<HTMLParagraphElement>
>(({ className, ...props }, ref) => (
  <p
    ref={ref}
    className={cn("mt-[var(--space-1)] text-[var(--muted-foreground)]", className)}
    {...props}
  />
));
BannerDescription.displayName = "BannerDescription";

const BannerActions = React.forwardRef<HTMLDivElement, React.HTMLAttributes<HTMLDivElement>>(
  ({ className, children, ...props }, ref) => {
    const count = React.Children.toArray(children).filter(Boolean).length;
    if (count > 2) {
      // Spec 24 §23.3.3: max 2 action Buttons. Fail loudly rather than clip.
      throw new Error(
        `<BannerActions> holds ${count} children; spec 24 §23.3.3 caps action Buttons at 2.`,
      );
    }
    return (
      <div
        ref={ref}
        className={cn("mt-[var(--space-2)] flex gap-[var(--space-2)]", className)}
        {...props}
      >
        {children}
      </div>
    );
  },
);
BannerActions.displayName = "BannerActions";

export { Banner, BannerTitle, BannerDescription, BannerActions };
