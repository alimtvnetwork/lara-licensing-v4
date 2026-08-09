// Captures the original Error out-of-band so `src/server.ts` can recover the
// stack when h3 has already swallowed the throw into a generic 500 Response.
//
// v0.442.0 (Plan 11 step 45 rev 2): this module MUST arm listeners in the
// Cloudflare workerd SSR runtime, not only in the browser. `src/server.ts`
// imports this file at line 1 precisely so `globalThis` error and
// unhandledrejection listeners are registered inside the worker isolate; the
// response normalizer at `src/server.ts` then calls `consumeLastCapturedError()`
// to log the real stack behind an h3 `{"unhandled":true,"message":"HTTPError"}`
// payload. v0.441.0 mistakenly gated on `typeof window`, which disabled that
// SSR path entirely; this file restores the workerd path.
//
// Cross-request safety: capture entries carry a 5s TTL and are consumed by
// the first `consumeLastCapturedError()` call, so a stale entry cannot leak
// into a later request even if the workerd isolate is reused.

let lastCapturedError: { error: unknown; at: number } | undefined;
const TTL_MS = 5_000;

function record(error: unknown) {
  lastCapturedError = { error, at: Date.now() };
}

type GlobalWithEvents = {
  addEventListener?: (type: string, listener: (event: unknown) => void) => void;
};

const globalWithEvents = globalThis as GlobalWithEvents;
if (typeof globalWithEvents.addEventListener === "function") {
  globalWithEvents.addEventListener("error", (event) => {
    const errorEvent = event as { error?: unknown };
    record(errorEvent.error ?? event);
  });
  globalWithEvents.addEventListener("unhandledrejection", (event) => {
    const rejectionEvent = event as { reason?: unknown };
    record(rejectionEvent.reason ?? event);
  });
}

export function consumeLastCapturedError(): unknown {
  if (!lastCapturedError) return undefined;
  if (Date.now() - lastCapturedError.at > TTL_MS) {
    lastCapturedError = undefined;

    return undefined;
  }
  const { error } = lastCapturedError;
  lastCapturedError = undefined;

  return error;
}
