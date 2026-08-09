import { test, expect } from '@playwright/test';
import { loginAsDemo } from '../../helpers/demo-login';

test('Error category routes to correct toast tone', async ({ page }) => {
  test.info().annotations.push({ type: 'seed', description: 'error' });

  await loginAsDemo(page, 'SuperAdmin');

  // Trigger different categories and check the toast class
  // For e2e, we might trigger one that we know (e.g. DomainConflict or Internal)
  
  await page.goto('/admin/dashboard?scenario=error-internal');
  const alertInternal = page.getByRole('alert').first();
  await expect(alertInternal).toBeVisible();
  // Internal -> destructive
  await expect(alertInternal).toHaveClass(/destructive/);

  await page.goto('/admin/dashboard?scenario=error-validation');
  const alertVal = page.getByRole('alert').first();
  await expect(alertVal).toBeVisible();
  // Validation -> warning
  await expect(alertVal).toHaveClass(/warning/);
});
