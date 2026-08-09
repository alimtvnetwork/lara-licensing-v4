/**
 * Plan 17 Step 24: negative-path error-seed contract.
 *
 * Proves that under `getPreviewSeed()==="error"`, the audit and features
 * handlers reject with the canonical `ERROR_SEED_DOMAIN_CODE[<domain>]`
 * even though the store contains seeded rows. Also proves the
 * quota-requests preview store is populated by `loadErrorSeed` so the
 * StateCard-error path is exercised end-to-end without the failure being
 * mistakable for an "empty store" no-op (INV-RM-06).
 */

import "fake-indexeddb/auto";
import { beforeEach, describe, expect, it } from "vitest";

import { loadErrorSeed, ERROR_SEED_DOMAIN_CODE } from "@/lib/preview-seeds/error";
import auditModule from "@/lib/preview-fixtures/audit";
import featuresModule from "@/lib/preview-fixtures/features";
import {
  clearPreviewHandlersForTest,
  dispatchPreview,
  type PreviewContext,
} from "@/lib/preview-transport";
import { LaraApiError } from "@/lib/lara-api-error";
import { list, resetAll } from "@/lib/preview-store";
import type { OperationId } from "@/generated/api/operations";

function ctx<K extends OperationId>(Params: unknown): PreviewContext<K> {
  return {
    Params: Params as never,
    Headers: {},
    Signal: new AbortController().signal,
    Seed: "error",
    Scenario: null,
    RequestId: `req-err-${Math.random().toString(16).slice(2, 8)}`,
  };
}

describe("preview error-seed negative-path rows (Plan 17 Step 24)", () => {
  beforeEach(async () => {
    clearPreviewHandlersForTest();
    await resetAll();
    auditModule.register();
    featuresModule.register();
    await loadErrorSeed();
  });

  it("writes rows into audit / features / quota-requests domains", async () => {
    const audit = await list("audit");
    const features = await list("features");
    const qreq = await list("quota-requests");
    expect(audit.length).toBeGreaterThanOrEqual(2);
    expect(features.length).toBeGreaterThanOrEqual(2);
    expect(qreq.length).toBeGreaterThanOrEqual(2);
  });

  it("audit list still rejects with canonical code despite seeded rows", async () => {
    const p = dispatchPreview("admin.audit.list", ctx({}));
    await expect(p).rejects.toBeInstanceOf(LaraApiError);
    await expect(p).rejects.toMatchObject({
      errorCode: ERROR_SEED_DOMAIN_CODE.audit,
    });
  });

  it("features list still rejects with canonical code despite seeded rows", async () => {
    const p = dispatchPreview("admin.features.list", ctx({}));
    await expect(p).rejects.toBeInstanceOf(LaraApiError);
    await expect(p).rejects.toMatchObject({
      errorCode: ERROR_SEED_DOMAIN_CODE.features,
    });
  });
});
