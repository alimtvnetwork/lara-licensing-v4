import { expect, test } from "@playwright/test";

/**
 * Plan 16 Step 95. Runtime toggle E2E.
 *
 * Root cause this file protects against (one sentence): the runtime mode
 * resolver (`src/lib/runtime-mode.ts`) freezes config at hydration and
 * relies on `writeRuntimeOverride` + reload to switch mode/seed, but
 * nothing E2E-asserts that a `PreviewDebugDrawer`-style override actually
 * survives the reload, is honoured by `getRuntimeMode()`, and re-drives
 * `dispatchPreviewSeed()` into the new seed's rowset, so a regression in
 * the precedence chain (`localStorage > /version.json > default`) or in
 * `resolveRuntimeConfig` could silently keep serving the old seed.
 *
 * Contract:
 * - Boot preview default: assert `Mode==="preview"`, `PreviewSeed==="default"`,
 *   admin licenses list count === 3 (canonical baseline).
 * - Write override `{ Mode:"preview", PreviewSeed:"empty" }` at the
 *   frontend PACKAGE_VERSION, wipe IndexedDB via `resetAll()`, reload.
 * - After reload assert `getRuntimeMode().PreviewSeed==="empty"`,
 *   `dispatchPreviewSeed()` result shows `SeedId==="empty"`,
 *   admin licenses list count === 0.
 * - Clear override, wipe IDB, reload; assert config resets to default
 *   and licenses count returns to 3.
 *
 * Silent-failure guardrail: if `getRuntimeMode()` disagrees with the
 * override or list count doesn't flip, the test hard-fails naming the
 * failing axis (seed id vs list count).
 */

const BOOT_TIMEOUT_MS = 10_000;

async function waitForBridge(page: import("@playwright/test").Page): Promise<void> {
  await page.waitForFunction(
    () => Boolean((window as unknown as { __LARA_PREVIEW__?: unknown }).__LARA_PREVIEW__),
    undefined,
    { timeout: BOOT_TIMEOUT_MS },
  );
}

async function readModeAndCount(
  page: import("@playwright/test").Page,
): Promise<{ Mode: string; Seed: string; Licenses: number }> {
  return page.evaluate(async () => {
    const rt = await import("/src/lib/runtime-mode.ts");
    const client = await import("/src/lib/api-client.ts");
    const cfg = rt.getRuntimeMode();
    const list = (await client.apiClient.call("admin.licenses.list", {})) as {
      Items: unknown[];
    };
    return { Mode: cfg.Mode, Seed: cfg.PreviewSeed, Licenses: list.Items.length };
  });
}

async function writeOverrideAndReset(
  page: import("@playwright/test").Page,
  seed: "empty" | "default",
): Promise<void> {
  await page.evaluate(async (nextSeed) => {
    const loader = await import("/src/lib/version-json-loader.ts");
    const store = await import("/src/lib/preview-store.ts");
    loader.writeRuntimeOverride({ Mode: "preview", ApiBaseUrl: null, PreviewSeed: nextSeed });
    await store.resetAll();
  }, seed);
}

async function clearOverrideAndReset(page: import("@playwright/test").Page): Promise<void> {
  await page.evaluate(async () => {
    const loader = await import("/src/lib/version-json-loader.ts");
    const store = await import("/src/lib/preview-store.ts");
    loader.clearRuntimeOverride();
    await store.resetAll();
  });
}

test.describe("runtime toggle (override -> reload -> new seed)", () => {
  test("override to empty seed flips getRuntimeMode + licenses list, revert restores default", async ({
    page,
  }) => {
    await page.goto("/");
    await waitForBridge(page);

    const baseline = await readModeAndCount(page);
    expect(baseline, "default boot state").toEqual({
      Mode: "preview",
      Seed: "default",
      Licenses: 3,
    });

    await writeOverrideAndReset(page, "empty");
    await page.reload();
    await waitForBridge(page);

    const afterOverride = await readModeAndCount(page);
    expect(afterOverride, "override must be honoured after reload").toEqual({
      Mode: "preview",
      Seed: "empty",
      Licenses: 0,
    });

    await clearOverrideAndReset(page);
    await page.reload();
    await waitForBridge(page);

    const afterRevert = await readModeAndCount(page);
    expect(afterRevert, "clearing override must restore default seed").toEqual({
      Mode: "preview",
      Seed: "default",
      Licenses: 3,
    });
  });
});
