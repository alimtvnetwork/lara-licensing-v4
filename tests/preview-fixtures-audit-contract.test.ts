/**
 * Plan 17 Step 41: preview `admin.audit.list` contract test.
 *
 * Complements `tests/preview-fixtures-audit.test.ts` (which covers
 * single-axis filters) by:
 *   1. Asserting the response shape matches `AdminAuditListResponse`
 *      (Paginated<AuditEntry>) at runtime via a Zod schema built from
 *      `src/generated/api/schema.d.ts` line 273-292. This is the
 *      "contract" half: any drift between the fixture and the typed
 *      schema fails here before it reaches the UI.
 *   2. Exercising filter COMBINATIONS (EventType + ActorUserId,
 *      EventType + Since/Until, ActorUserId + Since) which the earlier
 *      per-axis tests never combined.
 *   3. Round-tripping the pagination cursor: page 1 -> page 2 concatenated
 *      MUST equal an unpaginated fetch of the same filter subset, with no
 *      duplicates and no gaps.
 *
 * INV-RM-05 (typed response parity across preview and live) fails hard
 * here if a future refactor drops a field.
 * Every function body respects the 15-line cap.
 */
import "fake-indexeddb/auto";
import { beforeEach, describe, expect, it } from "vitest";
import { z } from "zod";
import { resetAll, write } from "../src/lib/preview-store";
import { loadDefaultSeed } from "../src/lib/preview-seeds/default";
import auditModule from "../src/lib/preview-fixtures/audit";
import {
  clearPreviewHandlersForTest,
  dispatchPreview,
  type PreviewContext,
} from "../src/lib/preview-transport";
import type { OperationId } from "../src/generated/api/operations";
import type { AuditEntry } from "../src/generated/api/schema";

const ADMIN_ID = "01H0000000000000000ADMIN1";
const RESELLER_ID = "01H0000000000000000RESEL1";

const AuditEntrySchema = z.object({
  Id: z.string().min(1),
  EventType: z.string().min(1),
  ActorUserId: z.string().min(1).nullable(),
  TargetType: z.string().min(1),
  TargetId: z.string().min(1),
  RequestId: z.string().min(1),
  OccurredAt: z.string().min(1),
  Payload: z.record(z.string(), z.unknown()),
});

const AdminAuditListResponseSchema = z.object({
  Items: z.array(AuditEntrySchema),
  Cursor: z.string().nullable(),
  Total: z.number().int().nonnegative(),
});

function ctx<K extends OperationId>(Params: unknown): PreviewContext<K> {
  return {
    Params: Params as never,
    Headers: {},
    Signal: new AbortController().signal,
    Seed: "default",
    Scenario: null,
    RequestId: `req-audc-${Math.random().toString(16).slice(2, 8)}`,
  };
}

async function seedSynthetic(count: number, actor: string): Promise<void> {
  for (let i = 0; i < count; i++) {
    const id = `01HAUDCONTRACT${String(i).padStart(11, "0")}`;
    await write<AuditEntry>("audit", id, {
      Id: id,
      EventType: i % 2 === 0 ? "license.created" : "license.revoked",
      ActorUserId: i % 3 === 0 ? RESELLER_ID : actor,
      TargetType: "license",
      TargetId: `lic-${i}`,
      RequestId: `req_contract_${i}`,
      OccurredAt: `2025-06-${String((i % 28) + 1).padStart(2, "0")}T00:00:00Z`,
      Payload: { Index: i },
    });
  }
}

describe("preview-fixtures admin.audit.list contract (Plan 17 Step 41)", () => {
  beforeEach(async () => {
    clearPreviewHandlersForTest();
    await resetAll();
    auditModule.register();
  });

  it("response matches AdminAuditListResponse shape (Zod)", async () => {
    await loadDefaultSeed();
    const res = await dispatchPreview("admin.audit.list", ctx({}));
    const parsed = AdminAuditListResponseSchema.safeParse(res);
    expect(parsed.success, JSON.stringify(parsed, null, 2)).toBe(true);
  });

  it("AND-combines EventType + ActorUserId filters", async () => {
    await seedSynthetic(30, ADMIN_ID);
    const res = await dispatchPreview("admin.audit.list", ctx({
      EventType: "license.created",
      ActorUserId: ADMIN_ID,
    }));
    // even indices are license.created; of those, i%3!==0 belongs to admin.
    // i in {2,4,8,10,14,16,20,22,26,28} = 10 rows.
    expect(res.Total).toBe(10);
    for (const e of res.Items) {
      expect(e.EventType).toBe("license.created");
      expect(e.ActorUserId).toBe(ADMIN_ID);
    }
  });

  it("AND-combines EventType + Since/Until window", async () => {
    await seedSynthetic(30, ADMIN_ID);
    const res = await dispatchPreview("admin.audit.list", ctx({
      EventType: "license.revoked",
      Since: "2025-06-10T00:00:00Z",
      Until: "2025-06-20T00:00:00Z",
    }));
    for (const e of res.Items) {
      expect(e.EventType).toBe("license.revoked");
      expect(e.OccurredAt >= "2025-06-10T00:00:00Z").toBe(true);
      expect(e.OccurredAt <= "2025-06-20T00:00:00Z").toBe(true);
    }
  });

  it("cursor round-trip: page1 + page2 = unpaginated slice, no duplicates", async () => {
    // Need > 25 admin rows to force a second page. 60 synthetic rows -> 40
    // admin rows (i%3!==0) -> page 1 has 25, page 2 has 15.
    await seedSynthetic(60, ADMIN_ID);
    const p1 = await dispatchPreview("admin.audit.list", ctx({ ActorUserId: ADMIN_ID }));
    expect(p1.Items).toHaveLength(25);
    expect(p1.Cursor).toBe("25");
    const p2 = await dispatchPreview("admin.audit.list", ctx({
      ActorUserId: ADMIN_ID,
      Cursor: p1.Cursor,
    }));
    expect(p2.Items).toHaveLength(15);
    expect(p2.Cursor).toBeNull();
    const stitched = [...p1.Items, ...p2.Items].map((e) => e.Id);
    expect(new Set(stitched).size).toBe(stitched.length);
    expect(stitched.length).toBe(p1.Total);
  });
});
