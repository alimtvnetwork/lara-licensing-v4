import { expect, test } from "@playwright/test";

/**
 * Plan 16 step 55. Preview-mode scenario overlay smoke.
 *
 * Root cause this file protects against (one sentence): a regression
 * in `src/lib/preview-scenario.ts` or `src/lib/api-client.ts::callPreview`
 * silently returns success from a preview handler even when scenario
 * is `offline` / `rate-limited`, so admin routes appear healthy in
 * preview while their live counterparts would crash - INV-RM-06 broken.
 *
 * Contract:
 * - App boots in preview mode (repo `public/version.json` Mode:"preview").
 * - Router boot (src/router.tsx lines 18..36) exposes
 *   `window.__LARA_PREVIEW__ = { getScenario, setScenario }` after
 *   `registerAllPreviewHandlers()` completes.
 * - Playwright drives the switch, then invokes `apiClient.call` via a
 *   dynamic import through Vite so the assertion crosses the exact
 *   `callPreview -> applyPreviewScenario -> dispatchPreview` path used
 *   in production preview builds.
 *
 * No login required: `apiClient.call("admin.metrics.kpis", {})` runs
 * against the seeded preview handler; the scenario wrapper decides the
 * outcome. This is the minimum surface that proves the overlay end-to-
 * end from a real browser. Route-level walks land with Step 57.
 */

const BOOT_TIMEOUT_MS = 10_000;

type PreviewBridge = {
  getScenario: () => "offline" | "slow" | "rate-limited" | null;
  setScenario: (s: "offline" | "slow" | "rate-limited" | null) => void;
};

async function waitForPreviewBridge(page: import("@playwright/test").Page): Promise<void> {
  await page.waitForFunction(
    () => Boolean((window as unknown as { __LARA_PREVIEW__?: unknown }).__LARA_PREVIEW__),
    undefined,
    { timeout: BOOT_TIMEOUT_MS },
  );
}

async function callAdminMetrics(page: import("@playwright/test").Page): Promise<{ ok: boolean; code?: string; status?: number; ms: number }> {
  return page.evaluate(async () => {
    const started = performance.now();
    try {
      const mod = await import("/src/lib/api-client.ts");
      await mod.apiClient.call("admin.metrics.kpis", {});
      return { ok: true, ms: performance.now() - started };
    } catch (err) {
      const e = err as { errorCode?: string; httpStatus?: number };
      return { ok: false, code: e.errorCode, status: e.httpStatus, ms: performance.now() - started };
    }
  });
}

test.describe("preview scenario overlay smoke", () => {
  test.beforeEach(async ({ page }) => {
    await page.goto("/");
    await waitForPreviewBridge(page);
    await page.evaluate(() => (window as unknown as { __LARA_PREVIEW__: PreviewBridge }).__LARA_PREVIEW__.setScenario(null));
  });

  test("null scenario succeeds", async ({ page }) => {
    const res = await callAdminMetrics(page);
    expect(res.ok).toBe(true);
  });

  test("offline scenario throws ServerError with httpStatus 0", async ({ page }) => {
    await page.evaluate(() => (window as unknown as { __LARA_PREVIEW__: PreviewBridge }).__LARA_PREVIEW__.setScenario("offline"));
    const res = await callAdminMetrics(page);
    expect(res.ok).toBe(false);
    expect(res.code).toBe("ServerError");
    expect(res.status).toBe(0);
  });

  test("rate-limited scenario throws RateLimited with httpStatus 429", async ({ page }) => {
    await page.evaluate(() => (window as unknown as { __LARA_PREVIEW__: PreviewBridge }).__LARA_PREVIEW__.setScenario("rate-limited"));
    const res = await callAdminMetrics(page);
    expect(res.ok).toBe(false);
    expect(res.code).toBe("RateLimited");
    expect(res.status).toBe(429);
  });

  test("slow scenario injects >= 1.8s latency then succeeds", async ({ page }) => {
    await page.evaluate(() => (window as unknown as { __LARA_PREVIEW__: PreviewBridge }).__LARA_PREVIEW__.setScenario("slow"));
    const res = await callAdminMetrics(page);
    expect(res.ok).toBe(true);
    expect(res.ms).toBeGreaterThanOrEqual(1800);
  });
});
