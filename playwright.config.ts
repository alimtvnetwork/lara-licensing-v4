import { defineConfig, devices } from "@playwright/test";

/**
 * Plan 10 step 26. Root Playwright config for full-stack e2e coverage.
 *
 * Root cause this file addresses (one sentence): the repo had no
 * Playwright entry point at all, so every browser-level assurance in
 * Plan 10 (auth login, register bootstrap, portal serial lookup, etc.)
 * was unimplementable and the four planned CI workflows had nothing
 * to invoke.
 *
 * Contract:
 * - baseURL comes from `E2E_BASE_URL` (CI sets this to the
 *   frontend preview URL; local dev falls back to Vite's default).
 * - Three projects mirror the browsers we ship against.
 * - `screenshot: "only-on-failure"` + `trace: "on-first-retry"` give
 *   post-mortem signal without ballooning artifact size on green runs.
 * - `reporter` combines an HTML report (uploaded from CI) and the
 *   `github` reporter so annotations surface inline on PRs.
 *
 * Specs land under `tests/e2e/specs/` in a follow-up step; keeping
 * `testDir` pointed there now avoids a second config edit.
 */
export default defineConfig({
  testDir: "./tests/e2e/specs",
  fullyParallel: true,
  forbidOnly: Boolean(process.env.CI),
  retries: process.env.CI ? 2 : 0,
  workers: process.env.CI ? 2 : undefined,
  reporter: [
    ["html", { open: "never" }],
    ["github"],
    // Plan 10 step 45. JUnit XML fed to mikepenz/action-junit-report
    // in .github/workflows/junit-annotations.yml so failing specs
    // surface as line-level PR annotations instead of requiring an
    // artifact download.
    ["junit", { outputFile: "test-results/junit.xml" }],
  ],
  timeout: 30_000,
  expect: { timeout: 5_000 },
  use: {
    baseURL: process.env.E2E_BASE_URL ?? "http://localhost:8080",
    trace: "on-first-retry",
    screenshot: "only-on-failure",
    video: "retain-on-failure",
  },
  projects: [
    { name: "chromium", use: { ...devices["Desktop Chrome"] } },
    { name: "firefox", use: { ...devices["Desktop Firefox"] } },
    { name: "webkit", use: { ...devices["Desktop Safari"] } },
  ],
});
