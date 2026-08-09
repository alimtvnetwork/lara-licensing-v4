/**
 * Plan 16 Step 45 tests: preview admin.quotas.* handlers.
 *
 * Verifies:
 *  - list returns all seeded quotas sorted by (ResellerName, FeatureCode).
 *  - list filters by ResellerId.
 *  - list paginates with cursor (page size 25).
 *  - update bumps Version and refreshes UpdatedAt on happy path.
 *  - update returns 412 PreconditionFailed on stale IfMatch.
 *  - update returns 422 ValidationFailed when Allocated < max(0, Used - Restored).
 *  - update returns 404 when Id missing.
 *  - update accepts Allocated == floor (boundary).
 *  - error seed rejects both ops with ValidationFailed (422).
 */
import "fake-indexeddb/auto";
import { beforeEach, describe, expect, it } from "vitest";
import { resetAll, read, write } from "../src/lib/preview-store";
import { loadDefaultSeed } from "../src/lib/preview-seeds/default";
import { loadErrorSeed } from "../src/lib/preview-seeds/error";
import quotasModule from "../src/lib/preview-fixtures/quotas";
import {
  clearPreviewHandlersForTest,
  dispatchPreview,
  type PreviewContext,
} from "../src/lib/preview-transport";
import { ApiErrorCodeType, LaraApiError } from "../src/lib/lara-api-error";
import type { OperationId } from "../src/generated/api/operations";
import type { Quota } from "../src/generated/api/schema";

function ctx<K extends OperationId>(
  Params: unknown,
  seed: "default" | "empty" | "error" = "default",
): PreviewContext<K> {
  return {
    Params: Params as never,
    Headers: {},
    Signal: new AbortController().signal,
    Seed: seed,
    Scenario: null,
    RequestId: `req-quo-${Math.random().toString(16).slice(2, 8)}`,
  };
}

describe("preview-fixtures quotas (Plan 16 Step 45)", () => {
  beforeEach(async () => {
    clearPreviewHandlersForTest();
    await resetAll();
    quotasModule.register();
  });

  it("list returns seeded quotas sorted deterministically", async () => {
    await loadDefaultSeed();
    const res = await dispatchPreview("admin.quotas.list", ctx({}));
    expect(res.Total).toBe(3);
    expect(res.Items.map((q) => q.FeatureCode)).toEqual([
      "addon.exports",
      "addon.sso",
      "core.reports",
    ]);
  });

  it("list filters by ResellerId", async () => {
    await loadDefaultSeed();
    const res = await dispatchPreview(
      "admin.quotas.list",
      ctx({ ResellerId: "does-not-exist" }),
    );
    expect(res.Total).toBe(0);
    expect(res.Items).toEqual([]);
  });

  it("list paginates with Cursor (page size 25)", async () => {
    await loadDefaultSeed();
    // Seed 27 additional quotas to force a second page.
    const base = (await read<Quota>("quotas", "01H0000000000000000QUOTA1"))!;
    for (let i = 0; i < 27; i++) {
      const id = `01H0000000000000000FILL${String(i).padStart(3, "0")}`;
      await write<Quota>("quotas", id, { ...base, Id: id, FeatureCode: `fill.${String(i).padStart(3, "0")}` });
    }
    const page1 = await dispatchPreview("admin.quotas.list", ctx({}));
    expect(page1.Items).toHaveLength(25);
    expect(page1.Cursor).toBe("25");
    const page2 = await dispatchPreview("admin.quotas.list", ctx({ Cursor: page1.Cursor }));
    expect(page2.Items).toHaveLength(30 - 25);
    expect(page2.Cursor).toBeNull();
  });

  it("update bumps Version and refreshes UpdatedAt on happy path", async () => {
    await loadDefaultSeed();
    const before = (await read<Quota>("quotas", "01H0000000000000000QUOTA1"))!;
    const res = await dispatchPreview("admin.quotas.update", ctx({
      Id: before.Id, IfMatch: String(before.Version), Allocated: 200,
    }));
    expect(res.Allocated).toBe(200);
    expect(res.Version).toBe(before.Version + 1);
    expect(new Date(res.UpdatedAt).getTime()).toBeGreaterThanOrEqual(new Date(before.UpdatedAt).getTime());
  });

  it("update returns 412 PreconditionFailed on stale IfMatch", async () => {
    await loadDefaultSeed();
    await expect(dispatchPreview("admin.quotas.update", ctx({
      Id: "01H0000000000000000QUOTA1", IfMatch: "999", Allocated: 200,
    }))).rejects.toMatchObject({
      errorCode: ApiErrorCodeType.PreconditionFailed,
      httpStatus: 412,
    });
  });

  it("update rejects Allocated below max(0, Used - Restored)", async () => {
    await loadDefaultSeed();
    // QUOTA2 has Used=20, Restored=0, so floor=20. Attempt 19.
    const q = (await read<Quota>("quotas", "01H0000000000000000QUOTA2"))!;
    await expect(dispatchPreview("admin.quotas.update", ctx({
      Id: q.Id, IfMatch: String(q.Version), Allocated: 19,
    }))).rejects.toMatchObject({
      errorCode: ApiErrorCodeType.ValidationFailed,
      httpStatus: 422,
    });
  });

  it("update accepts Allocated == floor (boundary)", async () => {
    await loadDefaultSeed();
    // QUOTA1: Used=42, Restored=3, floor=39.
    const q = (await read<Quota>("quotas", "01H0000000000000000QUOTA1"))!;
    const res = await dispatchPreview("admin.quotas.update", ctx({
      Id: q.Id, IfMatch: String(q.Version), Allocated: 39,
    }));
    expect(res.Allocated).toBe(39);
  });

  it("update returns 404 when Id missing", async () => {
    await loadDefaultSeed();
    await expect(dispatchPreview("admin.quotas.update", ctx({
      Id: "01H0000000000000000MISSING", IfMatch: "1", Allocated: 10,
    }))).rejects.toMatchObject({ httpStatus: 404 });
  });

  it("error seed rejects both list and update", async () => {
    await loadErrorSeed();
    await expect(dispatchPreview("admin.quotas.list", ctx({}, "error"))).rejects.toBeInstanceOf(LaraApiError);
    await expect(dispatchPreview("admin.quotas.update", ctx({
      Id: "any", IfMatch: "1", Allocated: 1,
    }, "error"))).rejects.toBeInstanceOf(LaraApiError);
  });
});
