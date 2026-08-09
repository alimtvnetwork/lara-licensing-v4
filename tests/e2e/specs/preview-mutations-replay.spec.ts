import { expect, test } from "@playwright/test";

/**
 * Plan 16 Step 94. Preview mutations replay E2E.
 *
 * Root cause this file protects against (one sentence): the preview
 * runtime's admin license write path (create -> update -> stale-If-Match
 * -> refetch -> delete) is unit-tested inside `src/lib/preview-fixtures/
 * licenses.ts` but never exercised end-to-end in a real browser, so a
 * regression in `apiClient.call` request routing, `preview-transport`
 * dispatch, or the `If-Match` -> 412 -> `PreconditionFailed` mapping
 * could ship and quietly break every optimistic write in preview mode.
 *
 * Contract:
 * - Boot in preview mode (`public/version.json` Mode:"preview").
 * - Wait for the `window.__LARA_PREVIEW__` bridge.
 * - Drive create -> update (v1 -> v2) -> stale update (v1) expecting
 *   `PreconditionFailed` -> re-read to get fresh Version -> delete with
 *   the fresh If-Match -> confirm list count returned to baseline (3).
 * - Every call uses the typed `apiClient.call<Op>` path so this exercises
 *   the same `callPreview -> applyPreviewScenario -> dispatchPreview`
 *   wiring used by preview production.
 *
 * Silent-failure guardrail: the stale-If-Match step MUST reject; if it
 * resolves, the test hard-fails naming the domain (`licenses.update`).
 */

const BOOT_TIMEOUT_MS = 10_000;

type ReplayResult = {
  CreatedVersion: number;
  UpdatedVersion: number;
  StaleErrorCode: string | null;
  RefetchedVersion: number;
  DeletedOk: boolean;
  FinalListCount: number;
};

async function waitForBridge(page: import("@playwright/test").Page): Promise<void> {
  await page.waitForFunction(
    () => Boolean((window as unknown as { __LARA_PREVIEW__?: unknown }).__LARA_PREVIEW__),
    undefined,
    { timeout: BOOT_TIMEOUT_MS },
  );
}

async function runReplay(page: import("@playwright/test").Page): Promise<ReplayResult> {
  return page.evaluate(async () => {
    const mod = await import("/src/lib/api-client.ts");
    const err = await import("/src/lib/lara-api-error.ts");
    const call = mod.apiClient.call.bind(mod.apiClient);

    const created = (await call("admin.licenses.create", {
      CustomerName: "Replay Customer",
      CustomerEmail: "replay@lara.test",
      ResellerId: null,
      Features: ["core"],
      MaxActivations: 1,
      ExpiresAt: null,
    })) as { Id: string; Version: number };

    const updated = (await call("admin.licenses.update", {
      Id: created.Id,
      IfMatch: String(created.Version),
      CustomerName: "Replay Customer v2",
    })) as { Version: number };

    let staleCode: string | null = null;
    try {
      await call("admin.licenses.update", {
        Id: created.Id,
        IfMatch: String(created.Version),
        CustomerName: "Replay Customer stale",
      });
    } catch (e) {
      staleCode = e instanceof err.LaraApiError ? e.errorCode : "non-lara-error";
    }

    const refetched = (await call("admin.licenses.show", { Id: created.Id })) as {
      Version: number;
    };

    let deletedOk = false;
    try {
      await call("admin.licenses.delete", {
        Id: created.Id,
        IfMatch: String(refetched.Version),
      });
      deletedOk = true;
    } catch {
      deletedOk = false;
    }

    const list = (await call("admin.licenses.list", {})) as { Items: unknown[] };
    return {
      CreatedVersion: created.Version,
      UpdatedVersion: updated.Version,
      StaleErrorCode: staleCode,
      RefetchedVersion: refetched.Version,
      DeletedOk: deletedOk,
      FinalListCount: list.Items.length,
    };
  });
}

test.describe("preview mutations replay (admin licenses)", () => {
  test.beforeEach(async ({ page }) => {
    await page.goto("/");
    await waitForBridge(page);
  });

  test("create -> update -> stale 412 -> refetch -> delete round-trips cleanly", async ({
    page,
  }) => {
    const r = await runReplay(page);
    expect(r.CreatedVersion, "create emits Version=1").toBe(1);
    expect(r.UpdatedVersion, "update bumps Version to 2").toBe(2);
    expect(r.StaleErrorCode, "stale If-Match must fail with PreconditionFailed").toBe(
      "PreconditionFailed",
    );
    expect(r.RefetchedVersion, "refetch surfaces post-update Version").toBe(2);
    expect(r.DeletedOk, "delete with fresh If-Match succeeds").toBe(true);
    expect(r.FinalListCount, "list count returns to canonical default seed baseline").toBe(3);
  });
});
