import { test, expect } from '@playwright/test';
import { loginAsDemo } from '../../helpers/demo-login';

test.use({ viewport: { width: 1280, height: 1800 } });

test('Admin overview displays green KPIs with default seed', async ({ page }) => {
  test.info().annotations.push({ type: 'seed', description: 'default' });

  await loginAsDemo(page, 'SuperAdmin');

  // Verify there are no red/error state cards
  await expect(page.locator('.text-destructive')).toHaveCount(0); // Assuming red state implies text-destructive

  // KPI tiles: resellers, licenses, quota requests
  const resellersTile = page.getByTestId('kpi-resellers');
  const licensesTile = page.getByTestId('kpi-licenses');
  const quotasTile = page.getByTestId('kpi-quota-requests');

  // Wait for the tiles to be visible
  await expect(resellersTile).toBeVisible();
  await expect(licensesTile).toBeVisible();
  await expect(quotasTile).toBeVisible();

  // Parse text content to assert values
  const parseCount = async (locator: any) => {
    const text = await locator.textContent();
    return parseInt(text?.replace(/\D/g, '') || '0', 10);
  };

  const rCount = await parseCount(resellersTile);
  expect(rCount).toBeGreaterThanOrEqual(8);

  const lCount = await parseCount(licensesTile);
  expect(lCount).toBeGreaterThanOrEqual(120);

  const qCount = await parseCount(quotasTile);
  expect(qCount).toBeGreaterThanOrEqual(24);

  // Baseline screenshot
  await expect(page).toHaveScreenshot('plan-18/overview-green.png');
});
