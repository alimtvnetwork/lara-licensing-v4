import { test, expect } from '@playwright/test';

test.use({ viewport: { width: 1280, height: 1800 } });

test('Shift+D Shift+D reveals DemoLoginPanel and focuses first chip', async ({ page }) => {
  test.info().annotations.push({ type: 'seed', description: 'default' });

  await page.goto('/admin/login');

  const panel = page.getByTestId('demo-login-panel');
  
  // Ensure we press Shift+D twice
  await page.keyboard.press('Shift+D');
  await page.keyboard.press('Shift+D');

  await expect(panel).toBeVisible();
  
  const firstChip = panel.getByRole('button').first();
  await expect(firstChip).toBeVisible();
  
  // Check focus
  await expect(firstChip).toBeFocused();
});
