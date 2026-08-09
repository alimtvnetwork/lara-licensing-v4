import "fake-indexeddb/auto";
import { describe, it, expect, beforeEach } from "vitest";
import { resetAll } from "@/lib/preview-store";
import { loadDefaultSeed } from "@/lib/preview-seeds/default";
import adminUsersModule from "@/lib/preview-fixtures/admin-users";
import {
  clearPreviewHandlersForTest,
  dispatchPreview,
  type PreviewContext,
} from "@/lib/preview-transport";
import type { OperationId } from "@/generated/api/operations";
import { LaraApiError } from "@/lib/lara-api-error";

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
    RequestId: `req-usr-${Math.random().toString(16).slice(2, 8)}`,
  };
}

describe("preview-fixtures admin-users (Plan 18 Step 66)", () => {
  beforeEach(async () => {
    clearPreviewHandlersForTest();
    await resetAll();
    adminUsersModule.register();
  });

  it("returns at least eight seeded users across roles", async () => {
    await loadDefaultSeed();
    const res = await dispatchPreview<"admin.users.list">(
      "admin.users.list",
      ctx({ Cursor: null })
    );

    expect(res.Total).toBeGreaterThanOrEqual(8);
    expect(res.Items.length).toBeGreaterThanOrEqual(8);

    // Verify presence of different roles
    const roles = new Set(res.Items.flatMap(u => u.Roles));
    expect(roles.has("admin")).toBe(true);
    expect(roles.has("reseller")).toBe(true);
  });

  it("filters by Role accurately", async () => {
    await loadDefaultSeed();
    const res = await dispatchPreview<"admin.users.list">(
      "admin.users.list",
      ctx({ Role: "admin" })
    );

    expect(res.Items.every(u => u.Roles.includes("admin"))).toBe(true);
  });

  it("filters by Query against Email (case-insensitive)", async () => {
    await loadDefaultSeed();
    const res = await dispatchPreview<"admin.users.list">(
      "admin.users.list",
      ctx({ Query: "LARA.LOCAL" })
    );

    expect(res.Items.length).toBeGreaterThan(0);
    expect(res.Items.every(u => u.Email.toLowerCase().includes("lara.local"))).toBe(true);
  });

  it("denies list when error seed is active (INV-RM-06)", async () => {
    try {
      await dispatchPreview<"admin.users.list">(
        "admin.users.list",
        ctx({}, "error")
      );
      throw new Error("Should have thrown AuthForbidden");
    } catch (err: any) {
      expect(err).toBeInstanceOf(LaraApiError);
      expect(err.errorCode).toBe("AuthForbidden");
    }
  });
});
