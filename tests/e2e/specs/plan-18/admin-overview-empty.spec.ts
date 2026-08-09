import { test, expect } from '@playwright/test';
import { loginAsDemo } from '../../helpers/demo-login';

test.use({ viewport: { width: 1280, height: 1800 } });

test('Admin overview displays empty states with empty seed', async ({ page }) => {
  test.info().annotations.push({ type: 'seed', description: 'empty' });

  await loginAsDemo(page, 'SuperAdmin');

  // Zero red banners
  await expect(page.locator('.text-destructive')).toHaveCount(0);

  // Check KPI tiles for 0
  const resellersTile = page.getByTestId('kpi-resellers');
  const licensesTile = page.getByTestId('kpi-licenses');
  const quotasTile = page.getByTestId('kpi-quota-requests');

  await expect(resellersTile).toContainText('0');
  await expect(licensesTile).toContainText('0');
  await expect(quotasTile).toContainText('0');

  // Verify empty state copy (assumes empty states render a generic message for empty tables)
  // Matching general copy strings for empty states.
  await expect(page.getByText('No records found', { exact: false })).toBeVisible();

  // Baseline screenshot
  await expect(page).toHaveScreenshot('plan-18/overview-empty.png');
});
