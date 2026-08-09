import { test, expect } from '@playwright/test';
import { loginAsDemo } from '../../helpers/demo-login';

test.use({ viewport: { width: 1280, height: 1800 } });

test('Deleting the last SuperAdmin shows friendly DomainConflict error', async ({ page }) => {
  test.info().annotations.push({ type: 'seed', description: 'default' });

  await loginAsDemo(page, 'SuperAdmin');

  await page.goto('/admin/users');

  // Attempt to delete the only SuperAdmin
  // Assuming there's a delete button for the current user
  const deleteBtn = page.getByRole('button', { name: /Delete/i }).first();
  await expect(deleteBtn).toBeVisible();
  await deleteBtn.click();

  const confirmDeleteBtn = page.getByRole('button', { name: /Confirm/i });
  await expect(confirmDeleteBtn).toBeVisible();
  await confirmDeleteBtn.click();

  // Dialog should surface friendly copy from src/copy/errors.ts
  // Usually this translates to "Cannot delete the last administrator" or similar
  await expect(page.getByText('Cannot remove the last administrator', { exact: false })).toBeVisible();
  
  // Or it shows up in a toast
  await expect(page.getByRole('alert').filter({ hasText: /DomainConflict|administrator/i })).toBeVisible();
});
