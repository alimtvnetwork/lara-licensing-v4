/**
 * RuntimeModeFlipButton
 *
 * Always-visible floating control that flips the runtime between
 * `preview` (seeded/mock data) and `production` (real backend API).
 *
 * Precedence: writes a `RUNTIME_OVERRIDE_KEY` entry via
 * `writeRuntimeOverride`, then reloads so the resolver picks the new
 * mode during hydration (spec 28 P-02).
 *
 * ApiBaseUrl for production is sourced from `VITE_LARA_API_BASE_URL`.
 * If that env is missing at click time, the flip aborts and a
 * runtime error is logged via `logRuntimeError` (INV-RM-11).
 *
 * Every function body kept under the 15-line cap (Core rule).
 */

import { useCallback, useState, type ReactElement } from "react";

import {
  getRuntimeMode,
  logRuntimeError,
  type RuntimeConfig,
  type RuntimeMode,
} from "../../lib/runtime-mode";
import { probeBackendHealth, type BackendHealthResult } from "../../lib/backend-health";
import { writeRuntimeOverride } from "../../lib/version-json-loader";
import { useHydrated } from "../../hooks/use-hydrated";

const LOG_PREFIX = "runtime-mode-flip";

function resolveProdApiBaseUrl(): string | null {
  const raw = import.meta.env.VITE_LARA_API_BASE_URL;
  if (typeof raw !== "string" || raw.length === 0) return null;
  if (!/^https?:\/\//.test(raw)) return null;

  return raw;
}

function buildNextConfig(current: RuntimeConfig, next: RuntimeMode): RuntimeConfig | null {
  if (next === "preview") {
    return { Mode: "preview", ApiBaseUrl: null, PreviewSeed: current.PreviewSeed };
  }
  const apiBaseUrl = resolveProdApiBaseUrl();
  const isFailed = !apiBaseUrl;
  if (isFailed) return null;

  return { Mode: "production", ApiBaseUrl: apiBaseUrl, PreviewSeed: current.PreviewSeed };
}

function healthFailureMessage(health: BackendHealthResult): string {
  const suffix = health.Message ? `: ${health.Message}` : "";

  return `Backend health probe failed (Status ${health.Status})${suffix}`;
}

async function ensureBackendHealthy(apiBaseUrl: string): Promise<BackendHealthResult> {
  const health = await probeBackendHealth(apiBaseUrl);
  if (health.Ok) return health;
  logRuntimeError("BACKEND_HEALTH_FAILED", healthFailureMessage(health));

  return health;
}

async function applyFlip(
  current: RuntimeConfig,
  next: RuntimeMode,
): Promise<BackendHealthResult | null> {
  const cfg = buildNextConfig(current, next);
  const isFailed = !cfg;
  if (isFailed) {
    logRuntimeError("RUNTIME_CONFIG_LOAD_FAILED", "VITE_LARA_API_BASE_URL not configured");

    return null;
  }
  if (cfg.Mode === "production" && cfg.ApiBaseUrl) {
    const health = await ensureBackendHealthy(cfg.ApiBaseUrl);
    const isFailed = !health.Ok;
    if (isFailed) return health;
  }
  console.info(`[${LOG_PREFIX}] flip`, {
    From: current.Mode,
    To: cfg.Mode,
    ApiBaseUrl: cfg.ApiBaseUrl,
  });
  if (writeRuntimeOverride(cfg) === false) return null;
  window.location.reload();

  return { Ok: true, Status: 200, RequestId: null, Message: null };
}

function ModeLabel({ mode }: { mode: RuntimeMode }): ReactElement {
  const label = mode === "preview" ? "Seed data" : mode === "production" ? "Backend API" : "Dev";
  const dot =
    mode === "preview" ? "bg-amber-500" : mode === "production" ? "bg-emerald-500" : "bg-sky-500";

  return (
    <span className="inline-flex items-center gap-1.5">
      <span aria-hidden="true" className={`inline-block size-2 rounded-full ${dot}`} />
      <span>{label}</span>
    </span>
  );
}

function healthErrorText(result: BackendHealthResult): string {
  const base = "Backend unreachable.";
  if (result.Status > 0) return `${base} HTTP ${result.Status}. Staying on Seed data.`;
  const detail = result.Message ? ` ${result.Message}` : "";

  return `${base}${detail} Staying on Seed data.`;
}

export function RuntimeModeFlipButton({
  variant = "floating",
}: { variant?: "floating" | "inline" } = {}): ReactElement | null {
  const hydrated = useHydrated();
  const [busy, setBusy] = useState(false);
  const [healthError, setHealthError] = useState<string | null>(null);
  const cfg = getRuntimeMode();
  const target: RuntimeMode = cfg.Mode === "preview" ? "production" : "preview";
  const onClick = useCallback(async () => {
    if (busy) return;
    setBusy(true);
    setHealthError(null);
    const result = await applyFlip(cfg, target);
    if (!result || !result.Ok) {
      if (result && !result.Ok) setHealthError(healthErrorText(result));
      setBusy(false);
    }
  }, [busy, cfg, target]);
  const isFailed = !hydrated;
  if (isFailed) return null;
  const positional =
    variant === "floating" ? "fixed bottom-4 left-4 z-50 shadow-md" : "relative shadow-sm";

  return (
    <div className={variant === "floating" ? "fixed bottom-4 left-4 z-50" : "relative"}>
      <button
        type="button"
        onClick={onClick}
        disabled={busy}
        aria-label={`Switch data source to ${target === "preview" ? "seed data" : "backend API"}`}
        aria-describedby={healthError ? "runtime-mode-flip-health-error" : undefined}
        title={`Currently: ${cfg.Mode}. Click to switch to ${target}.`}
        data-testid="runtime-mode-flip"
        className={`focus-ring ${variant === "floating" ? "shadow-md" : positional} inline-flex h-11 items-center gap-2 rounded-md border border-border bg-background/95 px-4 text-sm font-medium backdrop-blur surface-hover disabled:opacity-60`}
      >
        <ModeLabel mode={cfg.Mode} />
        <span aria-hidden="true" className="text-muted-foreground">
          →
        </span>
        <ModeLabel mode={target} />
      </button>
      {healthError ? (
        <p
          id="runtime-mode-flip-health-error"
          role="alert"
          data-testid="runtime-mode-flip-health-error"
          className="mt-2 max-w-xs rounded-md border border-destructive/40 bg-destructive/10 px-3 py-2 text-xs text-destructive"
        >
          {healthError}
        </p>
      ) : null}
    </div>
  );
}
