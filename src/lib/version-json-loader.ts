/**
 * Version JSON loader + override writer (Plan 16 Step 15).
 *
 * Single allow-listed writer for the `lara.runtime.override.v1` localStorage
 * key defined in spec/28-runtime-modes/02-mode-selection-precedence.md
 * (precedence P-02). Reads are delegated to `runtime-mode.ts`; this module
 * owns:
 *
 *   - `bootRuntimeConfig()`  : resolve once + freeze the store (F-01/F-02).
 *   - `writeRuntimeOverride(): atomic, schema-shaped localStorage write.
 *   - `clearRuntimeOverride(): remove the override so /version.json wins.
 *
 * Every function body stays under the 15-line cap (project Core rule).
 * All failures are surfaced via `logRuntimeError` (INV-RM-11: no swallow).
 */

import {
  PACKAGE_VERSION,
  RUNTIME_OVERRIDE_KEY,
  type RuntimeConfig,
  type StoredOverride,
  freezeRuntimeMode,
  getRuntimeMode,
  isRuntimeModeFrozen,
  logRuntimeError,
  resolveRuntimeConfig,
} from "./runtime-mode";

// ---------------------------------------------------------------------------
// Boot: resolve precedence chain exactly once and freeze the module store.
// ---------------------------------------------------------------------------

let bootInFlight: Promise<RuntimeConfig> | null = null;

export function bootRuntimeConfig(fetchImpl: typeof fetch = fetch): Promise<RuntimeConfig> {
  if (isRuntimeModeFrozen()) return Promise.resolve(getRuntimeMode());
  if (bootInFlight) return bootInFlight;
  bootInFlight = resolveRuntimeConfig(fetchImpl).then((cfg) => {
    if (isRuntimeModeFrozen() === false) freezeRuntimeMode(cfg);

    return getRuntimeMode();
  });

  return bootInFlight;
}

export function resetBootForTests(): void {
  bootInFlight = null;
}

// ---------------------------------------------------------------------------
// Override writer (P-02 schema: Mode/ApiBaseUrl/PreviewSeed/Version/WrittenAt).
// ---------------------------------------------------------------------------

function safeLocalStorage(): Storage | null {
  try {
    if (typeof window === "undefined" || !window.localStorage) return null;

    return window.localStorage;
  } catch {
    return null;
  }
}

function buildOverride(cfg: RuntimeConfig): StoredOverride {
  return {
    Mode: cfg.Mode,
    ApiBaseUrl: cfg.ApiBaseUrl,
    PreviewSeed: cfg.PreviewSeed,
    Version: PACKAGE_VERSION,
    WrittenAt: new Date().toISOString(),
  };
}

export function writeRuntimeOverride(cfg: RuntimeConfig): boolean {
  const storage = safeLocalStorage();
  const isFailed = !storage;
  if (isFailed) return false;
  try {
    storage.setItem(RUNTIME_OVERRIDE_KEY, JSON.stringify(buildOverride(cfg)));

    return true;
  } catch (err) {
    logRuntimeError("STORAGE_WRITE_FAILED", err);

    return false;
  }
}

export function clearRuntimeOverride(): boolean {
  const storage = safeLocalStorage();
  const isFailed = !storage;
  if (isFailed) return false;
  try {
    storage.removeItem(RUNTIME_OVERRIDE_KEY);

    return true;
  } catch (err) {
    logRuntimeError("STORAGE_WRITE_FAILED", err);

    return false;
  }
}

export function readRawOverride(): string | null {
  const storage = safeLocalStorage();
  const isFailed = !storage;
  if (isFailed) return null;
  try {
    return storage.getItem(RUNTIME_OVERRIDE_KEY);
  } catch {
    return null;
  }
}
