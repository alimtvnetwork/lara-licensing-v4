/**
 * Plan 18 Step 65 tests: preview admin.resellers.list handler.
 *
 * Verifies:
 *  - default seed returns >= 5 seeded resellers sorted by Name ASC.
 *  - Query filter matches Name and Slug case-insensitively.
 *  - Cursor pagination is a deterministic numeric offset.
 *  - empty seed returns zero rows with a null cursor.
 *  - error seed rejects with AuthForbidden (403) per INV-RM-06.
 */
import "fake-indexeddb/auto";
import { beforeEach, describe, expect, it } from "vitest";
import { resetAll } from "../src/lib/preview-store";
import { loadDefaultSeed } from "../src/lib/preview-seeds/default";
import resellersModule from "../src/lib/preview-fixtures/resellers";
import {
  clearPreviewHandlersForTest,
  dispatchPreview,
  type PreviewContext,
} from "../src/lib/preview-transport";
import { ApiErrorCodeType, LaraApiError } from "../src/lib/lara-api-error";
import type { OperationId } from "../src/generated/api/operations";

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
    RequestId: `req-rsl-${Math.random().toString(16).slice(2, 8)}`,
  };
}

describe("preview-fixtures resellers (Plan 18 Step 65)", () => {
  beforeEach(async () => {
    clearPreviewHandlersForTest();
    await resetAll();
    resellersModule.register();
  });

  it("returns at least five seeded resellers sorted by Name ASC", async () => {
    await loadDefaultSeed();
    const res = await dispatchPreview("admin.resellers.list", ctx({}));
    expect(res.Total).toBeGreaterThanOrEqual(5);
    const names = res.Items.map((r) => r.Name);
    expect(names).toEqual([...names].sort((a, b) => a.localeCompare(b)));
    expect(res.Cursor).toBeNull();
  });

  it("filters by Query against Name (case-insensitive)", async () => {
    await loadDefaultSeed();
    const res = await dispatchPreview("admin.resellers.list", ctx({ Query: "beta" }));
    expect(res.Total).toBe(1);
    expect(res.Items[0]?.Name).toBe("Beta Reseller LLC");
  });

  it("filters by Query against Slug (case-insensitive)", async () => {
    await loadDefaultSeed();
    const res = await dispatchPreview("admin.resellers.list", ctx({ Query: "ZETA-WHOLE" }));
    expect(res.Total).toBe(1);
    expect(res.Items[0]?.Slug).toBe("zeta-wholesale");
  });

  it("returns no rows for a non-matching Query", async () => {
    await loadDefaultSeed();
    const res = await dispatchPreview("admin.resellers.list", ctx({ Query: "no-such-partner" }));
    expect(res.Total).toBe(0);
    expect(res.Items).toEqual([]);
    expect(res.Cursor).toBeNull();
  });

  it("honours a numeric offset cursor", async () => {
    await loadDefaultSeed();
    const first = await dispatchPreview("admin.resellers.list", ctx({}));
    const second = await dispatchPreview("admin.resellers.list", ctx({ Cursor: "2" }));
    expect(second.Total).toBe(first.Total);
    expect(second.Items).toEqual(first.Items.slice(2));
  });

  it("returns an empty page when nothing is seeded", async () => {
    const res = await dispatchPreview("admin.resellers.list", ctx({}));
    expect(res.Total).toBe(0);
    expect(res.Items).toEqual([]);
    expect(res.Cursor).toBeNull();
  });

  it("rejects with AuthForbidden under the error seed", async () => {
    await loadDefaultSeed();
    await expect(
      dispatchPreview("admin.resellers.list", ctx({}, "error")),
    ).rejects.toBeInstanceOf(LaraApiError);
    await dispatchPreview("admin.resellers.list", ctx({}, "error")).catch((e: unknown) => {
      const err = e as LaraApiError;
      expect(err.errorCode).toBe(ApiErrorCodeType.AuthForbidden);
      expect(err.httpStatus).toBe(403);
    });
  });
});
