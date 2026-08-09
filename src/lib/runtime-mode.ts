/**
 * Runtime mode resolver core (Plan 16 Step 13).
 *
 * Implements the precedence chain defined in
 * spec/28-runtime-modes/02-mode-selection-precedence.md:
 *
 *   localStorage override (same Version) > /version.json > compile-time default
 *
 * Every function body is kept under the 15-line cap (project Core rule).
 * The SSR/hook wrapper (Step 14) and the dedicated version-json-loader
 * (Step 15) build on top of this module. This is the ONLY module that
 * reads `?mode=`, localStorage override, or /version.json directly.
 */

import packageJson from "../../package.json" with { type: "json" };

// ---------------------------------------------------------------------------
// Types & constants
// ---------------------------------------------------------------------------

export type RuntimeMode = "preview" | "dev" | "production";

export interface RuntimeConfig {
  Mode: RuntimeMode;
  ApiBaseUrl: string | null;
  PreviewSeed: string;
}

export interface VersionJson extends RuntimeConfig {
  AllowRuntimeToggle: boolean;
  UpdatedAt: string;
  Version: string;
}

export interface StoredOverride extends RuntimeConfig {
  Version: string;
  WrittenAt: string;
}

export const RUNTIME_OVERRIDE_KEY = "lara.runtime.override.v1";
export const VERSION_JSON_PATH = "/version.json";
export const PACKAGE_VERSION: string = (packageJson as { version: string }).version;

const COMPILE_TIME_DEFAULT: RuntimeConfig = Object.freeze({
  Mode: "preview" as const,
  ApiBaseUrl: null,
  PreviewSeed: "default",
});

const VALID_MODES: readonly RuntimeMode[] = ["preview", "dev", "production"];

// ---------------------------------------------------------------------------
// Boot-scoped correlation ids for LaraException-shaped logs
// ---------------------------------------------------------------------------

function randomId(prefix: string): string {
  const rand = Math.random().toString(36).slice(2, 10);
  const time = Date.now().toString(36);

  return `${prefix}_${time}_${rand}`;
}

const BOOT_REQUEST_ID = randomId("boot");

export function getBootRequestId(): string {
  return BOOT_REQUEST_ID;
}

export type RuntimeErrorCode =
  | "RUNTIME_CONFIG_LOAD_FAILED"
  | "RUNTIME_OVERRIDE_INVALID"
  | "STORAGE_WRITE_FAILED"
  | "UNKNOWN_PREVIEW_SEED"
  | "PREVIEW_SEED_LOAD_FAILED"
  | "BACKEND_HEALTH_FAILED";

export interface RuntimeErrorPayload {
  Code: RuntimeErrorCode;
  Message: string;
  RequestId: string;
  ErrorId: string;
  Cause?: unknown;
}

export function logRuntimeError(code: RuntimeErrorCode, cause: unknown): RuntimeErrorPayload {
  const payload: RuntimeErrorPayload = {
    Code: code,
    Message: cause instanceof Error ? cause.message : String(cause),
    RequestId: BOOT_REQUEST_ID,
    ErrorId: randomId("err"),
    Cause: cause,
  };

  pushLaraApiError(new Error());

  return payload;
}

export type RuntimeInfoCode =
  | "RUNTIME_MODE_SWITCH_REQUESTED"
  | "RUNTIME_MODE_SWITCH_COMMITTED"
  | "RUNTIME_MODE_SWITCH_ABORTED";

export interface RuntimeInfoPayload {
  Code: RuntimeInfoCode;
  RequestId: string;
  EventId: string;
  FromMode: RuntimeMode;
  ToMode: RuntimeMode;
  FromSeed: string;
  ToSeed: string;
  HasUrl: boolean;
  Reason?: string;
}

export function logRuntimeInfo(
  code: RuntimeInfoCode,
  detail: Omit<RuntimeInfoPayload, "Code" | "RequestId" | "EventId">,
): RuntimeInfoPayload {
  const payload: RuntimeInfoPayload = {
    Code: code,
    RequestId: BOOT_REQUEST_ID,
    EventId: randomId("evt"),
    ...detail,
  };

  console.info("[runtime-mode]", payload);

  return payload;
}

// ---------------------------------------------------------------------------
// Field validators (schema P-01 alignment with 01-version-json-schema.md)
// ---------------------------------------------------------------------------

function isValidMode(v: unknown): v is RuntimeMode {
  return typeof v === "string" && (VALID_MODES as readonly string[]).includes(v);
}

function isValidApiBaseUrl(mode: RuntimeMode, url: unknown): url is string | null {
  if (mode === "preview") return url === null;

  return typeof url === "string" && url.length > 0 && /^https?:\/\//.test(url);
}

function isValidPreviewSeed(seed: unknown): seed is string {
  return typeof seed === "string" && seed.length > 0 && seed.length <= 64;
}

function pickModeFields(source: RuntimeConfig): RuntimeConfig {
  return {
    Mode: source.Mode,
    ApiBaseUrl: source.ApiBaseUrl,
    PreviewSeed: source.PreviewSeed,
  };
}

// ---------------------------------------------------------------------------
// version.json fetch + validation
// ---------------------------------------------------------------------------

type Result<T> = { ok: true; data: T } | { ok: false; error: unknown };

