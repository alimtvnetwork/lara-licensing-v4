import AxeBuilder from "@axe-core/playwright";
import { expect, test } from "@playwright/test";

/**
 * Plan 11 step 36: axe-core WCAG 2.1 AA gate on the open Global Error
 * Modal.
 *
 * Root cause this spec addresses (one sentence): the modal is a
 * screen-blocking Radix Dialog surfaced on every fatal error, but
 * nothing enforces that its rendered tree (title, description, DL of
 * RequestId/ErrorId/HTTP, Copy + Dismiss buttons, focus trap) satisfies
 * WCAG 2.1 AA, so an aria/contrast regression would ship silently.
 *
 * Strategy mirrors `tests/e2e/specs/a11y-axe.spec.ts`: dispatch the
 * canonical fatal entry via `/e2e/error-harness` (step 35), scope
 * AxeBuilder to `[data-testid=global-error-modal]` so the harness page
 * chrome cannot mask or contribute violations, and require zero
 * violations across the WCAG 2.1 AA + best-practice tag set.
 */

const AXE_TAGS = ["wcag2a", "wcag2aa", "wcag21a", "wcag21aa", "best-practice"];

test.describe("Global Error Modal accessibility (axe, WCAG 2.1 AA)", () => {
  test("open fatal modal has no detectable axe violations", async ({ page }) => {
    await page.goto("/e2e/error-harness", { waitUntil: "networkidle" });
    await page.getByTestId("e2e-trigger-fatal").click();

    const modal = page.getByTestId("global-error-modal");
    await expect(modal).toBeVisible();
    // Wait for the Radix focus trap to settle so axe scans the final tree.
    await expect(page.getByTestId("global-error-dismiss")).toBeFocused();

    const results = await new AxeBuilder({ page })
      .withTags(AXE_TAGS)
      .include('[data-testid="global-error-modal"]')
      .analyze();

    expect(
      results.violations,
      `global-error-modal violations:\n${JSON.stringify(results.violations, null, 2)}`,
    ).toEqual([]);
  });
});
