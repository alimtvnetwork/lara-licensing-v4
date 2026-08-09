import { expect, test } from "@playwright/test";

import { RegisterPage } from "../pages/RegisterPage";

/**
 * Plan 10 step 29. SuperAdmin bootstrap registration e2e.
 *
 * Root cause locked here: `/register` is a one-shot invariant. The API
 * `POST /Api/Auth/Register` opens only until the first Root user exists;
 * every subsequent call returns `AuthRegistrationClosed` (403). If the
 * frontend regresses (missing route, wrong error branch, exposed form on
 * a seeded workspace) fresh installs are locked out and existing
 * workspaces get an unexpected re-open. Two branches are exercised:
 *
 * 1. Client-side password validation (<12 chars) surfaces the inline
 *    error rendered by `handleSubmit` in `src/routes/register.tsx`
 *    without hitting the API.
 * 2. On a seeded workspace (E2EFixturesSeeder registers the demo Admin,
 *    plan step 12) the API rejects with `AuthRegistrationClosed`; the UI
 *    must render the alert and the "Go to sign in" link.
 */
test.describe("Register bootstrap", () => {
  test("password shorter than 12 chars: inline error, no navigation", async ({ page }) => {
    const register = new RegisterPage(page);
    await register.register("bootstrap@example.test", "short-pass");
    await expect(page).toHaveURL(/\/register$/);
    await expect(page.getByText(/at least 12 characters/i)).toBeVisible();
  });

  test("registration closed on seeded workspace: alert + sign-in link", async ({ page }) => {
    const register = new RegisterPage(page);
    // Uses a synthetic email so we never accidentally claim SuperAdmin
    // on a fresh DB; the seeded workspace should reject with
    // AuthRegistrationClosed regardless of the address.
    await register.register(
      `e2e-bootstrap-${Date.now()}@example.test`,
      "correct-horse-battery-staple",
    );
    await expect(page).toHaveURL(/\/register$/);
    const alert = page.getByRole("alert");
    await expect(alert).toBeVisible();
    await expect(alert).toContainText(/registration is closed/i);
    await expect(page.getByRole("link", { name: /go to sign in/i })).toBeVisible();
  });
});
