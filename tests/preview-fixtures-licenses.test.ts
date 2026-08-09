/**
 * Plan 18 Step 67 tests: preview admin.licenses.list handler.
 */
import "fake-indexeddb/auto";
import { beforeEach, describe, expect, it } from "vitest";
import { resetAll } from "../src/lib/preview-store";
import { loadDefaultSeed } from "../src/lib/preview-seeds/default";
import licensesModule from "../src/lib/preview-fixtures/licenses";
import {
  clearPreviewHandlersForTest,
  dispatchPreview,
  type PreviewContext,
} from "../src/lib/preview-transport";
import { LaraApiError } from "../src/lib/lara-api-error";
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
    RequestId: `req-lic-${Math.random().toString(16).slice(2, 8)}`,
  };
}

describe("preview-fixtures licenses parity (Plan 18 Step 67)", () => {
  beforeEach(async () => {
    clearPreviewHandlersForTest();
    await resetAll();
    licensesModule.register();
  });

  it("returns at least six seeded licenses across statuses", async () => {
    await loadDefaultSeed();
    const res = await dispatchPreview<"admin.licenses.list">(
      "admin.licenses.list",
      ctx({ Cursor: null })
    );

    expect(res.Total).toBeGreaterThanOrEqual(6);
    expect(res.Items.length).toBeGreaterThanOrEqual(6);

    const statuses = new Set(res.Items.map(l => l.Status));
    expect(statuses.has("active")).toBe(true);
    expect(statuses.has("suspended")).toBe(true);
    expect(statuses.has("expired")).toBe(true);
  });

  it("filters by Status accurately", async () => {
    await loadDefaultSeed();
    const res = await dispatchPreview<"admin.licenses.list">(
      "admin.licenses.list",
      ctx({ Status: "active" })
    );

    expect(res.Items.every(l => l.Status === "active")).toBe(true);
    expect(res.Items.length).toBeGreaterThan(0);
  });

  it("filters by Query against CustomerEmail (case-insensitive)", async () => {
    await loadDefaultSeed();
    const res = await dispatchPreview<"admin.licenses.list">(
      "admin.licenses.list",
      ctx({ Query: "ACME.TEST" })
    );

    expect(res.Items.length).toBeGreaterThan(0);
    expect(res.Items.every(l => l.CustomerEmail.toLowerCase().includes("acme.test"))).toBe(true);
  });

  it("denies list when error seed is active (INV-RM-06)", async () => {
    try {
      await dispatchPreview<"admin.licenses.list">(
        "admin.licenses.list",
        ctx({}, "error")
      );
      throw new Error("Should have thrown AuthForbidden");
    } catch (err: any) {
      expect(err).toBeInstanceOf(LaraApiError);
    }
  });
});
