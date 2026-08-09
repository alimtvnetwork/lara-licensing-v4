import { test, expect } from '@playwright/test';

test.use({ viewport: { width: 1280, height: 1800 } });

test('DemoLoginPanel renders chips and logs in successfully', async ({ page }) => {
  test.info().annotations.push({ type: 'seed', description: 'default' });

  await page.goto('/admin/login');

  const panel = page.getByTestId('demo-login-panel');
  await expect(panel).toBeVisible();

  // Baseline screenshot
  await expect(panel).toHaveScreenshot('plan-18/demo-login-panel.png');

  // Verify chips
  await expect(panel.getByRole('button', { name: /SuperAdmin/i })).toBeVisible();
  await expect(panel.getByRole('button', { name: /Reseller/i })).toBeVisible();
  await expect(panel.getByRole('button', { name: /Portal/i })).toBeVisible();

  // Click SuperAdmin
  await panel.getByRole('button', { name: /SuperAdmin/i }).click();

  // Submit
  await page.getByRole('button', { name: /Sign in/i }).click();

  // Assert successful navigation
  await expect(page).toHaveURL(/\/admin\/overview/);
});
