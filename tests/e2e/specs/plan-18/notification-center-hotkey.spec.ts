import { test, expect } from '@playwright/test';
import { loginAsDemo } from '../../helpers/demo-login';

test.use({ viewport: { width: 1280, height: 1800 } });

test('Notification center responds to Alt+N and Escape', async ({ page }) => {
  test.info().annotations.push({ type: 'seed', description: 'default' });

  await loginAsDemo(page, 'SuperAdmin');

  const drawer = page.getByTestId('notification-drawer');
  await expect(drawer).toBeHidden();

  // Alt+N to open
  await page.keyboard.press('Alt+N');
  await expect(drawer).toBeVisible();

  // Arrow keys can be pressed (we don't strictly assert the exact element focused unless we know the DOM structure)
  await page.keyboard.press('ArrowDown');
  
  // Escape to close
  await page.keyboard.press('Escape');
  await expect(drawer).toBeHidden();

  // Focus returns to bell
  const bell = page.getByTestId('notification-bell');
  await expect(bell).toBeFocused();
});
