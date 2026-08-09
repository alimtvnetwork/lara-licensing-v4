import AxeBuilder from "@axe-core/playwright";
import { expect, test } from "../fixtures/lara-auth";

/**
 * Plan 10 step 39. Axe-core accessibility gate.
 *
 * Root cause locked: no automated a11y coverage exists. Keyboard-only and
 * screen-reader users hit undetected regressions across the three
 * highest-traffic surfaces: landing `/`, admin login `/admin/login`, and
 * authenticated admin `/admin` (which composes `AppShell`, `AppSidebar`,
 * `PageHeader`, `Breadcrumbs`, `TopbarSearch`, `ProfileMenu`,
 * `CommandPalette`, and the KPI `StatCard` grid). Without an axe gate,
 * violations like missing button names, low-contrast tokens, or duplicate
 * ids in list-rendered rows land silently.
 *
 * Strategy: run `AxeBuilder` with WCAG 2.1 AA + best-practice tags on each
 * surface and fail the test on ANY violation. `.disableRules(["region"])`
 * is applied only where a route intentionally renders without a landmark
 * shell (marketing `/` scrolls before landmark mounts). Every reported
 * violation must be fixed, not suppressed, unless a documented exception
 * is added here with rationale.
 */

const AXE_TAGS = ["wcag2a", "wcag2aa", "wcag21a", "wcag21aa", "best-practice"];

test.describe("Accessibility (axe-core, WCAG 2.1 AA)", () => {
  test("landing `/` has no detectable axe violations", async ({ page }) => {
    await page.goto("/", { waitUntil: "networkidle" });
    const results = await new AxeBuilder({ page }).withTags(AXE_TAGS).analyze();
    expect(
      results.violations,
      `landing violations:\n${JSON.stringify(results.violations, null, 2)}`,
    ).toEqual([]);
  });

  test("admin login `/admin/login` has no detectable axe violations", async ({ page }) => {
    await page.goto("/admin/login", { waitUntil: "networkidle" });
    const results = await new AxeBuilder({ page }).withTags(AXE_TAGS).analyze();
    expect(
      results.violations,
      `admin-login violations:\n${JSON.stringify(results.violations, null, 2)}`,
    ).toEqual([]);
  });

  test("authenticated admin `/admin` has no detectable axe violations", async ({
    signInAsAdmin,
    page,
  }) => {
    await signInAsAdmin();
    await expect(page).toHaveURL(/\/admin(\/|$)/);
    await expect(page.getByRole("heading", { name: "Overview", level: 1 })).toBeVisible();
    // Exclude Sonner toast portal: toasts are ephemeral and scanning them
    // during the initial paint window is racy; they have their own aria-live.
    const results = await new AxeBuilder({ page })
      .withTags(AXE_TAGS)
      .exclude("[data-sonner-toaster]")
      .analyze();
    expect(
      results.violations,
      `admin-dashboard violations:\n${JSON.stringify(results.violations, null, 2)}`,
    ).toEqual([]);
  });
});
