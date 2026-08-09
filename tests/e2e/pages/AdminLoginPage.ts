import type { Page } from "@playwright/test";
import { expect } from "@playwright/test";

/**
 * Page Object for `/admin/login`. Selectors are keyed to the stable
 * `id` attributes (`admin-email`, `admin-password`) rendered by
 * `src/routes/admin.login.tsx`. If those ids change, update this file,
 * do not sprinkle inline selectors across specs.
 */
export class AdminLoginPage {
  constructor(private readonly page: Page) {}

  async goto(): Promise<void> {
    await this.page.goto("/admin/login");
    await expect(this.page.locator("#admin-email")).toBeVisible();
  }

  async fillCredentials(email: string, password: string): Promise<void> {
    await this.page.locator("#admin-email").fill(email);
    await this.page.locator("#admin-password").fill(password);
  }

  async submit(): Promise<void> {
    await this.page.getByRole("button", { name: /sign in/i }).click();
  }

  async login(email: string, password: string): Promise<void> {
    await this.goto();
    await this.fillCredentials(email, password);
    await this.submit();
  }
}
