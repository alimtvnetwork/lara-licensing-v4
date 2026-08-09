/**
 * Preview seed dispatcher (Plan 16 Step 39).
 *
 * Bridges `version.json.PreviewSeed` (resolved through `getRuntimeMode()`)
 * to the matching `loadXxxSeed()` in `src/lib/preview-seeds/*`. This is
 * the single choke point that populates IndexedDB at boot; every handler
 * under `src/lib/preview-fixtures/*` (Steps 40-50) relies on it.
 *
 * Invariants:
 * - No-op in `dev`/`production` modes (INV-RM-04).
 * - Unknown seed ids log via `logRuntimeError` and fall back to `default`
 *   so screens never render blank in preview (no silent failure, matches
 *   spec/03-error-manage). The fallback is logged, not swallowed.
 * - Idempotent across reloads via each loader's `hydrateOnce()` marker.
 */

import { getRuntimeMode, logRuntimeError } from "./runtime-mode";
import type { PreviewSeedId } from "./preview-seeds/_contract";
import { loadDefaultSeed } from "./preview-seeds/default";
import { loadEmptySeed } from "./preview-seeds/empty";
import { loadErrorSeed } from "./preview-seeds/error";

export interface SeedDispatchResult {
  Dispatched: boolean;
  SeedId: PreviewSeedId | null;
  Hydrated: boolean;
  UsedFallback: boolean;
}

const LOADERS: Record<PreviewSeedId, () => Promise<{ Hydrated: boolean }>> = {
  default: loadDefaultSeed,
  empty: loadEmptySeed,
  error: loadErrorSeed,
};

function isKnownSeed(id: string): id is PreviewSeedId {
  return id === "default" || id === "empty" || id === "error";
}

function resolveSeedId(raw: string): { id: PreviewSeedId; usedFallback: boolean } {
  if (isKnownSeed(raw)) return { id: raw, usedFallback: false };
  logRuntimeError("UNKNOWN_PREVIEW_SEED", { Requested: raw });

  return { id: "default", usedFallback: true };
}

export async function dispatchPreviewSeed(): Promise<SeedDispatchResult> {
  const cfg = getRuntimeMode();
  if (cfg.Mode !== "preview") {
    return { Dispatched: false, SeedId: null, Hydrated: false, UsedFallback: false };
  }
  const { id, usedFallback } = resolveSeedId(cfg.PreviewSeed);
  try {
    const { Hydrated } = await LOADERS[id]();

    return { Dispatched: true, SeedId: id, Hydrated, UsedFallback: usedFallback };
  } catch (err) {
    logRuntimeError("PREVIEW_SEED_LOAD_FAILED", { SeedId: id, Error: err });
    throw err;
  }
}