function validateVersionJson(raw: unknown): Result<VersionJson> {
  if (!raw || typeof raw !== "object") return { ok: false, error: "not-object" };
  const r = raw as Record<string, unknown>;
  if (isValidMode(r.Mode) === false) return { ok: false, error: "invalid-mode" };
  if (isValidApiBaseUrl(r.Mode, r.ApiBaseUrl) === false)
    return { ok: false, error: "invalid-api-base-url" };
  if (isValidPreviewSeed(r.PreviewSeed) === false)
    return { ok: false, error: "invalid-preview-seed" };
  if (typeof r.Version !== "string") return { ok: false, error: "invalid-version" };
  if (typeof r.UpdatedAt !== "string") return { ok: false, error: "invalid-updated-at" };
  if (typeof r.AllowRuntimeToggle !== "boolean") return { ok: false, error: "invalid-toggle-flag" };

  return { ok: true, data: r as unknown as VersionJson };
}

export async function loadVersionJson(
  fetchImpl: typeof fetch = fetch,
): Promise<Result<VersionJson>> {
  try {
    const res = await fetchImpl(VERSION_JSON_PATH, { cache: "no-store" });
    const isFailed = !res.ok;
    if (isFailed) return { ok: false, error: `http-${res.status}` };
    const body = (await res.json()) as unknown;

    return validateVersionJson(body);
  } catch (err) {
    return { ok: false, error: err };
  }
}

// ---------------------------------------------------------------------------
// localStorage override parsing (P-02: Version must match current)
// ---------------------------------------------------------------------------

function safeLocalStorageGet(key: string): string | null {
  try {
    if (typeof window === "undefined" || !window.localStorage) return null;

    return window.localStorage.getItem(key);
  } catch {
    return null;
  }
}

function validateOverride(raw: unknown): Result<StoredOverride> {
  if (!raw || typeof raw !== "object") return { ok: false, error: "not-object" };
  const r = raw as Record<string, unknown>;
  if (isValidMode(r.Mode) === false) return { ok: false, error: "invalid-mode" };
  if (isValidApiBaseUrl(r.Mode, r.ApiBaseUrl) === false)
    return { ok: false, error: "invalid-api-base-url" };
  if (isValidPreviewSeed(r.PreviewSeed) === false)
    return { ok: false, error: "invalid-preview-seed" };
  if (typeof r.Version !== "string") return { ok: false, error: "invalid-version" };
  if (typeof r.WrittenAt !== "string") return { ok: false, error: "invalid-written-at" };

  return { ok: true, data: r as unknown as StoredOverride };
}

function parseOverride(raw: string): Result<StoredOverride> {
  try {
    return validateOverride(JSON.parse(raw));
  } catch (err) {
    return { ok: false, error: err };
  }
}

// ---------------------------------------------------------------------------
// Core resolver (algorithm from spec 02, section "Resolution Algorithm")
// ---------------------------------------------------------------------------

function applyVersionJson(base: RuntimeConfig, remote: Result<VersionJson>): RuntimeConfig {
  if (remote.ok && remote.data.Version === PACKAGE_VERSION) {
    return pickModeFields(remote.data);
  }
  if (!remote.ok) logRuntimeError("RUNTIME_CONFIG_LOAD_FAILED", remote.error);

  return base;
}

function applyOverride(base: RuntimeConfig): RuntimeConfig {
  const raw = safeLocalStorageGet(RUNTIME_OVERRIDE_KEY);
  if (!raw) return base;
  const parsed = parseOverride(raw);
  if (parsed.ok && parsed.data.Version === PACKAGE_VERSION) {
    return pickModeFields(parsed.data);
  }
  if (!parsed.ok) logRuntimeError("RUNTIME_OVERRIDE_INVALID", parsed.error);

  return base;
}

export async function resolveRuntimeConfig(
  fetchImpl: typeof fetch = fetch,
): Promise<RuntimeConfig> {
  const remote = await loadVersionJson(fetchImpl);
  const afterRemote = applyVersionJson({ ...COMPILE_TIME_DEFAULT }, remote);

  return applyOverride(afterRemote);
}

// ---------------------------------------------------------------------------
// Freeze-at-hydration store (F-01..F-04). SSR always sees the default.
// ---------------------------------------------------------------------------

let frozenConfig: RuntimeConfig = { ...COMPILE_TIME_DEFAULT };
let isFrozen = false;

export function getRuntimeMode(): RuntimeConfig {
  return frozenConfig;
}

export function freezeRuntimeMode(cfg: RuntimeConfig): void {
  frozenConfig = pickModeFields(cfg);
  isFrozen = true;
}

export function isRuntimeModeFrozen(): boolean {
  return isFrozen;
}

export function resetRuntimeMode(): void {
  // F-03: only preview/dev callers should invoke this; enforcement lives in
  // the debug drawer (Step 83) and test harness. The core module stays pure.
  frozenConfig = { ...COMPILE_TIME_DEFAULT };
  isFrozen = false;
}

export function getCompileTimeDefault(): RuntimeConfig {
  return { ...COMPILE_TIME_DEFAULT };
}

// Convenience predicates for consumers that must NOT read import.meta.env.MODE.
export const isPreview = (): boolean => getRuntimeMode().Mode === "preview";
export const isDev = (): boolean => getRuntimeMode().Mode === "dev";
export const isProduction = (): boolean => getRuntimeMode().Mode === "production";
