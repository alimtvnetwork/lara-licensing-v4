import { test, expect } from '@playwright/test';
import { loginAsDemo } from '../../helpers/demo-login';

test.use({ viewport: { width: 1280, height: 1800 } });

test('Notification drawer supports Copy correlation IDs', async ({ page, context }) => {
  test.info().annotations.push({ type: 'seed', description: 'error' });

  // Grant clipboard permissions
  await context.grantPermissions(['clipboard-read', 'clipboard-write'], { origin: 'http://localhost' });

  await loginAsDemo(page, 'SuperAdmin');

  // Trigger error
  await page.goto('/admin/dashboard?scenario=error');
  await expect(page.getByRole('alert')).toBeVisible();

  // Open drawer
  const bell = page.getByTestId('notification-bell');
  await bell.click();

  const drawer = page.getByTestId('notification-drawer');
  await expect(drawer).toBeVisible();

  // Find the Copy correlation IDs button on the first entry
  const firstEntry = drawer.locator('li').first();
  const copyBtn = firstEntry.getByRole('button', { name: /Copy correlation IDs/i });
  await expect(copyBtn).toBeVisible();
  await copyBtn.click();

  // Verify clipboard content
  const clipboardText = await page.evaluate(() => navigator.clipboard.readText());
  expect(clipboardText).toContain('RequestId');
  // Operations might not have all, but they should have at least RequestId, possibly ErrorId
});
