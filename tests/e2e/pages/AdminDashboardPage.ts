import type { Page } from "@playwright/test";
import { expect } from "@playwright/test";

/**
 * Page Object for `/admin` (post-login landing). Verifies the shell
 * rendered by `src/routes/_authenticated/admin.index.tsx` and the
 * `AppShell` topbar (`ProfileMenu` + `RoleChip`).
 */
export class AdminDashboardPage {
  constructor(private readonly page: Page) {}

  async expectLoaded(): Promise<void> {
    await this.page.waitForURL(/\/admin(\/|$)/);
    await expect(
      this.page.getByRole("navigation", { name: /admin/i }).first(),
    ).toBeVisible();
  }
}
