import { test, expect } from "../fixtures/lara-auth";

/**
 * v0.672.0: verify the route errorComponent surfaces BOTH `operationId`
 * and `requestId` so support tickets and debug drawer entries correlate
 * 1:1 with server logs (`spec/03-error-manage/`).
 *
 * Setup:
 *   - Preview mode, default seed (admin can sign in and reach /admin/audit).
 *   - Unregister the `admin.audit.list` preview handler at runtime via the
 *     test-only helper `unregisterPreviewHandlerForTest`. With no handler,
 *     `dispatchPreview` throws `PreviewHandlerMissingError` which extends
 *     `LaraApiError` and pins the failing `operationId` as a first-class
 *     field. The route loader propagates the throw to `RouteErrorState`
 *     (the route errorComponent for `/admin/audit`).
 *
 * Assertions:
 *   - `data-testid="route-error"` banner is visible.
 *   - `data-testid="route-error-operation-id"` reads `admin.audit.list`.
 *   - `data-testid="route-error-request-id"` is a non-empty string.
 */

test.describe("route errorComponent correlation", () => {
  test("displays operationId and requestId on route error", async ({ page, signInAsAdmin }) => {
    await signInAsAdmin();
    // Ensure default seed is loaded so admin auth resolves normally.
    await page.evaluate(async () => {
      const store = await import("/src/lib/preview-store.ts");
      await store.resetAll();
      const seed = await import("/src/lib/preview-seeds/default.ts");
      await seed.loadDefaultSeed();
      const transport = await import("/src/lib/preview-transport.ts");
      transport.unregisterPreviewHandlerForTest("admin.audit.list");
    });

    await page.goto("/admin/audit");

    const banner = page.getByTestId("route-error");
    await expect(banner).toBeVisible({ timeout: 10_000 });

    const opId = page.getByTestId("route-error-operation-id");
    await expect(opId).toBeVisible();
    await expect(opId).toHaveText("admin.audit.list");

    const reqId = page.getByTestId("route-error-request-id");
    await expect(reqId).toBeVisible();
    const reqText = (await reqId.textContent())?.trim() ?? "";
    expect(reqText.length).toBeGreaterThan(0);
  });

  test("Route error exposes Copy correlation IDs button which includes ErrorId", async ({ page }) => {
    test.info().annotations.push({ type: 'seed', description: 'error' });
    
    // Grant clipboard permissions
    await page.context().grantPermissions(['clipboard-read', 'clipboard-write'], { origin: 'http://localhost' });

    // Navigate to a route that will throw an error (e.g. 500)
    await page.goto("/admin/dashboard?scenario=error"); // Mock scenario
    
    const copyBtn = page.getByRole("button", { name: /Copy correlation IDs/i });
    await expect(copyBtn).toBeVisible();
    await copyBtn.click();

    // Verify clipboard content
    const clipboardText = await page.evaluate(() => navigator.clipboard.readText());
    expect(clipboardText).toContain("ErrorId");
    expect(clipboardText).toContain("RequestId");
  });
});
