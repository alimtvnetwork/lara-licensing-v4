/**
 * Plan 16 Step 48 tests: preview admin.metrics.kpis handler.
 *
 * Verifies:
 *  - default seed returns the seeded 4 tiles + GeneratedAt.
 *  - empty seed returns `Tiles` with zeroed values (Plan 18 Step 61) with a fresh GeneratedAt.
 *  - error seed rejects with ValidationFailed (422) per INV-RM-06.
 */
import "fake-indexeddb/auto";
import { beforeEach, describe, expect, it } from "vitest";
import { resetAll } from "../src/lib/preview-store";
import { loadDefaultSeed } from "../src/lib/preview-seeds/default";
import metricsModule from "../src/lib/preview-fixtures/metrics";
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
    RequestId: `req-kpi-${Math.random().toString(16).slice(2, 8)}`,
  };
}

describe("preview-fixtures metrics (Plan 16 Step 48)", () => {
  beforeEach(async () => {
    clearPreviewHandlersForTest();
    await resetAll();
    metricsModule.register();
  });

  it("returns the seeded KPI tiles under the default seed", async () => {
    await loadDefaultSeed();
    const res = await dispatchPreview("admin.metrics.kpis", ctx({}));
    expect(res.Tiles).toHaveLength(4);
    expect(res.Tiles.map((t) => t.Key).sort()).toEqual([
      "licenses.active",
      "licenses.expiring",
      "quota.utilization",
      "updates.adoption",
    ]);
    expect(typeof res.GeneratedAt).toBe("string");
  });

  it("returns a zeroed payload when no row is seeded", async () => {
    // No seed loaded: store is empty after resetAll() in beforeEach.
    const res = await dispatchPreview("admin.metrics.kpis", ctx({}, "empty"));
    expect(res.Tiles).toHaveLength(4);
    expect(res.Tiles.every(t => t.Value === 0)).toBe(true);
    expect(typeof res.GeneratedAt).toBe("string");
  });

  it("error seed rejects with ValidationFailed (422)", async () => {
    await expect(dispatchPreview("admin.metrics.kpis", ctx({}, "error"))).rejects.toMatchObject({
      errorCode: ApiErrorCodeType.ValidationFailed,
      httpStatus: 422,
    });
    await expect(dispatchPreview("admin.metrics.kpis", ctx({}, "error"))).rejects.toBeInstanceOf(LaraApiError);
  });
});
