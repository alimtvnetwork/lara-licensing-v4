import { expect, test } from "@playwright/test";

/**
 * Plan 11 step 35: E2E proof for the FE error surface.
 *
 * Root cause this spec addresses (one sentence): steps 28-32 built the
 * error-store, Global Error Modal, retryable-toast surface, Global
 * Rate Limit Banner, and submit-lock, but nothing proved end-to-end
 * that a real browser opens the modal on a fatal error, renders the
 * countdown banner on a 429, and copies the Error ID to the OS
 * clipboard.
 *
 * The modal is owned by classifyRetryPolicy() `NoRetry` / `FatalClear`
 * entries (src/components/global/GlobalErrorModal.tsx). ServerError
 * (500) maps to `ExpBackoff` and belongs to the toast surface, so the
 * modal path is exercised with `AuthForbidden` — the canonical
 * `NoRetry` code that still carries an `ErrorId` + `RequestId` envelope
 * for the copy button. The 429 case exercises the `RetryAfter`
 * classifier plus `GlobalRateLimitBanner`.
 *
 * Errors are dispatched via the `/e2e/error-harness` route so the
 * spec exercises the exact `pushLaraApiError` seam production uses,
 * without depending on backend timing or seeded rate-limit buckets.
 */

test.describe("Global error surface", () => {
  test("fatal error opens modal with RequestId, ErrorId, and clipboard copy", async ({
    page,
    context,
    browserName,
  }) => {
    if (browserName === "chromium") {
      await context.grantPermissions(["clipboard-read", "clipboard-write"], {
        origin: "http://localhost:8080",
      });
    }

    await page.goto("/e2e/error-harness");
    await page.getByTestId("e2e-trigger-fatal").click();

    const modal = page.getByTestId("global-error-modal");
    await expect(modal).toBeVisible();
    await expect(modal).toContainText("AuthForbidden");
    await expect(page.getByTestId("global-error-request-id")).toHaveText(
      "req-e2e-000000000001",
    );
    await expect(page.getByTestId("global-error-error-id")).toHaveText(
      "3c6f9b6a-2b1e-4c2a-9e3d-4a2f1b5c6d7e",
    );

    const copyBtn = page.getByTestId("global-error-copy-id");
    await expect(copyBtn).toBeVisible();

    if (browserName === "chromium") {
      await copyBtn.click();
      const clipboardText = await page.evaluate(() =>
        navigator.clipboard.readText(),
      );
      expect(clipboardText).toBe("3c6f9b6a-2b1e-4c2a-9e3d-4a2f1b5c6d7e");
    }

    await page.getByTestId("global-error-dismiss").click();
    await expect(modal).toBeHidden();
  });

  test("429 shows Retry-After banner with countdown and disabled Retry", async ({
    page,
  }) => {
    await page.goto("/e2e/error-harness");
    await page.getByTestId("e2e-trigger-429").click();

    const banner = page.getByRole("status").filter({ hasText: "Rate limit hit." });
    await expect(banner).toBeVisible();
    await expect(banner).toContainText("e2e-bucket");
    await expect(banner).toContainText(/Retry available in \d+s\./);

    const retryBtn = banner.getByRole("button", { name: /Retry in \d+s/ });
    await expect(retryBtn).toBeVisible();
    await expect(retryBtn).toBeDisabled();
  });
});
