/**
 * Plan 17 Step 11: preview bridge for the admin user surface.
 *
 * Locks the behavior that `userRolesQueryOptions`,
 * `userRoleAssignmentsQueryOptions`, `grantUserRole`, `revokeUserRole`,
 * and `createUser` all resolve in `Mode=preview` via `apiClient` and
 * the deterministic `preview-id-map`, instead of throwing
 * `LaraApiError: requestLaraApi invoked in preview mode`.
 */

import "fake-indexeddb/auto";
import { beforeEach, describe, expect, it, vi } from "vitest";

import {
  grantUserRole,
  revokeUserRole,
  userRoleAssignmentsQueryOptions,
  userRolesQueryOptions,
  laraUserSchema,
  userRoleEntrySchema,
} from "@/lib/lara-user-role";
import { createUser } from "@/lib/lara-user-create";
import { loadDefaultSeed } from "@/lib/preview-seeds/default";
import { freezeRuntimeMode } from "@/lib/runtime-mode";
import { registerAllPreviewHandlers } from "@/lib/preview-fixtures";
import { resetAll as resetPreviewStore } from "@/lib/preview-store";

describe("lara-user-role + lara-user-create preview bridge", () => {
  beforeEach(async () => {
    await resetPreviewStore();
    registerAllPreviewHandlers();
    freezeRuntimeMode({ Mode: "preview", ApiBaseUrl: null, PreviewSeed: "default" });
    await loadDefaultSeed();
  });

  it("userRolesQueryOptions returns adapted LaraUser rows", async () => {
    const info = vi.spyOn(console, "info").mockImplementation(() => {});
    const rows = await userRolesQueryOptions.queryFn!({} as never);
    expect(Array.isArray(rows)).toBe(true);
    expect(rows.length).toBeGreaterThanOrEqual(1);
    for (const row of rows) {
      expect(() => laraUserSchema.parse(row)).not.toThrow();
    }
    expect(info).toHaveBeenCalledWith(
      "lara-user-role:preview-bridge:list",
      expect.objectContaining({ Count: rows.length }),
    );
    info.mockRestore();
  });

  it("userRoleAssignmentsQueryOptions returns closed-set filtered array", async () => {
    const users = await userRolesQueryOptions.queryFn!({} as never);
    const first = users[0]!;
    const entries = await userRoleAssignmentsQueryOptions(first.UserId).queryFn!({} as never);
    expect(entries).toHaveLength(1);
    expect(() => userRoleEntrySchema.parse(entries[0])).not.toThrow();
    expect(entries[0]!.UserId).toBe(first.UserId);
  });

  it("grant then revoke role round-trips via admin.users.update If-Match", async () => {
    const users = await userRolesQueryOptions.queryFn!({} as never);
    const target = users[0]!;
    const g = await grantUserRole(target.UserId, "AppBuilder");
    expect(g.Role).toBe("AppBuilder");
    const after = await userRoleAssignmentsQueryOptions(target.UserId).queryFn!({} as never);
    expect(after[0]!.Roles).toContain("AppBuilder");
    await revokeUserRole(target.UserId, "AppBuilder");
    const final = await userRoleAssignmentsQueryOptions(target.UserId).queryFn!({} as never);
    expect(final[0]!.Roles).not.toContain("AppBuilder");
  });

  it("createUser creates via preview handler and adapts back to LaraUser", async () => {
    const created = await createUser({
      Email: "new-preview-user@example.com",
      Password: "Password!1",
      TenantId: null,
      IsActive: true,
    });
    expect(() => laraUserSchema.parse(created)).not.toThrow();
    expect(created.Email).toBe("new-preview-user@example.com");
  });
});
