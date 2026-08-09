import { test, expect } from '@playwright/test';
import { loginAsDemo } from '../../helpers/demo-login';

test.use({ viewport: { width: 1280, height: 1800 } });

test('Admin overview displays error state rows with error seed', async ({ page }) => {
  test.info().annotations.push({ type: 'seed', description: 'error' });

  await loginAsDemo(page, 'SuperAdmin');

  // Navigate to sections that should have error rows
  // E.g., expired licenses
  await page.goto('/admin/licenses');
  await expect(page.getByText('expired', { exact: false }).first()).toBeVisible();

  // E.g., stalled backups
  // Not sure the exact route, maybe /admin/backups or it's on the overview
  await page.goto('/admin/overview');
  // Usually overview has a feed or toast
  
  // Verify that an error toast or notification is present
  await expect(page.getByRole('alert').first()).toBeVisible();
});
