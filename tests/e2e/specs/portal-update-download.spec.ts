import { expect, test } from "../fixtures/lara-auth";

/**
 * Plan 10 step 34. Portal update download smoke.
 *
 * Root cause locked: `/portal/updates`
 * (`src/routes/_authenticated/portal.updates.tsx` lines 35-92) is the
 * only surface wiring `updateManifestQueryOptions()` ->
 * `fetchUpdateManifest()` (`src/lib/lara-self-update.ts` lines 74-89) ->
 * `ManifestPanel` state machine (lines 125-158) -> `ManifestReady`
 * up-to-date vs upgrade branch (lines 175-247). A regression in any of
 * (query wiring, error mapping via `LaraApiError -> copyForErrorCode`,
 * or the `LatestVersion === currentVersion` "up to date" branch)
 * silently breaks the sole EndUser privileged capability.
 *
 * Strategy: stub `**\/App/UpdateManifest**` via `page.route` so the spec
 * is deterministic (no seeded manifest required) and never touches the
 * SHA-256 asset verification path (that is exercised by the unit tests
 * on `downloadUpdateAsset`, not this UI smoke).
 */
const RESPONSE_HEADERS = { "Content-Type": "application/json" } as const;

function successEnvelope(result: unknown) {
  return {
    Status: { IsSuccess: true, Code: 200, Message: "OK" },
    Attributes: { RequestId: "e2e-portal-updates", RequestedAt: new Date().toISOString() },
    Results: [result],
  };
}

function failureEnvelope(errorCode: string, message: string) {
  return {
    Status: { IsSuccess: false, Code: 500, Message: message },
    Attributes: {
      RequestId: "e2e-portal-updates",
      RequestedAt: new Date().toISOString(),
      Error: { ErrorCode: errorCode, ErrorMessage: message },
    },
    Results: [],
  };
}

const SAMPLE_MANIFEST = {
  Product: "LicensingPortalClient",
  Channel: "Stable",
  LatestVersion: "0.0.0",
  MinRequiredVersion: "0.0.0",
  PublishedAt: "2026-07-19T00:00:00.000Z",
  Assets: [
    {
      Platform: "WindowsAmd64",
      Url: "https://example.invalid/win.bin",
      SizeBytes: 1024,
      Sha256: "a".repeat(64),
    },
    {
      Platform: "LinuxAmd64",
      Url: "https://example.invalid/linux.bin",
      SizeBytes: 1024,
      Sha256: "b".repeat(64),
    },
    {
      Platform: "DarwinArm64",
      Url: "https://example.invalid/mac.bin",
      SizeBytes: 1024,
      Sha256: "c".repeat(64),
    },
  ],
};

test.describe("Portal update download smoke", () => {
  test("up-to-date manifest renders EmptyState instead of a Download button", async ({
    signInAsAdmin,
    page,
  }) => {
    // VITE_LARA_APP_VERSION defaults to "0.0.0" in the client
    // (portal.updates.tsx `currentAppVersion` line 55-58), which matches
    // SAMPLE_MANIFEST.LatestVersion so we deterministically hit the
    // up-to-date branch at line 215-220.
    await page.route("**/App/UpdateManifest**", (route) =>
      route.fulfill({
        status: 200,
        headers: RESPONSE_HEADERS,
        body: JSON.stringify(successEnvelope(SAMPLE_MANIFEST)),
      }),
    );

    await signInAsAdmin();
    await page.goto("/portal/updates");
    await page.waitForURL(/\/portal\/updates/);

    // PageHeader (line 77-80).
    await expect(page.getByRole("heading", { name: "App update" })).toBeVisible();

    // Manifest ready panel + up-to-date EmptyState (lines 205, 215-220).
    const manifest = page.getByTestId("portal-updates-manifest");
    await expect(manifest).toBeVisible({ timeout: 10_000 });
    await expect(manifest.getByText("You are up to date")).toBeVisible();

    // Download button MUST NOT render in the up-to-date branch.
    await expect(page.getByTestId("portal-updates-download")).toHaveCount(0);
  });

  test("manifest error surfaces the error panel with a retry affordance", async ({
    signInAsAdmin,
    page,
  }) => {
    let hits = 0;
    await page.route("**/App/UpdateManifest**", (route) => {
      hits += 1;
      return route.fulfill({
        status: 500,
        headers: RESPONSE_HEADERS,
        body: JSON.stringify(failureEnvelope("ServerError", "Manifest fetch failed.")),
      });
    });

    await signInAsAdmin();
    await page.goto("/portal/updates");
    await page.waitForURL(/\/portal\/updates/);

    const errorPanel = page.getByTestId("portal-updates-error");
    await expect(errorPanel).toBeVisible({ timeout: 10_000 });
    await expect(errorPanel.getByRole("button", { name: "Retry" })).toBeVisible();

    // Retry must re-issue the manifest request so a transient upstream
    // failure is not fatal (line 150-152, `onRetry -> refetch`).
    const before = hits;
    await errorPanel.getByRole("button", { name: "Retry" }).click();
    await expect.poll(() => hits, { timeout: 5_000 }).toBeGreaterThan(before);
  });
});
