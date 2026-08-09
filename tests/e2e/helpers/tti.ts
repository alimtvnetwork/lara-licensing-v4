import { expect, type Page } from "@playwright/test";

/**
 * v0.675.0. TTI (time-to-interactive-ish) probe for preview admin flows.
 *
 * Root cause this helper addresses: performance regressions in preview
 * admin routes previously slipped through because no spec measured
 * per-route mount time. `measureRouteTti` gives cold-vs-warm baselines
 * a single, stable ready signal so regressions surface loudly instead
 * of showing up only in manual QA.
 *
 * "Ready" = `<h1>` from `PageHeader` is visible AND the
 * `data-testid="route-pending"` skeleton (see `RouteFallbacks`) is not
 * mounted. This intentionally matches what a user perceives as "the
 * page is usable", not a synthetic paint metric.
 */
export type TtiSample = { route: string; kind: "cold" | "warm"; ms: number };

const READY_TIMEOUT_MS = 15_000;

export async function measureRouteTti(
  page: Page,
  route: string,
  kind: "cold" | "warm",
): Promise<TtiSample> {
  const started = Date.now();
  await page.goto(route);
  await expect(page).toHaveURL(new RegExp(`${route}(\\/|$)`));
  await expect(page.locator('[data-testid="route-pending"]')).toHaveCount(0, {
    timeout: READY_TIMEOUT_MS,
  });
  await expect(page.getByRole("heading", { level: 1 }).first()).toBeVisible({
    timeout: READY_TIMEOUT_MS,
  });
  return { route, kind, ms: Date.now() - started };
}
