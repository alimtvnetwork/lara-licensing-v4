/**
 * RuntimeModeSwitch
 *
 * Visible selector between seed data and a user-configured backend.
 * Backend mode is only activated after a valid URL is saved.
 */

import { useCallback, useState, type ReactElement } from "react";

import { getRuntimeMode, logRuntimeInfo, type RuntimeMode } from "../../lib/runtime-mode";
import { applyRuntimeConfigChange } from "../../lib/apply-runtime-config";
import { probeBackendHealth } from "../../lib/backend-health";
import { readLastGoodBackendUrl, writeLastGoodBackendUrl } from "../../lib/last-good-backend-url";
import { useHydrated } from "../../hooks/use-hydrated";

function initialBackendUrl(configuredUrl: string | null): string {
  if (configuredUrl) return configuredUrl;
  const lastGood = readLastGoodBackendUrl();
  if (lastGood) return lastGood;
  const fallback = import.meta.env.VITE_LARA_API_BASE_URL;

  return typeof fallback === "string" ? fallback : "";
}

function normalizeBackendUrl(raw: string): string | null {
  try {
    const url = new URL(raw.trim());
    if (url.protocol !== "http:" && url.protocol !== "https:") return null;

    return url.toString().replace(/\/$/, "");
  } catch {
    return null;
  }
}

async function saveMode(
  mode: "preview" | "production",
  url: string | null,
  seed: string,
): Promise<string | null> {
  const result = await applyRuntimeConfigChange({ Mode: mode, ApiBaseUrl: url, PreviewSeed: seed });
  if (!result.Applied && result.Reason === "write-failed") {
    return "Unable to save the data source in this browser.";
  }

  return null;
}

function currentModeLabel(mode: string, seed: string): string {
  if (mode === "production") return "Currently: Backend API";
  if (mode === "dev") return "Currently: Dev (live backend)";

  return `Currently: Seed data (${seed})`;
}

function unhealthyMessage(status: number, message: string | null): string {
  if (status === 0) return `Backend unreachable${message ? `: ${message}` : "."}`;

  return `Backend health check failed (HTTP ${status})${message ? `: ${message}` : "."}`;
}

