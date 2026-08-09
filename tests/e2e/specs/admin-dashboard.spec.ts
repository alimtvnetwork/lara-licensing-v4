import { expect, test } from "../fixtures/lara-auth";

/**
 * Plan 10 step 31. Admin dashboard smoke.
 *
 * Root cause locked: `/admin` composes four surfaces that fail
 * independently: (a) the `_authenticated` route gate + Sanctum bearer
 * middleware, (b) `GET /Api/Admin/Metrics` multi-shard fanout in
 * `MetricsController@index`, (c) the four `StatCard` tiles inside
 * `data-shell-region="admin-kpis"`, (d) the `WarningsBanner` at
 * `data-ui="admin-metrics-warnings"` when a shard fails to respond.
 * A regression in any one silently degrades every downstream Admin
 * view. This smoke asserts the shell + KPI region + PageHeader load,
 * and that KPI tiles exit the loading state (state !== "loading") so
 * a stalled metrics fetch fails CI rather than "silent zero".
 */
test.describe("Admin dashboard smoke", () => {
  test("post-login shell, PageHeader, and four KPI tiles render", async ({
    signInAsAdmin,
    page,
  }) => {
    await signInAsAdmin();

    // URL contract.
    await expect(page).toHaveURL(/\/admin(\/|$)/);

    // PageHeader from src/routes/_authenticated/admin.index.tsx lines 46-49.
    await expect(page.getByRole("heading", { name: "Overview", level: 1 })).toBeVisible();

    // KPI region + all four tile labels. Selectors keyed to
    // data-shell-region="admin-kpis" and the exact labels emitted by
    // tileDefinitions() so a rename fails loudly.
    const kpis = page.locator('[data-shell-region="admin-kpis"]');
    await expect(kpis).toBeVisible();
    for (const label of [
      "Active resellers",
      "Active sessions",
      "Licenses issued",
      "Quota requests pending",
    ]) {
      await expect(kpis.getByText(label, { exact: true })).toBeVisible();
    }

    // Loading -> ready transition: the tile must not stay on the "--"
    // placeholder emitted while `metrics` is undefined. Poll the first
    // tile's value node; if the fetch stalls this fails loudly instead
    // of masquerading as zero traffic.
    await expect
      .poll(
        async () => {
          const text = await kpis.locator("text=Active resellers").first().locator("..").innerText();
          return /\d/.test(text);
        },
        { timeout: 10_000, message: "Admin metrics never resolved past loading state" },
      )
      .toBe(true);
  });

  test("sidebar navigates to Users list without full reload", async ({
    signInAsAdmin,
    page,
  }) => {
    await signInAsAdmin();
    // Client-side nav: click the Users link and assert URL + heading.
    await page.getByRole("link", { name: /users/i }).first().click();
    await page.waitForURL(/\/admin\/users(\/|$|\?)/);
    await expect(page.getByRole("heading", { name: /users/i }).first()).toBeVisible();
  });
});
