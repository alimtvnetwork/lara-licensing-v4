/**
 * Plan 16 Step 47 tests: preview admin.audit.list handler.
 *
 * Verifies:
 *  - default seed returns all audit rows sorted by OccurredAt DESC.
 *  - EventType filter narrows the result set.
 *  - ActorUserId filter narrows the result set.
 *  - Since/Until window filters bound OccurredAt inclusively.
 *  - Cursor pagination is deterministic (offset scheme, page size 25).
 *  - error seed rejects with AuthForbidden (403) per INV-RM-06.
 */
import "fake-indexeddb/auto";
import { beforeEach, describe, expect, it } from "vitest";
import { resetAll, write } from "../src/lib/preview-store";
import { loadDefaultSeed } from "../src/lib/preview-seeds/default";
import auditModule from "../src/lib/preview-fixtures/audit";
import {
  clearPreviewHandlersForTest,
  dispatchPreview,
  type PreviewContext,
} from "../src/lib/preview-transport";
import { ApiErrorCodeType, LaraApiError } from "../src/lib/lara-api-error";
import type { OperationId } from "../src/generated/api/operations";
import type { AuditEntry } from "../src/generated/api/schema";

const ADMIN_ID = "01H0000000000000000ADMIN1";

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
    RequestId: `req-aud-${Math.random().toString(16).slice(2, 8)}`,
  };
}

describe("preview-fixtures audit (Plan 16 Step 47)", () => {
  beforeEach(async () => {
    clearPreviewHandlersForTest();
    await resetAll();
    auditModule.register();
  });

  it("returns all seeded rows sorted by OccurredAt DESC", async () => {
    await loadDefaultSeed();
    const res = await dispatchPreview("admin.audit.list", ctx({}));
    expect(res.Total).toBe(30);
    expect(res.Items).toHaveLength(25); // page size cap
    const times = res.Items.map((e) => e.OccurredAt);
    const sorted = [...times].sort((a, b) => b.localeCompare(a));
    expect(times).toEqual(sorted);
  });

  it("filters by EventType", async () => {
    await loadDefaultSeed();
    const res = await dispatchPreview("admin.audit.list", ctx({ EventType: "license.created" }));
    expect(res.Total).toBe(1);
    expect(res.Items[0].EventType).toBe("license.created");
  });

  it("filters by ActorUserId", async () => {
    await loadDefaultSeed();
    // Plan 17 Step 20 + Phase D: 30 seeded rows, reseller when i%5===0 (indices 0,5,10,15,20,25 -> 6 rows),
    // admin for the remaining 24 rows.
    const res = await dispatchPreview("admin.audit.list", ctx({ ActorUserId: ADMIN_ID }));
    expect(res.Total).toBe(24);
    const other = await dispatchPreview("admin.audit.list", ctx({ ActorUserId: "01H0NOSUCHACTOR000000000000" }));
    expect(other.Total).toBe(0);
  });

  it("filters by Since / Until window", async () => {
    await loadDefaultSeed();
    const res = await dispatchPreview("admin.audit.list", ctx({
      Since: "2024-01-01T00:00:00Z",
      Until: "9999-12-31T23:59:59Z",
    }));
    expect(res.Total).toBe(30);
    const none = await dispatchPreview("admin.audit.list", ctx({
      Since: "9999-01-01T00:00:00Z",
    }));
    expect(none.Total).toBe(0);
  });


  it("paginates deterministically via numeric cursor", async () => {
    // Seed 30 synthetic rows to exercise the 25-row page size.
    for (let i = 0; i < 30; i++) {
      const id = `01HAUDITSYNTHROW${String(i).padStart(9, "0")}`;
      await write<AuditEntry>("audit", id, {
        Id: id,
        EventType: "synthetic.event",
        ActorUserId: ADMIN_ID,
        TargetType: "synthetic",
        TargetId: id,
        RequestId: `req_synth_${i}`,
        OccurredAt: `2025-01-01T00:00:${String(i).padStart(2, "0")}Z`,
        Payload: { Index: i },
      });
    }
    const first = await dispatchPreview("admin.audit.list", ctx({}));
    expect(first.Items).toHaveLength(25);
    expect(first.Cursor).toBe("25");
    expect(first.Total).toBe(30);
    const second = await dispatchPreview("admin.audit.list", ctx({ Cursor: first.Cursor }));
    expect(second.Items).toHaveLength(5);
    expect(second.Cursor).toBeNull();
  });

  it("error seed rejects with AuthForbidden (403)", async () => {
    await expect(dispatchPreview("admin.audit.list", ctx({}, "error"))).rejects.toMatchObject({
      errorCode: ApiErrorCodeType.AuthForbidden,
      httpStatus: 403,
    });
    await expect(dispatchPreview("admin.audit.list", ctx({}, "error"))).rejects.toBeInstanceOf(LaraApiError);
  });
});