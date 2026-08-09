/**
 * Preview debug drawer (Plan 16 Step 83).
 *
 * In-app operator surface for preview/dev modes. Exposes:
 *   - current RuntimeConfig (Mode / ApiBaseUrl / PreviewSeed)
 *   - PreviewScenario switch (null / offline / slow / rate-limited)
 *   - PreviewSeed switch (default / empty / error) written to
 *     `RUNTIME_OVERRIDE_KEY` via `writeRuntimeOverride`, then reload
 *
 * Gated by `isPreview() || isDev()` at render-time; the production
 * bundle imports this module (tree-shake guard lands in Step 84), so
 * every branch keeps side effects behind runtime predicates only.
 *
 * Hotkey: Cmd/Ctrl+Shift+D toggles visibility. INV-RM-04 respected:
 * no direct fetch, no bare `Error` throws, every state transition
 * logs via `console.info` with a stable prefix so QA can grep.
 */

import { useCallback, useEffect, useState, useSyncExternalStore } from "react";

import { getRuntimeMode, isDev, isPreview, type RuntimeConfig } from "../../lib/runtime-mode";
import {
  getPreviewScenario,
  setPreviewScenario,
  subscribePreviewScenario,
} from "../../lib/preview-scenario";
import {
  getPreviewStoreMetrics,
  resetPreviewStoreMetrics,
  subscribePreviewStoreMetrics,
  type PreviewStoreDomainMetric,
} from "../../lib/preview-store-metrics";
import { applyRuntimeConfigChange } from "../../lib/apply-runtime-config";
import { resetAll as resetPreviewStore } from "../../lib/preview-store";
import type { PreviewScenario } from "../../lib/preview-transport";

async function handleResetPreviewStore(): Promise<void> {
  console.info(`[${LOG_PREFIX}] reset preview store: begin`);
  try {
    await resetPreviewStore();
    console.info(`[${LOG_PREFIX}] reset preview store: ok, reloading`);
    window.location.reload();
  } catch (err) {
    console.error(`[${LOG_PREFIX}] reset preview store failed`, err);
  }
}

const LOG_PREFIX = "preview-debug-drawer";
const SCENARIO_OPTIONS: ReadonlyArray<{ value: PreviewScenario; label: string }> = [
  { value: null, label: "Normal" },
  { value: "offline", label: "Offline" },
  { value: "slow", label: "Slow (2 s)" },
  { value: "rate-limited", label: "Rate limited (429)" },
];
const SEED_OPTIONS: ReadonlyArray<{ value: string; label: string }> = [
  { value: "default", label: "Default" },
  { value: "empty", label: "Empty" },
  { value: "error", label: "Error" },
];

function isHotkey(e: KeyboardEvent): boolean {
  return (e.metaKey || e.ctrlKey) && e.shiftKey && e.key.toLowerCase() === "d";
}

function useDrawerHotkey(setOpen: (fn: (v: boolean) => boolean) => void): void {
  useEffect(() => {
    const onKey = (e: KeyboardEvent) => {
      if (isHotkey(e) === false) return;
      e.preventDefault();
      setOpen((v) => !v);
    };
    window.addEventListener("keydown", onKey);

    return () => window.removeEventListener("keydown", onKey);
  }, [setOpen]);
}

function useScenarioState(): [PreviewScenario, (n: PreviewScenario) => void] {
  const [scenario, setState] = useState<PreviewScenario>(() => getPreviewScenario());
  useEffect(() => subscribePreviewScenario(setState), []);
  const update = useCallback((next: PreviewScenario) => {
    console.info(`[${LOG_PREFIX}] scenario change`, { From: getPreviewScenario(), To: next });
    setPreviewScenario(next);
  }, []);

  return [scenario, update];
}

async function applySeed(cfg: RuntimeConfig, nextSeed: string): Promise<void> {
  console.info(`[${LOG_PREFIX}] seed change`, { From: cfg.PreviewSeed, To: nextSeed });
  const result = await applyRuntimeConfigChange({ ...cfg, PreviewSeed: nextSeed });
  console.info(`[${LOG_PREFIX}] seed change applied`, result);
  const isFailed = !result.Applied;
  if (isFailed) {
    console.error(`[${LOG_PREFIX}] seed change failed`, result);

    return;
  }
  if (!result.FastPath && typeof window !== "undefined") {
    // Reload only when the fast-path bailed (e.g. no shared QueryClient).
    // The helper already triggered reload for non-seed-only changes.
  }
}

function DrawerHeader({ onClose }: { onClose: () => void }) {
  return (
    <div className="flex items-center justify-between border-b border-border px-4 py-3">
      <span className="font-display text-sm font-semibold">Preview debug</span>
      <button
        type="button"
        onClick={onClose}
        aria-label="Close preview debug drawer"
        className="focus-ring rounded px-2 text-xs text-muted-foreground surface-hover"
      >
        Close (Cmd+Shift+D)
      </button>
    </div>
  );
}

function ConfigRow({ label, value }: { label: string; value: string }) {
  return (
    <div className="flex justify-between gap-3 text-xs">
      <span className="text-muted-foreground">{label}</span>
      <span className="font-mono text-foreground">{value}</span>
    </div>
  );
}

