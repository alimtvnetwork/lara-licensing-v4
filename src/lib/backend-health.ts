/**
 * Backend health probe (v0.673.0).
 *
 * Root cause: flipping runtime from `preview` to `production` with an
 * unreachable backend leaves the UI stuck on the very next render, since
 * every route loader immediately fires `LaraApiError(ServiceUnavailable)`
 * and there is no path back except editing localStorage by hand. Probing
 * `GET {ApiBaseUrl}/Api/Public/Health` (see `HealthController`) BEFORE
 * writing the override closes that trap: an unhealthy backend blocks the
 * flip and the failure is logged via `logRuntimeError` (INV-RM-11).
 *
 * The probe is intentionally shallow: single unauthenticated GET, 5s
 * abort, no retries (retrying an unreachable backend delays the user for
 * no signal). A 200 envelope with `Success=true` is the only "ok".
 *
 * Every function body kept under the 15-line cap (Core rule).
 */

import { logRuntimeError } from "./runtime-mode";

export const HEALTH_PATH = "/Api/Public/Health";
export const HEALTH_TIMEOUT_MS = 5_000;

export interface BackendHealthResult {
  Ok: boolean;
  Status: number;
  RequestId: string | null;
  Message: string | null;
}

function joinHealthUrl(apiBaseUrl: string): string {
  const trimmed = apiBaseUrl.replace(/\/+$/, "");

  return `${trimmed}${HEALTH_PATH}`;
}

async function readEnvelopeMessage(res: Response): Promise<string | null> {
  try {
    const body = (await res.json()) as {
      Message?: unknown;
      Errors?: Array<{ ErrorMessage?: unknown }>;
    };
    if (typeof body?.Message === "string" && body.Message.length > 0) return body.Message;
    const first = body?.Errors?.[0]?.ErrorMessage;

    return typeof first === "string" && first.length > 0 ? first : null;
  } catch {
    return null;
  }
}

function requestIdFrom(res: Response): string | null {
  const raw = res.headers.get("X-Request-Id");

  return typeof raw === "string" && raw.length > 0 ? raw : null;
}

async function runProbe(url: string, signal: AbortSignal): Promise<BackendHealthResult> {
  const res = await fetch(url, {
    method: "GET",
    signal,
    headers: { Accept: "application/json" },
    credentials: "omit",
    cache: "no-store",
  });
  const message = res.ok ? null : await readEnvelopeMessage(res);

  return { Ok: res.ok, Status: res.status, RequestId: requestIdFrom(res), Message: message };
}

/**
 * Ping the backend's public health endpoint. Never throws: transport
 * failures (network, CORS, abort) are captured and returned as
 * `{ Ok: false, Status: 0, Message: <reason> }`. Non-2xx responses are
 * returned as-is with the envelope `Message` when available.
 */
export async function probeBackendHealth(
  apiBaseUrl: string,
  timeoutMs: number = HEALTH_TIMEOUT_MS,
): Promise<BackendHealthResult> {
  const url = joinHealthUrl(apiBaseUrl);
  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), timeoutMs);
  try {
    const result = await runProbe(url, controller.signal);
    console.info("[backend-health] probe", { Url: url, ...result });

    return result;
  } catch (cause) {
    const message = cause instanceof Error ? cause.message : String(cause);
    logRuntimeError("BACKEND_HEALTH_FAILED", cause);

    return { Ok: false, Status: 0, RequestId: null, Message: message };
  } finally {
    clearTimeout(timer);
  }
}
