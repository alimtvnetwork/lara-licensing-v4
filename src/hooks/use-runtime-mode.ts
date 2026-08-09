/**
 * useRuntimeMode (Plan 16 Step 14).
 *
 * React binding for `src/lib/runtime-mode.ts`. Enforces spec 28 rules:
 *
 *   F-01: resolve exactly once on the client during hydration.
 *   F-02: after freeze, `useRuntimeMode()` MUST NOT re-run precedence.
 *   F-04: SSR HTML renders as if `Mode === "preview"` (the compile-time
 *         default), so hydration diff on `<RuntimeBanner>` is zero.
 *
 * The hook does NOT throw on load failure. `logRuntimeError` inside the
 * resolver surfaces the failure to `console.error` (INV-RM-11). The banner
 * (Step 51) reads `isRuntimeModeFrozen()` + last error id to render a
 * "fallback" chip; that path is intentionally out of scope for Step 14.
 *
 * Every function body kept under the 15-line cap.
 */

import { useEffect, useState } from "react";

import {
  type RuntimeConfig,
  getCompileTimeDefault,
  isRuntimeModeFrozen,
} from "../lib/runtime-mode";
import { bootRuntimeConfig } from "../lib/version-json-loader";
import { useHydrated } from "./use-hydrated";

function ensureResolveOnce(): Promise<RuntimeConfig> {
  return bootRuntimeConfig();
}

export interface UseRuntimeModeResult {
  Config: RuntimeConfig;
  IsHydrated: boolean;
  IsFrozen: boolean;
}

export function useRuntimeMode(): UseRuntimeModeResult {
  const hydrated = useHydrated();
  const [config, setConfig] = useState<RuntimeConfig>(() => getCompileTimeDefault());

  useEffect(() => {
    const isFailed = !hydrated;
    if (isFailed) return;
    let cancelled = false;
    ensureResolveOnce().then((resolved) => {
      const isFailed = !cancelled;
      if (isFailed) setConfig(resolved);
    });

    return () => {
      cancelled = true;
    };
  }, [hydrated]);

  return { Config: config, IsHydrated: hydrated, IsFrozen: isRuntimeModeFrozen() };
}

// Convenience wrappers for consumers that only need one field. They still
// gate on hydration to preserve F-04 (SSR always sees compile-time default).
export function useRuntimeModeValue(): RuntimeConfig["Mode"] {
  return useRuntimeMode().Config.Mode;
}

export function useApiBaseUrl(): string | null {
  return useRuntimeMode().Config.ApiBaseUrl;
}

export function usePreviewSeed(): string {
  return useRuntimeMode().Config.PreviewSeed;
}
