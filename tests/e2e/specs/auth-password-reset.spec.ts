import { expect, test } from "@playwright/test";

import { mintPasswordResetToken } from "../helpers/backend-token";
import { requireEnv } from "../helpers/env";

/**
 * Plan 10 step 30. Password reset e2e (forgot -> mint -> redeem -> login).
 *
 * Root cause locked: the reset flow spans three surfaces (`/forgot-password`
 * -> `POST /Api/Auth/ForgotPassword`, out-of-band plaintext token mint,
 * `/reset-password?Email&Token` -> `POST /Api/Auth/ResetPassword`, then
 * `/admin/login`). A regression in any single hop (token TTL, single-use
 * invalidation, PAT revocation on redeem) silently locks users out. This
 * spec exercises every hop with a deterministic token minted via the
 * artisan helper (`e2e:mint-reset-token`) so no SMTP or log scraping is
 * required.
 *
 * Fixtures required: seeded demo Admin from `E2EFixturesSeeder`.
 * Credentials in `E2E_ADMIN_EMAIL` / `E2E_ADMIN_PASSWORD`. The spec sets
 * a NEW password, then signs in with it to prove redemption landed, then
 * restores the seeded password so subsequent specs keep working.
 */
test.describe("Password reset flow", () => {
  test("forgot-password neutral response is rendered", async ({ page }) => {
    await page.goto("/forgot-password");
    await expect(async () => {
      await page.locator("#forgot-email").fill("nobody+e2e@example.test");
      await expect(page.getByRole("button", { name: /send reset link/i })).toBeEnabled({ timeout: 1000 });
    }).toPass();
    await page.getByRole("button", { name: /send reset link/i }).click();
    await expect(page.getByRole("status")).toContainText(/reset link has been generated/i);
  });

  test("mint -> redeem -> sign in with new password -> restore", async ({ page }) => {
    const email = requireEnv("E2E_ADMIN_EMAIL");
    const originalPassword = requireEnv("E2E_ADMIN_PASSWORD");
    const tempPassword = `e2e-rotate-${Date.now()}-Aa!`;

    // 1. Mint token deterministically (bypass SMTP).
    const minted = await mintPasswordResetToken(email);
    expect(minted.Token.length).toBeGreaterThan(32);

    // 2. Redeem via /reset-password with prefilled search params.
    await page.goto(
      `/reset-password?Email=${encodeURIComponent(minted.EmailLower)}&Token=${encodeURIComponent(minted.Token)}`,
    );
    await page.locator("#reset-password").fill(tempPassword);
    await page.locator("#reset-confirm").fill(tempPassword);
    await page.getByRole("button", { name: /update password/i }).click();
    await expect(page.getByRole("status")).toContainText(/password updated/i);

    // 3. The route auto-navigates to /admin/login after 1.5s. Wait for it.
    await page.waitForURL(/\/admin\/login/, { timeout: 5_000 });

    // 4. Sign in with the NEW password to prove redemption landed server-side.
    await page.locator("#admin-email").fill(email);
    await page.locator("#admin-password").fill(tempPassword);
    await page.getByRole("button", { name: /sign in/i }).click();
    await page.waitForURL(/\/admin(\/|$)/, { timeout: 10_000 });

    // 5. Second redemption of the same token must fail (single-use invariant).
    await page.goto(
      `/reset-password?Email=${encodeURIComponent(minted.EmailLower)}&Token=${encodeURIComponent(minted.Token)}`,
    );
    await page.locator("#reset-password").fill(originalPassword);
    await page.locator("#reset-confirm").fill(originalPassword);
    await page.getByRole("button", { name: /update password/i }).click();
    await expect(page.getByRole("alert")).toContainText(/invalid|expired/i);

    // 6. Restore the seeded password via a fresh mint so downstream specs
    //    keep authenticating with E2E_ADMIN_PASSWORD unchanged.
    const restore = await mintPasswordResetToken(email);
    await page.goto(
      `/reset-password?Email=${encodeURIComponent(restore.EmailLower)}&Token=${encodeURIComponent(restore.Token)}`,
    );
    await page.locator("#reset-password").fill(originalPassword);
    await page.locator("#reset-confirm").fill(originalPassword);
    await page.getByRole("button", { name: /update password/i }).click();
    await expect(page.getByRole("status")).toContainText(/password updated/i);
  });
});
