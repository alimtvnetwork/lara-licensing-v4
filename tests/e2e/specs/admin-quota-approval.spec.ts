import { expect, test } from "../fixtures/lara-auth";

/**
 * Plan 10 step 36. Admin quota approval smoke.
 *
 * Root cause locked: the Pending quota-request approve/deny path lives
 * only in `src/components/admin/admin-quota-requests-data-table.tsx`
 * (RowActions at line 286, PendingActions two-stage confirm at 295-455,
 * approve mutation at 307-320, deny mutation at 322-336) and calls
 * `approveQuotaRequest` / `denyQuotaRequest` from
 * `src/lib/lara-quota.ts` (lines 242-289) with the mandatory
 * `?ResellerSlug=<slug>` query parameter that binds the request to the
 * correct shard. No e2e proves the LineageBadge two-stage confirm plus
 * the shard-binding query parameter reach the wire, so a regression
 * silently mis-shards approvals to the wrong reseller tenant.
 *
 * Strategy: stub `GET /Admin/QuotaRequests/All` with one Pending row,
 * then stub `POST .../Approve` and `.../Deny` to capture the outgoing
 * URL and Idempotency-Key. UI evidence: LineageBadge renders inside
 * the confirm dialog before Confirm approve/deny fires.
 */
const RESPONSE_HEADERS = { "Content-Type": "application/json" } as const;

function envelope<T>(results: T[]) {
  return {
    Status: { IsSuccess: true, Code: 200, Message: "OK" },
    Attributes: { RequestId: "e2e-admin-quota", RequestedAt: new Date().toISOString() },
    Results: results,
  };
}

const PENDING_ROW = {
  QuotaRequestId: 9001,
  ResellerId: 42,
  ResellerSlug: "acme-eu",
  LicenseCategoryId: 3,
  LicenseTierId: 2,
  RequestedDelta: 25,
  Status: "Pending",
  SubmittedByUserId: 7,
  SubmittedAt: "2026-07-19T10:00:00.000Z",
};

const APPROVED_ROW = { ...PENDING_ROW, Status: "Approved", ApprovedDelta: 25, DecidedByUserId: 1, DecidedAt: "2026-07-19T10:05:00.000Z" };

test.describe("Admin quota approval smoke", () => {
  test("Approve: two-stage confirm sends ResellerSlug + Idempotency-Key", async ({
    signInAsAdmin,
    page,
  }) => {
    await page.route("**/Admin/QuotaRequests/All**", async (route) =>
      route.fulfill({
        status: 200,
        headers: RESPONSE_HEADERS,
        body: JSON.stringify(envelope([PENDING_ROW])),
      }),
    );

    let approveUrl: string | null = null;
    let approveIdemKey: string | undefined;
    await page.route(`**/QuotaRequests/${PENDING_ROW.QuotaRequestId}/Approve**`, async (route) => {
      if (route.request().method() !== "POST") return route.fallback();
      approveUrl = route.request().url();
      approveIdemKey = route.request().headers()["idempotency-key"];
      return route.fulfill({
        status: 200,
        headers: RESPONSE_HEADERS,
        body: JSON.stringify(envelope([APPROVED_ROW])),
      });
    });

    await signInAsAdmin();
    await page.goto("/admin/quota-requests");
    await page.waitForURL(/\/admin\/quota-requests/);
    await expect(page.getByRole("heading", { name: "Quota requests" })).toBeVisible();

    // Row is present, initial actions render.
    await expect(page.getByRole("button", { name: "Approve" })).toBeVisible({ timeout: 10_000 });
    await page.getByRole("button", { name: "Approve" }).click();

    // Two-stage confirm: LineageBadge + Confirm approve button appear.
    await expect(page.getByRole("button", { name: /Confirm approve/ })).toBeVisible();
    await expect(page.getByText(/Approve \+25 for acme-eu/)).toBeVisible();

    await page.getByRole("button", { name: /Confirm approve/ }).click();

    // Wait for the row status to flip to Approved after invalidation.
    await expect(page.getByRole("button", { name: "Approve" })).toHaveCount(0, { timeout: 10_000 });

    // Contract evidence: shard binding + idempotency reached the wire.
    expect(approveUrl).not.toBeNull();
    expect(approveUrl!).toContain("ResellerSlug=acme-eu");
    expect(approveIdemKey).toMatch(/qr-approve-9001-[0-9a-f-]{36}/i);
  });

  test("Deny: reason required, Confirm deny stays disabled until filled", async ({
    signInAsAdmin,
    page,
  }) => {
    await page.route("**/Admin/QuotaRequests/All**", async (route) =>
      route.fulfill({
        status: 200,
        headers: RESPONSE_HEADERS,
        body: JSON.stringify(envelope([PENDING_ROW])),
      }),
    );

    let denyBody: unknown = null;
    let denyUrl: string | null = null;
    await page.route(`**/QuotaRequests/${PENDING_ROW.QuotaRequestId}/Deny**`, async (route) => {
      if (route.request().method() !== "POST") return route.fallback();
      denyUrl = route.request().url();
      denyBody = route.request().postDataJSON();
      return route.fulfill({
        status: 200,
        headers: RESPONSE_HEADERS,
        body: JSON.stringify(envelope([{ ...PENDING_ROW, Status: "Denied", DenialReason: "Over quota this cycle." }])),
      });
    });

    await signInAsAdmin();
    await page.goto("/admin/quota-requests");
    await page.getByRole("button", { name: "Deny" }).click();

    const confirmDeny = page.getByRole("button", { name: /Confirm deny/ });
    // Reason gate: button is disabled until at least one char is typed.
    await expect(confirmDeny).toBeDisabled();
    await page.getByPlaceholder("Reason shown in audit log").fill("Over quota this cycle.");
    await expect(confirmDeny).toBeEnabled();
    await confirmDeny.click();

    await expect(page.getByRole("button", { name: "Deny" })).toHaveCount(0, { timeout: 10_000 });

    expect(denyUrl).not.toBeNull();
    expect(denyUrl!).toContain("ResellerSlug=acme-eu");
    expect(denyBody).toMatchObject({ Reason: "Over quota this cycle." });
  });

  test("Row reappears on rollback when preview seeds error scenario", async ({ signInAsAdmin, page }) => {
    test.info().annotations.push({ type: 'seed', description: 'default' });
    
    // We do NOT mock the network here; we let the preview transport handle it.
    // We add the scenario parameter to force the error
    await signInAsAdmin();
    await page.goto("/admin/quota-requests?scenario=quotas-approve-500");
    await page.waitForURL(/\/admin\/quota-requests/);

    // Assuming default seed has at least one pending quota request
    const approveBtn = page.getByRole("button", { name: "Approve" }).first();
    await expect(approveBtn).toBeVisible();

    await approveBtn.click();
    const confirmBtn = page.getByRole("button", { name: /Confirm approve/ });
    await expect(confirmBtn).toBeVisible();
    await confirmBtn.click();

    // The row should optimistically disappear or show loading
    // Then it should reappear because the server (preview transport) returned 500
    // Wait for the toast to appear indicating error
    await expect(page.getByRole("alert")).toBeVisible();
    
    // The button should be visible again (rollback)
    await expect(approveBtn).toBeVisible();
  });
});
