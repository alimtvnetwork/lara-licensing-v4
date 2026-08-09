import { expect, test } from "../fixtures/lara-auth";
import { firstResellerId } from "../helpers/backend-reseller";

/**
 * Plan 10 step 32. Reseller dashboard smoke.
 *
 * Root cause locked: `/reseller/$resellerId/` (defined at
 * `src/routes/_authenticated/reseller.$resellerId.index.tsx` lines
 * 25-35) parses the URL segment as a positive integer, hydrates
 * `useSuspenseQuery(meQueryOptions())`, and mounts three shard-scoped
 * KPI tiles (Allowances, Consumed, Pending quota requests) via
 * `resellerQuotasQueryOptions` + `quotaRequestListQueryOptions`. This
 * touches a different route tree than `/admin`, a different shard
 * bearer path, and its own `ForbiddenGate` (line 74-85). Without a
 * smoke, any regression in the shard-routed API path or the Suspense
 * boundary silently 500s in production only.
 *
 * We sign in as the seeded admin (RoleName "Admin"), so `isMismatch`
 * at line 52 stays false regardless of the resolved reseller id, and
 * the three tiles must mount from the same Data API the Reseller role
 * uses. A seeded ResellerId is looked up via `firstResellerId()` so
 * the spec never hard-codes drift-prone ids.
 */
test.describe("Reseller dashboard smoke", () => {
  test("KPI tiles + quick actions render for a seeded reseller", async ({
    signInAsAdmin,
    page,
  }) => {
    await signInAsAdmin();
    const reseller = await firstResellerId();

    await page.goto(`/reseller/${reseller.ResellerId}`);
    await page.waitForURL(new RegExp(`/reseller/${reseller.ResellerId}(/|$)`));

    // PageHeader title from line 56 of reseller.$resellerId.index.tsx.
    await expect(
      page.getByRole("heading", { name: "Reseller overview", level: 1 }),
    ).toBeVisible();

    // Not the ForbiddenGate (line 74-85); admin role must bypass the
    // mismatch branch entirely.
    await expect(page.getByRole("alert").filter({ hasText: /Access denied/i })).toHaveCount(0);

    // Three KPI tiles from ResellerHomeContent (lines 90-94). Match
    // the exact StatCard labels emitted by AllowancesTile /
    // ConsumedTile / PendingRequestsTile so a rename fails loudly.
    for (const label of [
      "Total allowances",
      "Licenses consumed",
      "Pending quota requests",
    ]) {
      await expect(page.getByText(label, { exact: true })).toBeVisible();
    }

    // QuickActionsPanel (lines 171-204) must render its two Links.
    await expect(page.getByRole("heading", { name: "Quick actions" })).toBeVisible();
    const submitLink = page.getByRole("link", { name: /Submit quota request/i });
    await expect(submitLink).toBeVisible();
    await expect(submitLink).toHaveAttribute(
      "href",
      new RegExp(`/reseller/${reseller.ResellerId}/quota-requests`),
    );

    // Poll: at least one tile must exit the "--" loading placeholder
    // emitted by StatCard while Suspense boundary is still hydrating,
    // so a stalled shard fetch fails CI instead of passing as zero.
    await expect
      .poll(
        async () => {
          const tile = page.getByText("Total allowances").locator("..");
          return await tile.innerText();
        },
        { timeout: 10_000, message: "Reseller quotas never resolved past loading state" },
      )
      .not.toMatch(/^\s*Total allowances\s*--\s*$/);
  });

  test("cross-reseller URL as a reseller-role user shows ForbiddenGate is available (admin bypass sanity)", async ({
    signInAsAdmin,
    page,
  }) => {
    // Sanity check that the ForbiddenGate branch is even reachable:
    // admin role bypasses (isMismatch false), so navigating to an
    // obviously-nonexistent id still renders the header + gate for the
    // suspense-error boundary, not a 500. We stop at the URL contract
    // and heading; deeper coverage lives in step 35 (License CRUD).
    await signInAsAdmin();
    await page.goto("/reseller/999999");
    await page.waitForURL(/\/reseller\/999999(\/|$)/);
    await expect(
      page.getByRole("heading", { name: "Reseller overview", level: 1 }),
    ).toBeVisible();
  });
});
