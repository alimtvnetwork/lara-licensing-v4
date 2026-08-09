import type { ReactNode } from "react";
import { cn } from "@/lib/utils";

export type TimelineTone = "neutral" | "primary" | "success" | "warning" | "danger";

export interface TimelineEntry {
  readonly id: string | number;
  readonly title: ReactNode;
  readonly description?: ReactNode;
  readonly timestamp?: string;
  readonly tone?: TimelineTone;
  readonly trailing?: ReactNode;
}

export interface TimelineProps {
  readonly entries: readonly TimelineEntry[];
  readonly emptyState?: ReactNode;
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

export function Timeline({ entries, emptyState, ariaLabel, className }: TimelineProps) {
  if (entries.length === 0) {
    return <>{emptyState ?? null}</>;
  }
  return (
    <ol
      aria-label={ariaLabel ?? "Activity timeline"}
      className={cn(
        "relative ms-2 border-s border-border ps-6",
        className,
      )}
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
        {entry.trailing ? (
          <div className="shrink-0">{entry.trailing}</div>
        ) : null}
      </div>
    </li>
  );
}
