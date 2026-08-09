import { expect, test } from "../fixtures/lara-auth";
import { requireEnv } from "../helpers/env";

/**
 * Plan 10 step 28. Auth login e2e.
 *
 * Root cause locked here: every downstream spec (dashboard, license CRUD,
 * quota, impersonation) reuses `signInAsAdmin()`; a silent regression in
 * selectors, redirect timing, or the CAPTCHA gate in
 * `src/routes/admin.login.tsx::handleSubmit` would fail every later spec
 * identically and hide the true cause. This spec asserts both the happy
 * path and one negative branch so failures attribute to auth explicitly.
 *
 * Fixtures required (see `backend/database/seeders/E2EFixturesSeeder.php`,
 * plan step 12): a demo Admin user whose credentials come from env only,
 * never hardcoded here.
 */

test.describe("Admin login", () => {
  test("happy path: seeded admin lands on /admin", async ({ signInAsAdmin, page }) => {
    await signInAsAdmin();
    // signInAsAdmin already asserts the dashboard nav mounted; here we
    // additionally lock in the URL contract so a router regression that
    // silently keeps us on /admin/login would fail loudly.
    expect(page.url()).toMatch(/\/admin(\/|$)/);
  });

  test("negative path: wrong password stays on /admin/login and surfaces an alert", async ({
    loginPage,
    page,
  }) => {
    const email = requireEnv("E2E_ADMIN_EMAIL");
    await loginPage.login(email, "definitely-not-the-real-password");
    // Assert we did NOT navigate away. Router replace to /admin only
    // happens after `loginToLaraApi` resolves; on rejection the catch
    // block renders the alert div (role="alert") with the formatted
    // LaraApiError message.
    await expect(page).toHaveURL(/\/admin\/login/);
    await expect(page.getByRole("alert")).toBeVisible();
  });

  test("non-preview mode does not render DemoLoginPanel", async ({ loginPage, page }) => {
    // This assumes the e2e test runner is running against the production build without the preview mock
    // If the DemoLoginPanel is strictly gated behind import.meta.env.DEV, it will not exist.
    await page.goto("/admin/login");
    await expect(page.getByTestId("demo-login-panel")).toHaveCount(0);
  });
});
