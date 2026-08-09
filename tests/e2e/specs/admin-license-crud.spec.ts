import { expect, test } from "../fixtures/lara-auth";

/**
 * Plan 10 step 35. Admin license CRUD smoke.
 *
 * Root cause locked: the license write triad lives across
 * `src/components/admin/license-issue-form.tsx` (POST /Licenses,
 * lines 105-213), `src/components/admin/license-detail-actions.tsx`
 * (PATCH /Licenses/{id} with If-Match at line 98-101, DELETE at
 * 129, PreconditionFailed conflict banner at 167-182), and
 * `src/lib/lara-license.ts` (createLicense line 86-96, updateLicense,
 * deleteLicense, ETag capture at 155-167). No spec proves the create
 * happy path or the 412 conflict recovery UI wires end-to-end, so a
 * regression in the If-Match round-trip added in v0.262 silently
 * corrupts inventory parity in production only.
 *
 * Strategy: stub every /Licenses endpoint via `page.route` so the
 * spec is deterministic and does not depend on seeded catalog/quota
 * state; the server contracts themselves are covered by Pest.
 */
const RESPONSE_HEADERS = { "Content-Type": "application/json" } as const;

function envelope<T>(result: T) {
  return {
    Status: { IsSuccess: true, Code: 200, Message: "OK" },
    Attributes: { RequestId: "e2e-admin-licenses", RequestedAt: new Date().toISOString() },
    Results: [result],
  };
}

function failEnvelope(errorCode: string, message: string, httpCode: number) {
  return {
    Status: { IsSuccess: false, Code: httpCode, Message: message },
    Attributes: {
      RequestId: "e2e-admin-licenses",
      RequestedAt: new Date().toISOString(),
      Error: { ErrorCode: errorCode, ErrorMessage: message },
    },
    Results: [],
  };
}

const SAMPLE_LICENSE = {
  LicenseId: 424242,
  LicenseCategoryId: 3,
  LicenseTierId: 2,
  EnvironmentId: 1,
  LicensePackageId: null,
  ResellerId: null,
  IssuedByUserId: 1,
  ProductVersion: "1.0.0",
  IsActive: true,
  IssuedAt: "2026-07-19T00:00:00.000Z",
  ExpiresAt: null,
  UserCount: null,
  MachineCount: null,
  IsSingleUse: false,
};

test.describe("Admin license CRUD smoke", () => {
  test("issue license: POST /Licenses success renders the issued panel", async ({
    signInAsAdmin,
    page,
  }) => {
    let postBody: unknown = null;
    let postHeaders: Record<string, string> | null = null;
    await page.route("**/Licenses", async (route) => {
      if (route.request().method() !== "POST") return route.fallback();
      postBody = route.request().postDataJSON();
      postHeaders = route.request().headers();
      return route.fulfill({
        status: 200,
        headers: RESPONSE_HEADERS,
        body: JSON.stringify(envelope(SAMPLE_LICENSE)),
      });
    });

    await signInAsAdmin();
    await page.goto("/admin/licenses/new");
    await page.waitForURL(/\/admin\/licenses\/new/);
    await expect(page.getByRole("heading", { name: "Issue license" })).toBeVisible();

    await page.getByLabel("ProductVersion *").fill("1.0.0");
    await page.getByRole("button", { name: "Issue license" }).click();

    // Success panel: "License #{LicenseId}" (line 147 of license-issue-form).
    await expect(page.getByRole("heading", { name: `License #${SAMPLE_LICENSE.LicenseId}` }))
      .toBeVisible({ timeout: 10_000 });

    // Contract sanity: POST body carries the closed-set ordinals from the
    // form and Idempotency-Key is present per spec/21-app/11 §08 (line 93).
    expect(postBody).toMatchObject({ ProductVersion: "1.0.0", IsSingleUse: false });
    expect(postHeaders?.["idempotency-key"]).toMatch(/[0-9a-f-]{36}/i);
  });

  test("update conflict: PATCH 412 renders the reload-latest banner", async ({
    signInAsAdmin,
    page,
  }) => {
    const licenseId = SAMPLE_LICENSE.LicenseId;
    const etag = '"license-v1"';

    await page.route(`**/Licenses/${licenseId}`, async (route) => {
      const method = route.request().method();
      if (method === "GET") {
        return route.fulfill({
          status: 200,
          headers: { ...RESPONSE_HEADERS, ETag: etag },
          body: JSON.stringify(envelope(SAMPLE_LICENSE)),
        });
      }
      if (method === "PATCH") {
        // If-Match round-trip evidence: the client MUST send the ETag we
        // gave it on GET (spec/21-app/11-api-contracts/09 §Request rules).
        expect(route.request().headers()["if-match"]).toBe(etag);
        return route.fulfill({
          status: 412,
          headers: RESPONSE_HEADERS,
          body: JSON.stringify(failEnvelope("PreconditionFailed", "Stale version.", 412)),
        });
      }
      return route.fallback();
    });

    await signInAsAdmin();
    await page.goto(`/admin/licenses/${licenseId}`);
    await page.waitForURL(new RegExp(`/admin/licenses/${licenseId}$`));
    await expect(page.getByRole("heading", { name: `License ${licenseId}` }))
      .toBeVisible({ timeout: 10_000 });

    // Trigger the save path with an unrelated ExpiresAt edit; PATCH stub
    // returns 412 so we can prove the conflict banner and Reload button
    // render (license-detail-actions lines 167-182). The confirm-revoke
    // path shares the same isConflict branch, so covering save is
    // sufficient to prove the UI wiring.
    await page.getByRole("button", { name: "Save changes" }).click();
    await expect(page.getByText("This license changed since you loaded it.", { exact: true }))
      .toBeVisible({ timeout: 10_000 });
    await expect(page.getByRole("button", { name: /Reload latest and retry/ })).toBeVisible();
  });
});
