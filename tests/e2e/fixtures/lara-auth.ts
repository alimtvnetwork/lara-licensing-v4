import { test as base, expect } from "@playwright/test";

import { AdminDashboardPage } from "../pages/AdminDashboardPage";
import { AdminLoginPage } from "../pages/AdminLoginPage";
import { requireEnv } from "../helpers/env";
import { saveStorageState } from "../helpers/storage-state";

/**
 * Plan 10 step 27. Shared Playwright fixture that logs in as the
 * seeded demo Admin (see `backend/database/seeders/E2EFixturesSeeder.php`,
 * plan step 12) and hands specs three ready-to-use objects:
 *
 * - `loginPage`  / `dashboardPage`: Page Objects for auth + landing.
 * - `signInAsAdmin()`: idempotent helper that navigates through login
 *   and asserts the dashboard mounted, so failures surface at the auth
 *   boundary rather than deep inside a downstream spec.
 *
 * Credentials come from `E2E_ADMIN_EMAIL` / `E2E_ADMIN_PASSWORD` and
 * fail loud if unset (`requireEnv`). Never hardcode.
 */
type LaraAuthFixtures = {
  loginPage: AdminLoginPage;
  dashboardPage: AdminDashboardPage;
  signInAsAdmin: () => Promise<void>;
};

export const test = base.extend<LaraAuthFixtures>({
  loginPage: async ({ page }, use) => {
    await use(new AdminLoginPage(page));
  },
  dashboardPage: async ({ page }, use) => {
    await use(new AdminDashboardPage(page));
  },
  signInAsAdmin: async ({ page, loginPage, dashboardPage, context }, use) => {
    const signIn = async (): Promise<void> => {
      const email = requireEnv("E2E_ADMIN_EMAIL");
      const password = requireEnv("E2E_ADMIN_PASSWORD");
      await loginPage.login(email, password);
      await dashboardPage.expectLoaded();
      await saveStorageState(context, "admin");
    };
    await use(signIn);
  },
});

export { expect };
