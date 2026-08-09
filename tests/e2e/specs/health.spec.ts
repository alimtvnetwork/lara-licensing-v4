import { expect, test } from "@playwright/test";

/**
 * Smoke spec that proves the Playwright wiring is functional without
 * requiring the backend or seeded fixtures. Any real backend/frontend
 * flows live in later plan steps (28+).
 */
test("landing renders", async ({ page }) => {
  const response = await page.goto("/");
  expect(response?.ok(), "landing should return 2xx").toBeTruthy();
  await expect(page).toHaveTitle(/Licensing Portal/i);
});
