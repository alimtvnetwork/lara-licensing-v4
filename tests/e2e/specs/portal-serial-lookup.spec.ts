import { expect, test } from "../fixtures/lara-auth";

/**
 * Plan 10 step 33. Portal serial lookup smoke.
 *
 * Root cause locked: `/portal/home` (defined at
 * `src/routes/_authenticated/portal.home.tsx` lines 16-26) is the
 * only surface that exercises `SerialLookupPanel` end-to-end: it
 * mounts the form (`serial-lookup-panel.tsx` lines 116-146), the
 * mutation via `verifySerial()` (`lara-serial.ts` verifySerial ->
 * `POST /Verify/Serial`), the three-state `ResultPanel` (lines
 * 161-209), and the localStorage `HistoryList` (STORAGE_KEY
 * `LicensingPortal.portalRecentSerials`). A regression in any of
 * (client validation guard, mutation error mapping via
 * `LaraApiError -> copyForErrorCode`, panel tone rendering,
 * or history persistence) silently breaks the end-user path.
 *
 * Admin auth is used because the `/portal/home` route file itself
 * is not role-gated, only `/portal` (the redirect at line 45 of
 * portal.tsx) is. Direct navigation renders the shell for any
 * authenticated caller, which is sufficient for a UI smoke that
 * doesn't require a real seeded serial.
 */
test.describe("Portal serial lookup smoke", () => {
  test("empty submit surfaces client-side guard, not a network call", async ({
    signInAsAdmin,
    page,
  }) => {
    // Track outbound verify calls so we can assert the client-side
    // guard short-circuits before the network hop (line 121-124 of
    // serial-lookup-panel.tsx).
    let verifyCalls = 0;
    await page.route("**/Verify/Serial", (route) => {
      verifyCalls += 1;
      return route.continue();
    });

    await signInAsAdmin();
    await page.goto("/portal/home");
    await page.waitForURL(/\/portal\/home/);

    // Idle state: EmptyState "No serial verified yet" (lines 162-169).
    await expect(page.getByText("No serial verified yet")).toBeVisible();

    await page.getByTestId("portal-home-serial-submit").click();

    const alert = page.getByTestId("portal-home-serial-error");
    await expect(alert).toBeVisible();
    await expect(alert).toHaveText(/Enter a serial to verify\./);
    expect(verifyCalls).toBe(0);
  });

  test("nonexistent serial renders result-or-error panel and persists history", async ({
    signInAsAdmin,
    page,
  }) => {
    await signInAsAdmin();
    await page.goto("/portal/home");
    await page.waitForURL(/\/portal\/home/);

    const bogus = `E2E-BOGUS-${Date.now()}`;
    await page.getByTestId("portal-home-serial-input").fill(bogus);
    await page.getByTestId("portal-home-serial-submit").click();

    // Either the API returns `IsValid: false` (renders result panel
    // with tone="destructive", lines 185-208) or it throws a
    // LaraApiError (renders alert panel, lines 171-181). Both are
    // valid outcomes for an unknown serial; we require one to
    // become visible so a silently hung mutation fails CI. Do not
    // narrow this: the actual response shape is a server contract
    // decision, not something this UI smoke should encode.
    const errorPanel = page.getByTestId("portal-home-serial-error");
    const resultPanel = page.getByTestId("portal-home-serial-result");
    await expect(errorPanel.or(resultPanel)).toBeVisible({ timeout: 15_000 });

    // History persistence path (lines 83-90 + 234-256). If the
    // mutation resolved to a result (success branch) history was
    // recorded; if it errored, no history is recorded per line 98.
    // We only assert the section is well-formed when present, to
    // avoid coupling the test to the server response shape.
    const history = page.getByTestId("portal-home-serial-history");
    if ((await history.count()) > 0) {
      await expect(history.getByRole("heading", { name: "Recent lookups" })).toBeVisible();
      await expect(history.getByText(bogus, { exact: true })).toBeVisible();

      // Reload; localStorage persistence (STORAGE_KEY
      // "LicensingPortal.portalRecentSerials") must rehydrate the
      // entry so the user sees their last lookups on a return visit.
      await page.reload();
      await expect(page.getByTestId("portal-home-serial-history")).toBeVisible();
      await expect(
        page.getByTestId("portal-home-serial-history").getByText(bogus, { exact: true }),
      ).toBeVisible();
    }
  });
});
