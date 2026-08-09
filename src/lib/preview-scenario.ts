/**
 * Preview scenario overlay (Plan 16 Step 54).
 *
 * Wraps `dispatchPreview` so the operator (or a Playwright test) can
 * exercise degraded paths without changing seeds:
 *
 *   - "offline"       -> NetworkError-shaped `LaraApiError` (no data)
 *   - "slow"          -> injects PREVIEW_SLOW_LATENCY_MS before running
 *   - "rate-limited"  -> 429 `LaraApiError` with `Retry-After` header
 *   - null            -> passthrough
 *
 * The scenario is process-local (no persistence); the admin runtime
 * page (Step 53) is the single write surface. Reads flow through
 * `getPreviewScenario()` so api-client.ts stays scenario-agnostic.
 *
 * INV-RM-06: overlay MUST emit a `LaraApiError` for offline / rate-
 * limited paths so the FE error contract exercises the same envelope
 * as live transport (spec/03-error-manage §E-01).
 */

import { ApiErrorCodeType, LaraApiError } from "./lara-api-error";
import type { PreviewScenario } from "./preview-transport";

export const PREVIEW_SLOW_LATENCY_MS = 2000;
export const PREVIEW_RATE_LIMIT_RETRY_AFTER_S = 30;
/**
 * Plan 16 Step 80: header-triggered rate-limit uses the short 3-second
 * Retry-After so Playwright / QA can observe the submit-lock countdown
 * without waiting the process-global 30 s window used by the admin
 * runtime toggle.
 */
export const PREVIEW_HEADER_RATE_LIMIT_RETRY_AFTER_S = 3;

const VALID_SCENARIOS: ReadonlySet<PreviewScenario> = new Set([
  "offline",
  "slow",
  "rate-limited",
  null,
]);

let CURRENT: PreviewScenario = null;
const LISTENERS = new Set<(next: PreviewScenario) => void>();

export function getPreviewScenario(): PreviewScenario {
  return CURRENT;
}

export function setPreviewScenario(next: PreviewScenario): void {
  if (VALID_SCENARIOS.has(next) === false) {
    throw new Error(`preview-scenario: invalid scenario "${String(next)}"`);
  }
  if (CURRENT === next) return;
  CURRENT = next;
  for (const listener of LISTENERS) listener(next);
}

export function subscribePreviewScenario(listener: (next: PreviewScenario) => void): () => void {
  LISTENERS.add(listener);

  return () => LISTENERS.delete(listener);
}

export function resetPreviewScenarioForTest(): void {
  CURRENT = null;
  LISTENERS.clear();
}

/**
 * Plan 16 Step 80. Extract a per-call scenario override from request
 * headers. The header MUST be `x-preview-scenario` (lowercase) with
 * one of the closed-set scenario values. Unknown values are logged
 * and ignored (silent-fail would violate INV-ERR-04 upstream, so we
 * surface the drift here instead of swallowing).
 */
export function parseScenarioHeader(
  headers: Record<string, string> | undefined,
): PreviewScenario | undefined {
  const isFailed = !headers;
  if (isFailed) return undefined;
  const raw = headers["x-preview-scenario"] ?? headers["X-Preview-Scenario"];
  if (raw === undefined) return undefined;
  const value = raw.trim().toLowerCase();
  if (value === "") return null;
  if (value === "offline" || value === "slow" || value === "rate-limited") return value;
  console.warn("preview-scenario: ignoring unknown x-preview-scenario header value", { value });

  return undefined;
}

/**
 * Plan 16 Step 81/82. Parse `?preview=offline|slow|rate-limited` from a URL
 * search string so shared preview links can force degraded states without
 * touching the process-global setter directly. Unknown values are logged
 * (never silently ignored) and return `undefined` so callers keep the
 * existing scenario. An explicit `?preview=` (empty) resets to `null`.
 */
export function parseScenarioFromSearch(search: string | undefined): PreviewScenario | undefined {
  const isFailed = !search;
  if (isFailed) return undefined;
  const params = new URLSearchParams(search.startsWith("?") ? search.slice(1) : search);
  if (params.has("preview") === false) return undefined;
  const value = (params.get("preview") ?? "").trim().toLowerCase();
  if (value === "") return null;
  if (value === "offline" || value === "slow" || value === "rate-limited") return value;
  console.warn("preview-scenario: ignoring unknown ?preview= search value", { value });

  return undefined;
}

function offlineError(requestId: string): never {
  throw new LaraApiError(
    "Preview scenario: offline (simulated network failure)",
    ApiErrorCodeType.ServerError,
    0,
    requestId,
  );
}

function rateLimitedError(requestId: string, retryAfterSeconds: number): never {
  const err = new LaraApiError(
    "Preview scenario: rate limited (simulated)",
    ApiErrorCodeType.RateLimited,
    429,
    requestId,
  );
  (err as { retryAfterSeconds?: number }).retryAfterSeconds = retryAfterSeconds;
  throw err;
}

async function sleep(ms: number, signal: AbortSignal): Promise<void> {
  await new Promise<void>((resolve, reject) => {
    const t = setTimeout(resolve, ms);
    signal.addEventListener(
      "abort",
      () => {
        clearTimeout(t);
        reject(new DOMException("Aborted", "AbortError"));
      },
      { once: true },
    );
  });
}

export async function applyPreviewScenario<T>(
  scenario: PreviewScenario,
  requestId: string,
  signal: AbortSignal,
  run: () => Promise<T>,
  retryAfterOverrideSeconds?: number,
): Promise<T> {
  if (scenario === "offline") offlineError(requestId);
  if (scenario === "rate-limited") {
    rateLimitedError(requestId, retryAfterOverrideSeconds ?? PREVIEW_RATE_LIMIT_RETRY_AFTER_S);
  }
  if (scenario === "slow") await sleep(PREVIEW_SLOW_LATENCY_MS, signal);

  return run();
}
