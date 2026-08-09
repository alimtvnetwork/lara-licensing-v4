import { test, expect } from "../fixtures/lara-auth";

/**
 * Plan 17 Step 28. Preview admin routes render green under default/empty seeds.
 *
 * Root invariant (INV-RM-04 + INV-RM-06): every top-level admin route must
 * mount without the route-shell StateError banner ("Something went wrong on
 * our side.") when the preview seed is `default` or `empty`. Only the
 * `error` seed is allowed to surface that banner (asserted separately in
 * `preview-admin-routes-error.spec.ts`). Any handler/registration/regression
 * that drops a route into StateError under the happy-path seeds is caught
 * here rather than silently in production preview demos.
 *
 * Reseed uses the same runtime path the settings drawer takes:
 * `preview-store.resetAll()` -> `loadDefaultSeed()` / `loadEmptySeed()`.
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

const STATE_ERROR_HEADLINE = "Something went wrong on our side.";

type Seed = "default" | "empty";

async function reseed(page: import("@playwright/test").Page, seed: Seed): Promise<void> {
  await page.evaluate(async (which) => {
    const store = await import("/src/lib/preview-store.ts");
    await store.resetAll();
    if (which === "default") {
      const mod = await import("/src/lib/preview-seeds/default.ts");
      await mod.loadDefaultSeed();
    } else {
      const mod = await import("/src/lib/preview-seeds/empty.ts");
      await mod.loadEmptySeed();
    }
  }, seed);
}

for (const seed of ["default", "empty"] as const) {
  test.describe(`preview admin routes render green (seed=${seed})`, () => {
    test(`no StateError banner on any admin route (seed=${seed})`, async ({
      page,
      signInAsAdmin,
    }) => {
      await signInAsAdmin();
      await reseed(page, seed);

      for (const route of ADMIN_ROUTES) {
        await page.goto(route);
        await expect(page).toHaveURL(new RegExp(`${route}(\\/|$)`));
        // The StateError h1 is unique to route-shell failure. Assert it
        // is never visible under a happy-path seed. Poll briefly so a
        // loading skeleton doesn't race the check.
        await expect
          .poll(
            async () => await page.getByRole("heading", { name: STATE_ERROR_HEADLINE }).count(),
            {
              timeout: 8_000,
              message: `StateError surfaced on ${route} under seed=${seed}`,
            },
          )
          .toBe(0);
      }
    });
  });
}
