/**
 * @vitest-environment node
 *
 * Plan 11 step 45 rev 2 (v0.442.0): SSR simulation for
 * `src/lib/error-capture.ts` and `src/lib/lovable-error-reporting.ts`.
 *
 * Contract asserted:
 *
 *   1. `error-capture` MUST arm `globalThis` error/unhandledrejection
 *      listeners under Node/workerd (no `window`). This is the SSR path
 *      `src/server.ts` relies on to recover h3-swallowed error stacks.
 *   2. `consumeLastCapturedError()` returns the most recently recorded
 *      error after the listener has fired, and returns `undefined` again
 *      after consumption (single-consumer semantics).
 *   3. `reportLovableError(...)` is a no-op and does not throw when
 *      `window` is absent (SSR safety for the client-only reporter).
 */

import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

describe("error-capture (SSR: workerd/Node global scope)", () => {
  beforeEach(() => {
    vi.resetModules();
  });

  afterEach(() => {
    vi.restoreAllMocks();
  });

  it("arms globalThis listeners in SSR and captures the recorded error", async () => {
    expect(typeof (globalThis as { window?: unknown }).window).toBe("undefined");

    // Node's globalThis has no `addEventListener` by default; workerd
    // exposes one. Install a stub that records registrations so we can
    // simulate the workerd surface without a real event bus.
    const registrations: Array<{ type: string; listener: (event: unknown) => void }> = [];
    const stub = (type: string, listener: (event: unknown) => void) => {
      registrations.push({ type, listener });
    };
    const g = globalThis as { addEventListener?: unknown };
    const original = g.addEventListener;
    g.addEventListener = stub;

    try {
      const mod = await import("@/lib/error-capture");
      const types = registrations.map((r) => r.type);
      expect(types).toContain("error");
      expect(types).toContain("unhandledrejection");

      const errorListener = registrations.find((r) => r.type === "error")?.listener;
      expect(typeof errorListener).toBe("function");

      const boom = new Error("ssr-boom");
      errorListener?.({ error: boom });

      expect(mod.consumeLastCapturedError()).toBe(boom);
      expect(mod.consumeLastCapturedError()).toBeUndefined();
    } finally {
      if (original === undefined) {
        delete g.addEventListener;
      } else {
        g.addEventListener = original;
      }
    }
  });

});

describe("lovable-error-reporting (SSR: no window)", () => {
  beforeEach(() => {
    vi.resetModules();
  });

  it("reportLovableError is a no-op and does not throw without window", async () => {
    expect(typeof (globalThis as { window?: unknown }).window).toBe("undefined");
    const { reportLovableError } = await import("@/lib/lovable-error-reporting");
    expect(() => reportLovableError(new Error("boom"), { source: "ssr-test" })).not.toThrow();
  });
});
