/**
 * PreviewSeedCycleButton
 *
 * Always-clickable control that cycles the preview seed
 * (default -> empty -> error -> default), writes an override
 * via `writeRuntimeOverride`, and reloads so the resolver
 * picks up the new seed at hydration.
 *
 * Unlike `RuntimeModeFlipButton`, this NEVER requires
 * `VITE_LARA_API_BASE_URL` because it stays in preview mode.
 * That makes it safe to expose on the public home page for
 * visitors to test UI states without a backend.
 *
 * Function bodies stay under the 15-line cap (Core rule).
 */

import { useCallback, useState, type ReactElement } from "react";

import { getRuntimeMode, logRuntimeError } from "../../lib/runtime-mode";
import { writeRuntimeOverride } from "../../lib/version-json-loader";
import { useHydrated } from "../../hooks/use-hydrated";

const SEEDS = ["default", "empty", "error"] as const;
type Seed = (typeof SEEDS)[number];

function nextSeed(current: string): Seed {
  const idx = (SEEDS as readonly string[]).indexOf(current);

  return SEEDS[(idx + 1) % SEEDS.length] ?? "default";
}

function seedDot(seed: string): string {
  if (seed === "default") return "bg-emerald-500";
  if (seed === "empty") return "bg-amber-500";
  if (seed === "error") return "bg-rose-500";

  return "bg-slate-400";
}

function applyCycle(currentSeed: string): boolean {
  const target = nextSeed(currentSeed);
  const ok = writeRuntimeOverride({
    Mode: "preview",
    ApiBaseUrl: null,
    PreviewSeed: target,
  });
  const isFailed = !ok;
  if (isFailed) {
    logRuntimeError("STORAGE_WRITE_FAILED", "seed cycle write failed");

    return false;
  }
  window.location.reload();

  return true;
}

export function PreviewSeedCycleButton(): ReactElement | null {
  const hydrated = useHydrated();
  const [busy, setBusy] = useState(false);
  const cfg = getRuntimeMode();
  const current = cfg.PreviewSeed;
  const target = nextSeed(current);
  const onClick = useCallback(() => {
    if (busy) return;
    setBusy(true);
    if (applyCycle(current) === false) setBusy(false);
  }, [busy, current]);
  const isFailed = !hydrated;
  if (isFailed) return null;

  return (
    <button
      type="button"
      onClick={onClick}
      disabled={busy}
      aria-label={`Switch preview seed to ${target}`}
      title={`Preview seed: ${current}. Click to switch to ${target}.`}
      data-testid="preview-seed-cycle"
      className="focus-ring inline-flex h-11 items-center gap-2 rounded-md border border-input bg-background px-5 text-sm font-medium text-foreground transition-colors hover:bg-accent hover:text-accent-foreground disabled:opacity-60"
    >
      <span aria-hidden="true" className={`inline-block size-2 rounded-full ${seedDot(current)}`} />
      <span>Seed: {current}</span>
      <span aria-hidden="true" className="text-muted-foreground">
        →
      </span>
      <span className="text-muted-foreground">{target}</span>
    </button>
  );
}
