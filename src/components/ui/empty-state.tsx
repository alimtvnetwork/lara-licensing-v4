// Plan 09 step 94. In-page empty-state primitive per Spec 24 §26.
//
// Distinct from `StateNotFound` (route-level 404 shell): this component
// renders inside an already-mounted route when a list query legitimately
// returns zero rows. Two illustration presets (`box` for "no records
// exist", `search` for "no filter matches") cover the entire product's
// empty surface.

import type { ReactNode } from "react";

import { EmptyBoxIllustration } from "@/assets/illustrations/EmptyBoxIllustration";
import { EmptySearchIllustration } from "@/assets/illustrations/EmptySearchIllustration";
import { cn } from "@/lib/utils";

export type EmptyStatePreset = "box" | "search";

export type EmptyStateProps = {
  /** Preset illustration. Use `search` for filter/query zero-hits, `box` otherwise. */
  preset?: EmptyStatePreset;
  /** Override illustration entirely. Takes precedence over `preset`. */
  illustration?: ReactNode;
  headline: string;
  body?: string;
  primary?: ReactNode;
  secondary?: ReactNode;
  className?: string;
};

const PRESET_NODES: Record<EmptyStatePreset, ReactNode> = {
  box: <EmptyBoxIllustration className="h-32 w-auto" />,
  search: <EmptySearchIllustration className="h-32 w-auto" />,
};

export function EmptyState(props: EmptyStateProps) {
  const illustration = props.illustration ?? PRESET_NODES[props.preset ?? "box"];
  return (
    <div
      role="status"
      className={cn(
        "mx-auto grid max-w-[420px] justify-items-center gap-3 py-10 text-center",
        props.className,
      )}
      data-testid="empty-state"
    >
      {/*
       * v0.503.0 (Plan 15 step 16). Wrap the illustration in a soft radial
       * primary halo so the empty state has a visual anchor tied to the same
       * accent family used by Button gradients, focus rings, and row hovers.
       * `aria-hidden` keeps the halo out of the a11y tree. The halo is a
       * pure background-image on a fixed-size box, so it does not shift
       * layout and honors reduced-motion (no animation).
       */}
      <div
        aria-hidden="true"
        className={cn(
          "relative grid h-40 w-40 place-items-center",
          "before:absolute before:inset-0 before:rounded-full before:content-['']",
          "before:bg-[radial-gradient(circle_at_center,color-mix(in_oklab,var(--primary)_16%,transparent)_0%,transparent_65%)]",
        )}
      >
        <div className="relative">{illustration}</div>
      </div>
      <h2
        className={cn(
          // v0.503.0 (Plan 15 step 16). Short gradient underline anchors the
          // headline to the accent axis; `pb-2` reserves room for the
          // pseudo-element so descenders never collide with it.
          "relative pb-2 text-lg font-semibold text-foreground",
          "after:absolute after:left-1/2 after:bottom-0 after:h-[2px] after:w-10 after:-translate-x-1/2",
          "after:rounded-full after:content-['']",
          "after:bg-[linear-gradient(90deg,var(--primary),var(--accent))]",
        )}
      >
        {props.headline}
      </h2>
      {props.body ? <p className="text-sm text-muted-foreground">{props.body}</p> : null}
      {props.primary || props.secondary ? (
        <div className="mt-2 flex flex-wrap justify-center gap-2">
          {props.primary}
          {props.secondary}
        </div>
      ) : null}
    </div>
  );
}
