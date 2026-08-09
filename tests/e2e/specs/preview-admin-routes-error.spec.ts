import { test, expect } from "../fixtures/lara-auth";

/**
 * v0.674.0. Sibling to `preview-admin-routes.spec.ts` (default/empty seeds).
 *
 * Invariant (INV-RM-04 + INV-RM-06 + Plan 11 error-contract axis): under
 * the preview `error` seed every top-level admin route MUST fail loudly
 * via its route-scoped `RouteErrorState` (`data-testid="route-error"`,
 * headline "<title> could not be loaded"), and MUST NOT bubble up to the
 * router's generic `StateError` banner ("Something went wrong on our
 * side."). The scoped panel is the StateCard-shaped fallback that carries
 * `operationId` / `requestId` correlation; the generic banner is only for
 * unhandled router faults and would erase that correlation.
 *
 * Any route that hoists an error-seed failure into the generic banner is
 * a regression against Plan 11 and this spec is designed to catch it.
 */

const ADMIN_ROUTES = [
  "/admin",
  "/admin/resellers",
  "/admin/users",
  "/admin/audit",
  "/admin/quotas",
  "/admin/quota-requests",
  "/admin/app-updates",
  "/admin/serials",
] as const;

const GENERIC_STATE_ERROR_HEADLINE = "Something went wrong on our side.";

async function loadErrorSeed(page: import("@playwright/test").Page): Promise<void> {
  await page.evaluate(async () => {
    const store = await import("/src/lib/preview-store.ts");
    await store.resetAll();
    const mod = await import("/src/lib/preview-seeds/error.ts");
    await mod.loadErrorSeed();
  });
}

test.describe("preview admin routes (seed=error) never surface the generic StateError banner", () => {
  test("scoped route-error renders, generic banner does not", async ({
    page,
    signInAsAdmin,
  }) => {
    await signInAsAdmin();
    await loadErrorSeed(page);

    for (const route of ADMIN_ROUTES) {
      await page.goto(route);
      await expect(page).toHaveURL(new RegExp(`${route}(\\/|$)`));

      // Generic router-level banner must never appear: that would mean the
      // error escaped the route's own errorComponent.
      await expect
        .poll(
          async () =>
            await page
              .getByRole("heading", { name: GENERIC_STATE_ERROR_HEADLINE })
              .count(),
          {
            timeout: 8_000,
            message: `Generic StateError banner surfaced on ${route} under seed=error`,
          },
        )
        .toBe(0);

      // And the route MUST render its scoped StateCard-shaped error panel,
      // proving the failure was handled by the route's own errorComponent
      // (RouteErrorState) rather than swallowed / hidden.
      await expect(
        page.getByTestId("route-error").first(),
        `Route ${route} did not render a scoped route-error panel under seed=error`,
      ).toBeVisible({ timeout: 8_000 });
    }
  });
});