function ScenarioField({
  scenario,
  onChange,
}: {
  scenario: PreviewScenario;
  onChange: (n: PreviewScenario) => void;
}) {
  return (
    <label className="flex flex-col gap-1 text-xs">
      <span className="text-muted-foreground">Scenario</span>
      <select
        value={scenario ?? ""}
        onChange={(e) => onChange((e.target.value || null) as PreviewScenario)}
        className="focus-ring rounded border border-input bg-background px-2 py-1"
        data-testid="preview-debug-scenario"
      >
        {SCENARIO_OPTIONS.map((o) => (
          <option key={o.label} value={o.value ?? ""}>
            {o.label}
          </option>
        ))}
      </select>
    </label>
  );
}

function SeedField({ seed, onChange }: { seed: string; onChange: (n: string) => void }) {
  return (
    <label className="flex flex-col gap-1 text-xs">
      <span className="text-muted-foreground">Preview seed (reloads)</span>
      <select
        value={seed}
        onChange={(e) => onChange(e.target.value)}
        className="focus-ring rounded border border-input bg-background px-2 py-1"
        data-testid="preview-debug-seed"
      >
        {SEED_OPTIONS.map((o) => (
          <option key={o.value} value={o.value}>
            {o.label}
          </option>
        ))}
      </select>
    </label>
  );
}

function useStoreMetrics(): readonly PreviewStoreDomainMetric[] {
  return useSyncExternalStore(
    subscribePreviewStoreMetrics,
    getPreviewStoreMetrics,
    getPreviewStoreMetrics,
  );
}

function MetricsRow({ m }: { m: PreviewStoreDomainMetric }) {
  const ops = m.Reads + m.Writes + m.Lists + m.Removes;

  return (
    <tr className="border-t border-border/50">
      <td className="py-1 pr-2 font-mono text-[11px]">{m.Domain}</td>
      <td className="py-1 pr-2 text-right tabular-nums">{m.RowsLoaded}</td>
      <td className="py-1 pr-2 text-right tabular-nums">{ops}</td>
      <td className="py-1 text-right tabular-nums">{m.TotalMs.toFixed(1)}</td>
    </tr>
  );
}

function MetricsPanel() {
  const metrics = useStoreMetrics();
  if (metrics.length === 0) {
    return <p className="text-[11px] text-muted-foreground">No IndexedDB reads yet.</p>;
  }
  const sorted = [...metrics].sort((a, b) => b.RowsLoaded - a.RowsLoaded);

  return (
    <div className="flex flex-col gap-2" data-testid="preview-debug-store-metrics">
      <div className="flex items-center justify-between">
        <span className="text-xs text-muted-foreground">IndexedDB cache</span>
        <button
          type="button"
          onClick={() => resetPreviewStoreMetrics()}
          className="focus-ring rounded px-2 py-0.5 text-[11px] text-muted-foreground surface-hover"
          data-testid="preview-debug-store-metrics-reset"
        >
          Reset
        </button>
      </div>
      <table className="w-full text-[11px]">
        <thead className="text-muted-foreground">
          <tr>
            <th className="pb-1 text-left font-normal">Domain</th>
            <th className="pb-1 text-right font-normal">Rows</th>
            <th className="pb-1 text-right font-normal">Ops</th>
            <th className="pb-1 text-right font-normal">ms</th>
          </tr>
        </thead>
        <tbody>
          {sorted.map((m) => (
            <MetricsRow key={m.Domain} m={m} />
          ))}
        </tbody>
      </table>
    </div>
  );
}

export function PreviewDebugDrawer() {
  const [open, setOpen] = useState(false);
  useDrawerHotkey(setOpen);
  const [scenario, setScenario] = useScenarioState();
  if (!(isPreview() || isDev())) return null;
  const cfg = getRuntimeMode();

  return (
    <>
      <button
        type="button"
        onClick={() => setOpen((v) => !v)}
        aria-label="Toggle preview debug drawer"
        className="focus-ring fixed bottom-4 right-4 z-50 rounded-full border border-border bg-background px-3 py-2 text-xs shadow-md surface-hover"
        data-testid="preview-debug-toggle"
      >
        Debug: {cfg.Mode}
      </button>
      {open ? (
        <aside
          role="dialog"
          aria-label="Preview debug drawer"
          data-testid="preview-debug-drawer"
          className="fixed bottom-16 right-4 z-50 w-80 rounded-lg border border-border bg-card shadow-xl"
        >
          <DrawerHeader onClose={() => setOpen(false)} />
          <div className="flex flex-col gap-3 p-4">
            <ConfigRow label="Mode" value={cfg.Mode} />
            <ConfigRow label="ApiBaseUrl" value={cfg.ApiBaseUrl ?? "(none)"} />
            <ConfigRow label="PreviewSeed" value={cfg.PreviewSeed} />
            <ScenarioField scenario={scenario} onChange={setScenario} />
            <SeedField
              seed={cfg.PreviewSeed}
              onChange={(next) => {
                void applySeed(cfg, next);
              }}
            />
            <div className="border-t border-border/60 pt-3">
              <MetricsPanel />
            </div>
            <button
              type="button"
              onClick={() => {
                void handleResetPreviewStore();
              }}
              className="focus-ring rounded border border-input px-3 py-1.5 text-xs text-foreground surface-hover"
              data-testid="preview-debug-reset-store"
            >
              Reset preview store &amp; re-seed
            </button>
          </div>
        </aside>
      ) : null}
    </>
  );
}
