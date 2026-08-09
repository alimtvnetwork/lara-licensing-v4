// Plan 09 step 28. Timeline primitive.
//
// Consumers (in this order of adoption): admin dashboard recent activity
// (step 29), admin reseller detail activity panel (step 34), and admin
// license detail ledger (step 40). Kept dependency-free (no motion, no
// portal, no popover) so it can be dropped into any route without pulling
// framer-motion into the initial route chunk.
//
// Design rules baked in:
//   - Semantic <ol> so screen readers hear "1 of N" without extra ARIA.
//   - Left rail is a 2px `--border` line; each dot is a token-driven
//     currentColor circle so tone (`neutral | primary | success | warning
//     | danger`) rethemes via `text-<tone>` utilities.
//   - No hardcoded colors; all surfaces resolve to Spec 24 tokens.
//   - `tone` values map 1:1 to the closed-set of audit severities we use
//     elsewhere (see spec/21-app/12-error-taxonomy.md). Adding a new tone
//     requires editing this file and the type union, forcing a review.

import type { ReactNode } from "react";
import { cn } from "@/lib/utils";

export type TimelineTone = "neutral" | "primary" | "success" | "warning" | "danger";

export interface TimelineEntry {
  /** Stable key. When rendering audit rows, use `AuditLogId`. */
  readonly id: string | number;
  /** One-line summary. Rendered as the entry heading. */
  readonly title: ReactNode;
  /** Optional secondary line (actor, target, diff summary). */
  readonly description?: ReactNode;
  /** Absolute timestamp (ISO). Rendered as `<time dateTime=...>`. */
  readonly timestamp?: string;
  /** Semantic tone; drives dot color via `text-<tone>` token. */
  readonly tone?: TimelineTone;
  /** Optional trailing slot (chips, action buttons). */
  readonly trailing?: ReactNode;
}

export interface TimelineProps {
  readonly entries: readonly TimelineEntry[];
  /** Rendered when `entries` is empty. Caller owns the copy. */
  readonly emptyState?: ReactNode;
  /** Optional aria-label overriding the default "Activity timeline". */
  readonly ariaLabel?: string;
  readonly className?: string;
}

const TONE_CLASS: Record<TimelineTone, string> = {
  neutral: "text-muted-foreground",
  primary: "text-primary",
  success: "text-success",
  warning: "text-warning",
  danger: "text-destructive",
};

/**
 * Ordered list of timestamped events. Empty `entries` renders the caller
 * supplied `emptyState` (or nothing) rather than a bare list so the parent
 * can substitute an `EmptyState` illustration without wrapping this
 * component in a conditional.
 */
export function Timeline({ entries, emptyState, ariaLabel, className }: TimelineProps) {
  if (entries.length === 0) {
    return <>{emptyState ?? null}</>;
  }
  return (
    <ol
      aria-label={ariaLabel ?? "Activity timeline"}
      className={cn("relative ms-2 border-s border-border ps-6", className)}
      data-testid="timeline"
    >
      {entries.map((entry) => (
        <TimelineRow key={entry.id} entry={entry} />
      ))}
    </ol>
  );
}

function TimelineRow({ entry }: { entry: TimelineEntry }) {
  const tone: TimelineTone = entry.tone ?? "neutral";
  return (
    <li className="relative py-3" data-testid="timeline-row" data-tone={tone}>
      <span
        aria-hidden="true"
        className={cn(
          "absolute -start-[7px] top-4 inline-flex size-3 rounded-full bg-background ring-2 ring-current",
          TONE_CLASS[tone],
        )}
      />
      <div className="flex items-start justify-between gap-3">
        <div className="min-w-0 flex-1">
          <p className="text-sm font-medium text-foreground">{entry.title}</p>
          {entry.description ? (
            <p className="mt-0.5 text-xs text-muted-foreground">{entry.description}</p>
          ) : null}
          {entry.timestamp ? (
            <time
              dateTime={entry.timestamp}
              className="mt-1 block text-[11px] uppercase tracking-wide text-muted-foreground"
            >
              {entry.timestamp}
            </time>
          ) : null}
        </div>
        {entry.trailing ? <div className="shrink-0">{entry.trailing}</div> : null}
      </div>
    </li>
  );
}
