import { Page, expect } from '@playwright/test';

export async function loginAsDemo(page: Page, role: string = 'SuperAdmin') {
  await page.goto('/admin/login');
  
  // Wait for the DemoLoginPanel to be visible
  const demoPanel = page.getByTestId('demo-login-panel');
  await expect(demoPanel).toBeVisible();

  // Click the chip for the given role
  await demoPanel.getByRole('button', { name: new RegExp(role, 'i') }).click();

  // Ensure fields are populated (password is usually standard, we just submit)
  await page.getByRole('button', { name: /Sign in/i }).click();

  // Assert successful navigation
  await expect(page).toHaveURL(/\/admin\/overview/);
}
