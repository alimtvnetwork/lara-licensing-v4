/**
 * applyRuntimeConfigChange (Plan 17 Step 34).
 *
 * Fast-path helper used by `PreviewDebugDrawer` and `RuntimeModeSwitch`.
 *
 * If only `PreviewSeed` changed (both configs stay in Mode="preview",
 * ApiBaseUrl unchanged), we skip the full-page reload: overwrite the
 * frozen runtime config, wipe IndexedDB, re-dispatch the seed, and
 * invalidate every query in the shared `QueryClient` so loaders re-fire.
 * That preserves scroll and open drawers, and is 5-10x faster than
 * a hard reload.
 *
 * Any other change (Mode flip, ApiBaseUrl swap) still reloads because
 * transport wiring, health checks and router boot only run at boot.
 *
 * Never swallows errors: failures propagate; storage-write failures
 * are logged via `logRuntimeError` inside `writeRuntimeOverride`.
 */

import type { QueryClient } from "@tanstack/react-query";

import {
  freezeRuntimeMode,
  getRuntimeMode,
  logRuntimeError,
  type RuntimeConfig,
} from "./runtime-mode";
import { writeRuntimeOverride } from "./version-json-loader";
import { resetAll as resetPreviewStore } from "./preview-store";
import { dispatchPreviewSeed } from "./preview-seed-dispatcher";

const LOG = "apply-runtime-config";

export interface ApplyResult {
  Applied: boolean;
  FastPath: boolean;
  Reason: "seed-only" | "mode-change" | "url-change" | "no-op" | "write-failed";
}

function isSeedOnlyChange(current: RuntimeConfig, next: RuntimeConfig): boolean {
  return (
    current.Mode === "preview" &&
    next.Mode === "preview" &&
    current.ApiBaseUrl === next.ApiBaseUrl &&
    current.PreviewSeed !== next.PreviewSeed
  );
}

function classify(current: RuntimeConfig, next: RuntimeConfig): ApplyResult["Reason"] {
  if (current.Mode !== next.Mode) return "mode-change";
  if (current.ApiBaseUrl !== next.ApiBaseUrl) return "url-change";
  if (current.PreviewSeed !== next.PreviewSeed) return "seed-only";

  return "no-op";
}

function getSharedQueryClient(): QueryClient | null {
  if (typeof window === "undefined") return null;
  const w = window as unknown as { __LARA_QUERY_CLIENT__?: QueryClient };

  return w.__LARA_QUERY_CLIENT__ ?? null;
}

async function runSeedFastPath(next: RuntimeConfig): Promise<void> {
  console.info(`[${LOG}] seed-only fast-path`, { To: next.PreviewSeed });
  freezeRuntimeMode(next);
  await resetPreviewStore();
  await dispatchPreviewSeed();
  const qc = getSharedQueryClient();
  if (qc) await qc.invalidateQueries();
  else console.warn(`[${LOG}] no shared QueryClient; skipped invalidateQueries`);
}

async function trySeedFastPath(next: RuntimeConfig): Promise<ApplyResult> {
  try {
    await runSeedFastPath(next);

    return { Applied: true, FastPath: true, Reason: "seed-only" };
  } catch (err) {
    logRuntimeError("PREVIEW_SEED_LOAD_FAILED", err);
    if (typeof window !== "undefined") window.location.reload();

    return { Applied: true, FastPath: false, Reason: "seed-only" };
  }
}

export async function applyRuntimeConfigChange(next: RuntimeConfig): Promise<ApplyResult> {
  const current = getRuntimeMode();
  const reason = classify(current, next);
  if (reason === "no-op") return { Applied: false, FastPath: false, Reason: "no-op" };
  if (writeRuntimeOverride(next) === false) {
    return { Applied: false, FastPath: false, Reason: "write-failed" };
  }
  if (isSeedOnlyChange(current, next)) return trySeedFastPath(next);
  if (typeof window !== "undefined") window.location.reload();

  return { Applied: true, FastPath: false, Reason: reason };
}
