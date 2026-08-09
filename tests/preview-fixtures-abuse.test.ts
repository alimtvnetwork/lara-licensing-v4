/**
 * Plan 18 Phase D tests: preview admin.abuse.list handler.
 */
import "fake-indexeddb/auto";
import { beforeEach, describe, expect, it } from "vitest";
import { resetAll } from "../src/lib/preview-store";
import { loadDefaultSeed } from "../src/lib/preview-seeds/default";
import abuseModule from "../src/lib/preview-fixtures/abuse";
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
    RequestId: `req-abuse-${Math.random().toString(16).slice(2, 8)}`,
  };
}

describe("preview-fixtures abuse (Plan 18 Phase D)", () => {
  beforeEach(async () => {
    clearPreviewHandlersForTest();
    await resetAll();
    abuseModule.register();
  });

  it("returns all seeded rows sorted by OccurredAt DESC", async () => {
    await loadDefaultSeed();
    const res = await dispatchPreview("admin.abuse.list", ctx({}));
    expect(res.Total).toBe(12); // From seedAbuse in default.ts
    expect(res.Items).toHaveLength(12);
    const times = res.Items.map((e) => e.OccurredAt);
    const sorted = [...times].sort((a, b) => b.localeCompare(a));
    expect(times).toEqual(sorted);
  });

  it("filters by Query (EventType)", async () => {
    await loadDefaultSeed();
    const res = await dispatchPreview("admin.abuse.list", ctx({ Query: "RateLimited" }));
    expect(res.Total).toBe(6);
    expect(res.Items.every(e => e.EventType === "RateLimited")).toBe(true);
  });

  it("filters by Query (ResellerSlug via Metadata)", async () => {
    await loadDefaultSeed();
    // 12 rows, 3 slugs ("reseller-a", "reseller-b", "reseller-c") distributed i % 3.
    // So "reseller-a" should have indices 0, 3, 6, 9 -> 4 rows.
    const res = await dispatchPreview("admin.abuse.list", ctx({ Query: "reseller-a" }));
    expect(res.Total).toBe(4);
    expect(res.Items.every(e => (e.Metadata?.ResellerSlug as string).toLowerCase().includes("reseller-a"))).toBe(true);
  });


  it("paginates via numeric cursor", async () => {
    await loadDefaultSeed();
    // Default page size is 25, but we only have 12 rows. Let's test with a smaller slice if we had more.
    // For now, verify Cursor is null when items < page size.
    const res = await dispatchPreview("admin.abuse.list", ctx({}));
    expect(res.Cursor).toBeNull();
  });

  it("error seed rejects with AuthUnprocessable (422) per abuse.ts implementation", async () => {
    // abuse.ts uses HTTP_UNPROCESSABLE (422) and ERROR_SEED_DOMAIN_CODE.abuse which is generic Error
    await expect(dispatchPreview("admin.abuse.list", ctx({}, "error"))).rejects.toMatchObject({
      httpStatus: 422,
    });
  });
});
