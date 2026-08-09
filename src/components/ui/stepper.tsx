import * as React from "react";

import { cn } from "@/lib/utils";

/**
 * Stepper primitive, Plan 09 Step 35.
 *
 * Root cause guarded: `admin.resellers.new.tsx` and `admin.licenses.new.tsx`
 * are multi-decision creates (identity -> prefixes -> confirm, and
 * reseller -> tier -> features -> environments -> confirm) that were
 * rendered as one flat form, so the user got no progress signal and no
 * review gate before an idempotent POST fired.
 *
 * This component is presentational only: it owns the progress rail, the
 * accessible-name wiring, and the closed status set. Step state, validation,
 * and navigation stay in the calling wizard so each route keeps its own
 * mutation semantics.
 *
 * Semantics:
 * - Rendered as an ordered list so screen readers announce "N of M".
 * - The active step carries `aria-current="step"`.
 * - Completed steps expose a check glyph and stay keyboard-reachable when
 *   `onStepSelect` is supplied (back-navigation only, never skip-ahead).
 * - Colors come from semantic tokens only (`--color-primary`, `--color-muted-foreground`,
 *   `--color-border`); no literals, per `.lovable/memory/style/fluid-palette.md`.
 */

export interface StepperStep {
  /** Stable key, also used as the DOM id suffix. */
  id: string;
  /** Short label, sentence case. */
  label: string;
  /** Optional one-line hint rendered under the label. */
  description?: string;
}

export type StepperStatus = "complete" | "current" | "upcoming";

export interface StepperProps {
  steps: readonly StepperStep[];
  /** Zero-based index of the active step. */
  activeIndex: number;
  /**
   * When provided, completed steps become buttons that jump back.
   * Never called for upcoming steps: forward motion belongs to the wizard.
   */
  onStepSelect?: (index: number) => void;
  /** Accessible name for the whole rail. */
  label?: string;
  className?: string;
}

export function statusForIndex(index: number, activeIndex: number): StepperStatus {
  if (index < activeIndex) return "complete";
  if (index === activeIndex) return "current";
  return "upcoming";
}

export function Stepper({
  steps,
  activeIndex,
  onStepSelect,
  label = "Progress",
  className,
}: StepperProps) {
  return (
    <nav aria-label={label} className={cn("w-full", className)}>
      <ol className="flex flex-col gap-3 sm:flex-row sm:items-start sm:gap-0">
        {steps.map((step, index) => {
          const status = statusForIndex(index, activeIndex);
          const isLast = index === steps.length - 1;
          return (
            <li
              key={step.id}
              className={cn("flex items-start gap-3 sm:flex-1 sm:flex-col sm:gap-2")}
              aria-current={status === "current" ? "step" : undefined}
            >
              <div className="flex w-full items-center gap-3">
                <StepMarker
                  index={index}
                  status={status}
                  step={step}
                  onSelect={
                    status === "complete" && onStepSelect !== undefined
                      ? () => onStepSelect(index)
                      : undefined
                  }
                />
                {!isLast ? (
                  <span
                    aria-hidden="true"
                    className={cn(
                      "hidden h-px flex-1 sm:block",
                      status === "complete" ? "bg-primary" : "bg-border",
                    )}
                  />
                ) : null}
              </div>
              <div className="flex flex-col sm:pr-4">
                <span
                  className={cn(
                    "text-sm font-medium",
                    status === "upcoming" ? "text-muted-foreground" : "text-foreground",
                  )}
                >
                  {step.label}
                </span>
                {step.description !== undefined ? (
                  <span className="text-xs text-muted-foreground">{step.description}</span>
                ) : null}
              </div>
            </li>
          );
        })}
      </ol>
    </nav>
  );
}

interface StepMarkerProps {
  index: number;
  status: StepperStatus;
  step: StepperStep;
  onSelect?: () => void;
}

function StepMarker({ index, status, step, onSelect }: StepMarkerProps) {
  const content = status === "complete" ? <CheckGlyph /> : String(index + 1);
  const shared = cn(
    "flex size-8 shrink-0 items-center justify-center rounded-full border text-sm font-medium transition-colors",
    status === "complete" && "border-primary bg-primary text-primary-foreground",
    status === "current" && "border-primary text-primary",
    status === "upcoming" && "border-border text-muted-foreground",
  );

  if (onSelect === undefined) {
    return (
      <span className={shared} data-status={status} aria-hidden="true">
        {content}
      </span>
    );
  }

  return (
    <button
      type="button"
      onClick={onSelect}
      data-status={status}
      className={cn(
        shared,
        "outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2",
      )}
    >
      <span className="sr-only">{`Back to step ${String(index + 1)}: ${step.label}`}</span>
      <span aria-hidden="true">{content}</span>
    </button>
  );
}

function CheckGlyph() {
  return (
    <svg viewBox="0 0 16 16" className="size-4" fill="none" aria-hidden="true">
      <path
        d="M3.5 8.5l3 3 6-7"
        stroke="currentColor"
        strokeWidth="2"
        strokeLinecap="round"
        strokeLinejoin="round"
      />
    </svg>
  );
}
