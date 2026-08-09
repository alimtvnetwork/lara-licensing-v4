import type { Page } from "@playwright/test";
import { expect } from "@playwright/test";

/**
 * Page Object for `/register` (SuperAdmin bootstrap surface).
 * Selectors are keyed to accessible labels rendered by
 * `src/routes/register.tsx` (`Work email`, `Password`, `Create workspace`).
 * If those labels change, update this file, do not sprinkle inline
 * selectors across specs.
 */
export class RegisterPage {
  constructor(private readonly page: Page) {}

  async goto(): Promise<void> {
    await this.page.goto("/register");
    await expect(this.page.getByLabel("Work email")).toBeVisible();
  }

  async fillCredentials(email: string, password: string): Promise<void> {
    await this.page.getByLabel("Work email").fill(email);
    await this.page.getByLabel("Password", { exact: true }).fill(password);
  }

  async submit(): Promise<void> {
    await this.page.getByRole("button", { name: /create workspace/i }).click();
  }

  async register(email: string, password: string): Promise<void> {
    await this.goto();
    await this.fillCredentials(email, password);
    await this.submit();
  }
}