export function RuntimeModeSwitch(): ReactElement | null {
  const hydrated = useHydrated();
  const [error, setError] = useState<string | null>(null);
  const [probing, setProbing] = useState(false);
  const cfg = getRuntimeMode();
  const [showBackendForm, setShowBackendForm] = useState(cfg.Mode === "production");
  const [backendUrl, setBackendUrl] = useState(() => initialBackendUrl(cfg.ApiBaseUrl));
  const isSeed = cfg.Mode !== "production";
  const normalizedUrl = normalizeBackendUrl(backendUrl);
  const canSubmitBackend = normalizedUrl !== null && !probing;
  const useSeedData = useCallback(() => {
    setError(null);
    setShowBackendForm(false);
    const from: RuntimeMode = cfg.Mode;
    logRuntimeInfo("RUNTIME_MODE_SWITCH_REQUESTED", {
      FromMode: from,
      ToMode: "preview",
      FromSeed: cfg.PreviewSeed,
      ToSeed: cfg.PreviewSeed,
      HasUrl: false,
    });
    void saveMode("preview", null, cfg.PreviewSeed).then((err) => {
      if (err) {
        setError(err);
        logRuntimeInfo("RUNTIME_MODE_SWITCH_ABORTED", {
          FromMode: from,
          ToMode: "preview",
          FromSeed: cfg.PreviewSeed,
          ToSeed: cfg.PreviewSeed,
          HasUrl: false,
          Reason: err,
        });

        return;
      }
      logRuntimeInfo("RUNTIME_MODE_SWITCH_COMMITTED", {
        FromMode: from,
        ToMode: "preview",
        FromSeed: cfg.PreviewSeed,
        ToSeed: cfg.PreviewSeed,
        HasUrl: false,
      });
    });
  }, [cfg.Mode, cfg.PreviewSeed]);
  const configureBackend = useCallback(() => {
    setError(null);
    setShowBackendForm(true);
  }, []);
  const commitBackend = useCallback(
    async (url: string): Promise<void> => {
      const from: RuntimeMode = cfg.Mode;
      logRuntimeInfo("RUNTIME_MODE_SWITCH_REQUESTED", {
        FromMode: from,
        ToMode: "production",
        FromSeed: cfg.PreviewSeed,
        ToSeed: cfg.PreviewSeed,
        HasUrl: true,
      });
      setProbing(true);
      const health = await probeBackendHealth(url);
      const isFailed = !health.Ok;
      if (isFailed) {
        const msg = unhealthyMessage(health.Status, health.Message);
        setError(msg);
        setProbing(false);
        logRuntimeInfo("RUNTIME_MODE_SWITCH_ABORTED", {
          FromMode: from,
          ToMode: "production",
          FromSeed: cfg.PreviewSeed,
          ToSeed: cfg.PreviewSeed,
          HasUrl: true,
          Reason: msg,
        });

        return;
      }
      writeLastGoodBackendUrl(url);
      const err = await saveMode("production", url, cfg.PreviewSeed);
      setError(err);
      setProbing(false);
      if (err) {
        logRuntimeInfo("RUNTIME_MODE_SWITCH_ABORTED", {
          FromMode: from,
          ToMode: "production",
          FromSeed: cfg.PreviewSeed,
          ToSeed: cfg.PreviewSeed,
          HasUrl: true,
          Reason: err,
        });

        return;
      }
      logRuntimeInfo("RUNTIME_MODE_SWITCH_COMMITTED", {
        FromMode: from,
        ToMode: "production",
        FromSeed: cfg.PreviewSeed,
        ToSeed: cfg.PreviewSeed,
        HasUrl: true,
      });
    },
    [cfg.Mode, cfg.PreviewSeed],
  );
  const useBackend = useCallback(() => {
    const isFailed = !normalizedUrl;
    if (isFailed) {
      setError("Enter a valid backend URL starting with http:// or https://.");

      return;
    }
    setError(null);
    void commitBackend(normalizedUrl);
  }, [normalizedUrl, commitBackend]);
  const isFailed = !hydrated;
  if (isFailed) return null;
  const base =
    "focus-ring inline-flex h-9 items-center rounded px-4 text-sm font-semibold transition-colors";
  const active = "bg-primary text-primary-foreground shadow-sm";
  const idle = "text-muted-foreground hover:text-foreground";

  return (
    <div className="flex flex-col gap-2">
      <div
        role="tablist"
        aria-label="Data source"
        data-testid="runtime-mode-switch"
        className="inline-flex h-11 items-center gap-1 rounded-md border border-input bg-background p-1"
      >
        <span className="px-2 text-xs font-semibold uppercase text-muted-foreground">
          Data source
        </span>
        <button
          type="button"
          role="tab"
          aria-selected={isSeed}
          onClick={useSeedData}
          className={`${base} ${isSeed ? active : idle}`}
        >
          Seed data
        </button>
        <button
          type="button"
          role="tab"
          aria-selected={!isSeed}
          onClick={configureBackend}
          className={`${base} ${!isSeed ? active : idle}`}
        >
          Backend API
        </button>
      </div>
      <p data-testid="runtime-mode-current" className="text-xs font-medium text-muted-foreground">
        {currentModeLabel(cfg.Mode, cfg.PreviewSeed)}
      </p>
      {showBackendForm ? (
        <div className="flex flex-col gap-2 sm:flex-row sm:items-end">
          <label className="flex min-w-0 flex-1 flex-col gap-1 text-xs font-semibold text-foreground">
            Backend URL
            <input
              type="url"
              inputMode="url"
              autoComplete="url"
              placeholder="https://api.example.com"
              value={backendUrl}
              onChange={(event) => setBackendUrl(event.target.value)}
              onKeyDown={(event) => {
                if (event.key === "Enter" && canSubmitBackend) useBackend();
              }}
              aria-invalid={Boolean(error)}
              aria-describedby={error ? "runtime-backend-error" : undefined}
              className="focus-ring h-10 min-w-0 rounded-md border border-input bg-background px-3 text-sm font-normal text-foreground"
            />
          </label>
          <button
            type="button"
            onClick={useBackend}
            disabled={!canSubmitBackend}
            aria-disabled={!canSubmitBackend}
            data-testid="runtime-mode-backend-submit"
            className="focus-ring h-10 rounded-md bg-primary px-4 text-sm font-semibold text-primary-foreground shadow-sm disabled:cursor-not-allowed disabled:opacity-50"
          >
            {probing ? "Checking…" : "Use backend"}
          </button>
        </div>
      ) : null}
      {error ? (
        <p id="runtime-backend-error" className="text-xs text-destructive" role="alert">
          {error}
        </p>
      ) : null}
    </div>
  );
}
