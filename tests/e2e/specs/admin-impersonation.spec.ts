import { expect, test } from "../fixtures/lara-auth";

/**
 * Plan 10 step 37. Admin impersonation banner + end-session smoke.
 *
 * Root cause locked: the impersonation lifecycle is split across
 * `src/lib/lara-impersonation.ts` (`startImpersonation` line 43-59
 * persists an `ImpersonationSessionEnvelope` under
 * `LicensingPortal.ActiveImpersonation`; `endImpersonation` line 61-76
 * POSTs `/Impersonation/End` with `{EndReason:"OperatorEnded"}` +
 * Idempotency-Key and clears storage on success) and
 * `src/components/impersonation-banner.tsx` (banner reads storage on
 * mount, renders sticky at `--z-topbar`, "Return to Admin" button at
 * line 60-68 calls `handleEnd`). No e2e proves that (a) the banner
 * actually mounts on every _authenticated route when the storage key
 * is populated and (b) "Return to Admin" wires to `POST
 * /Impersonation/End` with the correct EndReason enum, so a regression
 * silently leaves a stale impersonation record past logout, or worse,
 * fails to end the server session while clearing the client record.
 *
 * Strategy: seed the storage key after signing in as Admin (avoids
 * needing the full start flow with a target user detail route). Stub
 * `POST **\/Impersonation/End`, click Return to Admin, assert (i) the
 * request body is `{EndReason:"OperatorEnded"}`, (ii) an Idempotency-Key
 * header is present, (iii) the storage key is cleared, (iv) the banner
 * disappears on next storage read.
 */
const RESPONSE_HEADERS = { "Content-Type": "application/json" } as const;

const IMPERSONATION_ENVELOPE = {
  SessionId: "11111111-2222-3333-4444-555555555555",
  ImpersonatorUserId: 1,
  TargetUserId: 4242,
  Kind: "Impersonation",
  // 25 minutes in the future so the countdown renders a positive value.
  ExpiresAt: new Date(Date.now() + 25 * 60 * 1000).toISOString(),
};

function endEnvelope() {
  return {
    Status: { IsSuccess: true, Code: 200, Message: "OK" },
    Attributes: { RequestId: "e2e-imp-end", RequestedAt: new Date().toISOString() },
    Results: [
      {
        SessionId: IMPERSONATION_ENVELOPE.SessionId,
        EndedAt: new Date().toISOString(),
        EndReason: "OperatorEnded",
      },
    ],
  };
}

test.describe("Admin impersonation banner smoke", () => {
  test("Banner renders from storage and Return to Admin ends the session", async ({
    signInAsAdmin,
    page,
  }) => {
    let endBody: unknown = null;
    let endIdemKey: string | undefined;
    let endMethod: string | null = null;
    await page.route("**/Impersonation/End", async (route) => {
      endMethod = route.request().method();
      if (endMethod !== "POST") return route.fallback();
      endBody = route.request().postDataJSON();
      endIdemKey = route.request().headers()["idempotency-key"];
      return route.fulfill({
        status: 200,
        headers: RESPONSE_HEADERS,
        body: JSON.stringify(endEnvelope()),
      });
    });

    await signInAsAdmin();

    // Seed the storage key that <ImpersonationBanner /> reads on mount.
    // This matches ACTIVE_STORAGE_KEY in src/lib/lara-impersonation.ts line 17.
    await page.evaluate((envelope) => {
      window.localStorage.setItem(
        "LicensingPortal.ActiveImpersonation",
        JSON.stringify(envelope),
      );
    }, IMPERSONATION_ENVELOPE);

    // Reload so the _authenticated layout re-mounts and picks up the record.
    await page.goto("/admin");
    await page.waitForURL(/\/admin/);

    // Banner is visible with the target user id and countdown copy.
    const banner = page.locator('[data-shell-region="impersonation-banner"]');
    await expect(banner).toBeVisible({ timeout: 10_000 });
    await expect(banner).toContainText(`Impersonating user #${IMPERSONATION_ENVELOPE.TargetUserId}`);
    await expect(banner.getByText(/Ends in \d+m \d{2}s/)).toBeVisible();

    // End the session.
    await banner.getByRole("button", { name: /Return to Admin/ }).click();

    // Banner disappears once endImpersonation clears the storage key.
    await expect(banner).toHaveCount(0, { timeout: 10_000 });

    // Storage evidence: key is gone.
    const stored = await page.evaluate(() =>
      window.localStorage.getItem("LicensingPortal.ActiveImpersonation"),
    );
    expect(stored).toBeNull();

    // Wire evidence: correct method, body, and idempotency header.
    expect(endMethod).toBe("POST");
    expect(endBody).toEqual({ EndReason: "OperatorEnded" });
    expect(endIdemKey).toMatch(/^[0-9a-f-]{36}$/i);
  });
});
