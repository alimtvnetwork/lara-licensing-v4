import { Page, expect } from '@playwright/test';

export async function openBell(page: Page) {
  const bell = page.getByTestId('notification-bell');
  await expect(bell).toBeVisible();
  await bell.click();
  const drawer = page.getByTestId('notification-drawer');
  await expect(drawer).toBeVisible();
}

export async function readEntries(page: Page) {
  const drawer = page.getByTestId('notification-drawer');
  // Return locators for all li items
  return drawer.locator('li');
}

export async function copyCorrelationIds(page: Page, entryIndex: number = 0) {
  const entries = await readEntries(page);
  const entry = entries.nth(entryIndex);
  const copyBtn = entry.getByRole('button', { name: /Copy correlation IDs/i });
  await expect(copyBtn).toBeVisible();
  await copyBtn.click();
  // Ensure clipboard reading works
  return await page.evaluate(() => navigator.clipboard.readText());
}
