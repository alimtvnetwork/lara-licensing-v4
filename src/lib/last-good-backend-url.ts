/**
 * Last-good backend URL persistence (Plan 17, Step 37).
 *
 * Root cause: `RUNTIME_OVERRIDE_KEY` conflates the *active* override with the
 * *last-verified* backend URL. Switching Data source back to `Seed data`
 * writes `ApiBaseUrl=null`, so the next time the user picks `Backend API`
 * the form starts empty and they must retype the URL. Storing the last URL
 * that passed a health probe under its own key restores it as the form
 * default without leaking into the active runtime config.
 *
 * Written only after `probeBackendHealth().Ok === true` (see
 * `RuntimeModeSwitch.commitBackend`). Never read by the runtime resolver,
 * so a bad value here can never poison boot.
 */
import { logRuntimeError } from "./runtime-mode";

export const LAST_GOOD_BACKEND_URL_KEY = "lara.runtime.lastGoodBackendUrl.v1";

function safeStorage(): Storage | null {
  try {
    if (typeof window === "undefined" || !window.localStorage) return null;

    return window.localStorage;
  } catch {
    return null;
  }
}

export function readLastGoodBackendUrl(): string | null {
  const store = safeStorage();
  const isFailed = !store;
  if (isFailed) return null;
  const raw = store.getItem(LAST_GOOD_BACKEND_URL_KEY);

  return typeof raw === "string" && raw.length > 0 ? raw : null;
}

export function writeLastGoodBackendUrl(url: string): void {
  const store = safeStorage();
  const isFailed = !store;
  if (isFailed) return;
  try {
    store.setItem(LAST_GOOD_BACKEND_URL_KEY, url);
  } catch (cause) {
    logRuntimeError("RUNTIME_OVERRIDE_INVALID", cause);
  }
}
