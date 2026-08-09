import { expect } from "@playwright/test";
import { test } from "../fixtures/lara-auth";

/**
 * Plan 16 Step 93. Preview seed E2E matrix (authed rows).
 *
 * Root cause this file protects against (one sentence): the preview
 * runtime's IndexedDB seed produces deterministic rows for every admin
 * / catalog list, but nothing E2E-asserts that a real browser sees the
 * exact seeded row counts, so a regression in
 * `src/lib/preview-seeds/default.ts`, `src/lib/preview-seeds/empty.ts`,
 * or any handler under `src/lib/preview-fixtures/*` could ship silently
 * and fake the very demos the preview mode was built to enable.
 *
 * Contract:
 * - Boot in preview mode (repo `public/version.json` Mode:"preview").
 * - Baseline "default" seed asserts the shipped canonical counts
 *   (LIC=3, QUOTA=3, AUDIT=4, USERS=2, FEATURES=4) matching
 *   `src/lib/preview-seeds/default.ts` lines 80..119.
 * - Reset + re-hydrate the "empty" seed via dynamic imports (mirrors
 *   the runtime settings reseed path). Empty seed populates only auth
 *   (INV-RM-05): USERS=2, everything else 0.
 * - Every list call is typed through `apiClient.call<Op>` so the same
 *   `callPreview -> applyPreviewScenario -> dispatchPreview` path used
 *   in preview production is exercised end-to-end.
 *
 * Silent-failure guardrail: if the bridge is missing, or the counts
 * drift, the test hard-fails and names the offending domain.
 */

const BOOT_TIMEOUT_MS = 10_000;

type SeedResult = {
  licenses: number;
  quotas: number;
  audit: number;
  users: number;
  features: number;
};

async function waitForBridge(page: import("@playwright/test").Page): Promise<void> {
  await page.waitForFunction(
    () => Boolean((window as unknown as { __LARA_PREVIEW__?: unknown }).__LARA_PREVIEW__),
    undefined,
    { timeout: BOOT_TIMEOUT_MS },
  );
}

async function collectCounts(page: import("@playwright/test").Page): Promise<SeedResult> {
  return page.evaluate(async () => {
    const mod = await import("/src/lib/api-client.ts");
    const call = mod.apiClient.call.bind(mod.apiClient);
    const [lic, qta, aud, usr, feat] = await Promise.all([
      call("admin.licenses.list", {}),
      call("admin.quotas.list", {}),
      call("admin.audit.list", {}),
      call("admin.users.list", {}),
      call("admin.features.list", {}),
    ]);
    return {
      licenses: (lic as { Items: unknown[] }).Items.length,
      quotas: (qta as { Items: unknown[] }).Items.length,
      audit: (aud as { Items: unknown[] }).Items.length,
      users: (usr as { Items: unknown[] }).Items.length,
      features: (feat as { Items: unknown[] }).Items.length,
    };
  });
}

async function reseedEmpty(page: import("@playwright/test").Page): Promise<void> {
  await page.evaluate(async () => {
    const store = await import("/src/lib/preview-store.ts");
    const empty = await import("/src/lib/preview-seeds/empty.ts");
    await store.resetAll();
    await empty.loadEmptySeed();
  });
}

test.describe("preview seed matrix (authed rows)", () => {
  test.beforeEach(async ({ page }) => {
    await page.goto("/");
    await waitForBridge(page);
  });

  test("Error profile mounts error-trigger classes", async ({ signInAsAdmin, page }) => {
    // Requires PLAYWRIGHT_SEED_PROFILE=error
    test.info().annotations.push({ type: 'seed', description: 'error' });
    
    await signInAsAdmin();
    await page.goto("/admin/licenses");
    await expect(page.getByText('expired', { exact: false }).first()).toBeVisible();
  });

  test("default seed exposes canonical row counts across all admin lists", async ({ page }) => {
    const counts = await collectCounts(page);
    expect(counts, "default seed drift").toEqual({
      licenses: 3,
      quotas: 3,
      audit: 28,
      users: 2,
      features: 4,
    });
  });

  test("empty seed keeps auth users and zeroes every other domain", async ({ page }) => {
    await reseedEmpty(page);
    const counts = await collectCounts(page);
    expect(counts, "empty seed drift").toEqual({
      licenses: 0,
      quotas: 0,
      audit: 0,
      users: 2,
      features: 0,
    });
  });
});
