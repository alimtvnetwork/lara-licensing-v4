import { ArrowDownRight, ArrowUpRight, Minus } from "lucide-react";
import type { ReactNode } from "react";

/**
 * KPI tile per Plan 09 Step 27, Spec 24 §26, and Plan 15 Step 6 (v0.493.0).
 *
 * Depth: the outer shell now uses `@utility surface-elevated` (v0.490.0),
 * which composes `--shadow-inset-hairline` + `--shadow-elevation-2` over
 * `--color-card` at radius 2xl, with a reduced-motion-safe hover lift to
 * elevation-3. No inline `box-shadow` or legacy `--shadow-1` reference.
 *
 * Delta chip: uses `@utility chip` with `data-tone` (v0.491.0). Directions
 * map to closed tones: up -> success, down -> destructive, flat -> neutral
 * (chip default, no data-tone attribute). The tone-to-color map lives in
 * the utility, not this file.
 *
 * Loading and error states are still explicit; error state surfaces an
 * inline caption so callers cannot hide a failure behind a zero.
 */
export type StatCardDeltaDirection = "up" | "down" | "flat";

export type StatCardState = "ready" | "loading" | "error";

export interface StatCardProps {
  label: string;
  value: string;
  hint?: string;
  delta?: { label: string; direction: StatCardDeltaDirection };
  sparkline?: ReactNode;
  state?: StatCardState;
  errorMessage?: string;
}

const deltaToneMap: Record<StatCardDeltaDirection, "success" | "destructive" | undefined> = {
  up: "success",
  down: "destructive",
  flat: undefined,
};

export function StatCard(props: StatCardProps) {
  const state: StatCardState = props.state ?? "ready";
  return (
    <div className="surface-elevated p-4" data-ui="stat-card" data-state={state}>
      <div
        className="text-xs font-medium uppercase tracking-[0.06em] text-muted-foreground"
        style={{ fontFamily: "var(--font-sans)" }}
      >
        {props.label}
      </div>
      <div className="mt-2 flex items-baseline justify-between gap-3">
        <StatCardValue state={state} value={props.value} />
        {props.delta && state === "ready" ? <DeltaChip delta={props.delta} /> : null}
      </div>
      {props.sparkline && state === "ready" ? (
        <div className="mt-3" data-slot="sparkline">
          {props.sparkline}
        </div>
      ) : null}
      <StatCardFooter state={state} hint={props.hint} errorMessage={props.errorMessage} />
    </div>
  );
}

function StatCardValue({ state, value }: { state: StatCardState; value: string }) {
  if (state === "loading") {
    return (
      <div
        aria-hidden="true"
        className="h-8 w-24 animate-pulse rounded bg-muted"
        data-slot="value-skeleton"
      />
    );
  }
  if (state === "error") {
    return <span className="text-2xl font-semibold text-destructive">--</span>;
  }
  return (
    <span
      className="text-2xl font-semibold tracking-tight text-foreground"
      style={{ fontFamily: "var(--font-display)" }}
    >
      {value}
    </span>
  );
}

function DeltaChip({ delta }: { delta: NonNullable<StatCardProps["delta"]> }) {
  const Icon =
    delta.direction === "up" ? ArrowUpRight : delta.direction === "down" ? ArrowDownRight : Minus;
  const tone = deltaToneMap[delta.direction];
  return (
    <span
      className="chip"
      data-slot="delta"
      data-direction={delta.direction}
      {...(tone ? { "data-tone": tone } : {})}
    >
      <Icon aria-hidden="true" className="size-3" />
      {delta.label}
    </span>
  );
}

function StatCardFooter(props: { state: StatCardState; hint?: string; errorMessage?: string }) {
  if (props.state === "error") {
    return (
      <p className="mt-2 text-xs text-destructive" role="alert">
        {props.errorMessage ?? "Failed to load metric."}
      </p>
    );
  }
  if (typeof props.hint === "string" && props.hint.length > 0) {
    return <p className="mt-2 text-xs text-muted-foreground">{props.hint}</p>;
  }
  return null;
}
