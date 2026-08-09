// Button primitive. Normative source: spec 24 §17-component-button.md.
// Ships the variant + intent grammar (§2), size ladder (§3), loading
// state (§6), and press motion (§4) from spec 51 §6. Legacy shadcn
// props (variant="default|destructive|outline|secondary|ghost|link",
// size="default|sm|lg|icon") are accepted as a compat shim and mapped
// to variant + intent so we do not break the 92 existing call sites in
// a single commit; new call sites SHOULD use `intent`.

import * as React from "react";
import { Slot } from "@radix-ui/react-slot";
import { cva, type VariantProps } from "class-variance-authority";
import { Loader2 } from "lucide-react";

import { cn } from "@/lib/utils";

const buttonVariants = cva(
  [
    "inline-flex items-center justify-center gap-2 whitespace-nowrap rounded-md",
    "text-sm font-medium cursor-pointer select-none",
    "transition-[background-color,border-color,color,box-shadow,transform]",
    "duration-[var(--motion-duration-xs)] ease-[var(--motion-easing-standard)]",
    "focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background",
    "disabled:pointer-events-none disabled:opacity-50 disabled:cursor-not-allowed",
    "aria-disabled:pointer-events-none aria-disabled:opacity-50 aria-disabled:cursor-not-allowed",
    "active:motion-safe:translate-y-[var(--motion-distance-xs)]",
    "[&_svg]:pointer-events-none [&_svg]:shrink-0",
  ].join(" "),
  {
    variants: {
      variant: {
        // Canonical (spec 24 §17 §2.1)
        solid: "",
        outline: "border bg-transparent",
        ghost: "bg-transparent",
        link: "underline-offset-4 hover:underline px-0 h-auto min-h-0",
        // Legacy shadcn aliases; mapped to intent below via defaultProps logic.
        default: "",
        destructive: "",
        secondary: "",
      },
      intent: {
        neutral: "",
        primary: "",
        destructive: "",
      },
      size: {
        // Canonical spec ladder
        sm: "h-8 px-3 [&_svg]:size-4",
        md: "h-10 px-4 [&_svg]:size-4",
        lg: "h-12 px-5 text-base [&_svg]:size-5",
        // Legacy aliases
        default: "h-9 px-4 py-2 [&_svg]:size-4",
        icon: "size-10 [&_svg]:size-4",
      },
    },
    compoundVariants: [
      // solid + intent. Plan 15 step 8 (v0.495.0): primary CTA gets a
      // primary->accent gradient and a `--ring-focus-strong` box-shadow ring
      // (from v0.489.0) that replaces the default 2px focus ring so the
      // highest-priority action has a distinct focus signal. Neutral and
      // destructive keep the standard focus ring.
      {
        variant: "solid",
        intent: "primary",
        className: [
          "text-primary-foreground shadow-[var(--shadow-elevation-1)]",
          "bg-[image:linear-gradient(135deg,var(--color-primary)_0%,color-mix(in_oklab,var(--color-primary)_82%,var(--color-accent))_100%)]",
          "hover:brightness-[1.05] hover:shadow-[var(--shadow-elevation-2)]",
          "focus-visible:ring-0 focus-visible:ring-offset-0 focus-visible:shadow-[var(--ring-focus-strong)]",
        ].join(" "),
      },
      {
        variant: "solid",
        intent: "neutral",
        className: "bg-secondary text-secondary-foreground hover:bg-secondary/80",
      },
      {
        variant: "solid",
        intent: "destructive",
        className: "bg-destructive text-destructive-foreground hover:bg-destructive/90",
      },
      // outline + intent
      {
        variant: "outline",
        intent: "neutral",
        className: "border-input text-foreground hover:bg-accent hover:text-accent-foreground",
      },
      {
        variant: "outline",
        intent: "primary",
        className: "border-primary text-primary hover:bg-primary/10",
      },
      {
        variant: "outline",
        intent: "destructive",
        className: "border-destructive text-destructive hover:bg-destructive/10",
      },
      // ghost + intent
      {
        variant: "ghost",
        intent: "neutral",
        className: "text-foreground hover:bg-accent hover:text-accent-foreground",
      },
      { variant: "ghost", intent: "primary", className: "text-primary hover:bg-primary/10" },
      {
        variant: "ghost",
        intent: "destructive",
        className: "text-destructive hover:bg-destructive/10",
      },
      // link + intent
      { variant: "link", intent: "primary", className: "text-primary" },
      { variant: "link", intent: "neutral", className: "text-foreground" },
      { variant: "link", intent: "destructive", className: "text-destructive" },
      // Legacy variant compat -> visual parity with the new primary gradient
      // + strong focus ring so 92 pre-existing call sites inherit the refit
      // without touching a single import.
      {
        variant: "default",
        className: [
          "text-primary-foreground shadow-[var(--shadow-elevation-1)]",
          "bg-[image:linear-gradient(135deg,var(--color-primary)_0%,color-mix(in_oklab,var(--color-primary)_82%,var(--color-accent))_100%)]",
          "hover:brightness-[1.05] hover:shadow-[var(--shadow-elevation-2)]",
          "focus-visible:ring-0 focus-visible:ring-offset-0 focus-visible:shadow-[var(--ring-focus-strong)]",
        ].join(" "),
      },
      {
        variant: "destructive",
        className: "bg-destructive text-destructive-foreground shadow-sm hover:bg-destructive/90",
      },
      {
        variant: "secondary",
        className: "bg-secondary text-secondary-foreground shadow-sm hover:bg-secondary/80",
      },
    ],

    defaultVariants: {
      variant: "solid",
      intent: "primary",
      size: "md",
    },
  },
);

export interface ButtonProps
  extends
    Omit<React.ButtonHTMLAttributes<HTMLButtonElement>, "disabled">,
    VariantProps<typeof buttonVariants> {
  asChild?: boolean;
  loading?: boolean;
  disabled?: boolean;
}

const Button = React.forwardRef<HTMLButtonElement, ButtonProps>(function Button(
  {
    className,
    variant,
    intent,
    size,
    asChild = false,
    loading = false,
    disabled,
    children,
    ...props
  },
  ref,
) {
  const Comp = asChild ? Slot : "button";
  const busy = loading === true;
  const inert = busy || disabled === true;
  // aria-busy/aria-disabled per spec 24 §17 §6; keep focusability when busy.
  return (
    <Comp
      className={cn(buttonVariants({ variant, intent, size, className }))}
      ref={ref}
      aria-busy={busy || undefined}
      aria-disabled={inert || undefined}
      disabled={!asChild && disabled === true ? true : undefined}
      {...props}
    >
      {busy ? <Loader2 aria-hidden className="animate-spin" /> : null}
      {children}
    </Comp>
  );
});

export { Button, buttonVariants };
