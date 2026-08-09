import * as React from "react";

import { cn } from "@/lib/utils";

/**
 * Card variants. Plan 15 step 5 (v0.492.0):
 *
 *   default   Legacy shadcn shell: rounded-xl border, bg-card, base shadow.
 *             Byte-identical to prior releases; unchanged for every existing
 *             consumer that omits `variant`.
 *   elevated  Applies @utility surface-elevated (src/styles.css). Composes
 *             --shadow-inset-hairline + --shadow-elevation-2 over --color-card
 *             with a rounded-2xl radius and a reduced-motion-safe hover lift.
 *             Adopted by stat-card.tsx (step 6), admin overview KPI grid
 *             (step 7), and auth cards (step 25). Do not layer surface-elevated
 *             on top of the default shell: pass the variant and let the utility
 *             own radius + shadow.
 */
export type CardVariant = "default" | "elevated";

const cardVariantClass: Record<CardVariant, string> = {
  default: "rounded-xl border bg-card text-card-foreground shadow",
  elevated: "surface-elevated",
};

export interface CardProps extends React.HTMLAttributes<HTMLDivElement> {
  variant?: CardVariant;
}

const Card = React.forwardRef<HTMLDivElement, CardProps>(
  ({ className, variant = "default", ...props }, ref) => (
    <div ref={ref} className={cn(cardVariantClass[variant], className)} {...props} />
  ),
);
Card.displayName = "Card";

const CardHeader = React.forwardRef<HTMLDivElement, React.HTMLAttributes<HTMLDivElement>>(
  ({ className, ...props }, ref) => (
    <div ref={ref} className={cn("flex flex-col space-y-1.5 p-6", className)} {...props} />
  ),
);
CardHeader.displayName = "CardHeader";

const CardTitle = React.forwardRef<HTMLDivElement, React.HTMLAttributes<HTMLDivElement>>(
  ({ className, ...props }, ref) => (
    <div
      ref={ref}
      className={cn("font-semibold leading-none tracking-tight", className)}
      {...props}
    />
  ),
);
CardTitle.displayName = "CardTitle";

const CardDescription = React.forwardRef<HTMLDivElement, React.HTMLAttributes<HTMLDivElement>>(
  ({ className, ...props }, ref) => (
    <div ref={ref} className={cn("text-sm text-muted-foreground", className)} {...props} />
  ),
);
CardDescription.displayName = "CardDescription";

const CardContent = React.forwardRef<HTMLDivElement, React.HTMLAttributes<HTMLDivElement>>(
  ({ className, ...props }, ref) => (
    <div ref={ref} className={cn("p-6 pt-0", className)} {...props} />
  ),
);
CardContent.displayName = "CardContent";

const CardFooter = React.forwardRef<HTMLDivElement, React.HTMLAttributes<HTMLDivElement>>(
  ({ className, ...props }, ref) => (
    <div ref={ref} className={cn("flex items-center p-6 pt-0", className)} {...props} />
  ),
);
CardFooter.displayName = "CardFooter";

export { Card, CardHeader, CardFooter, CardTitle, CardDescription, CardContent };
