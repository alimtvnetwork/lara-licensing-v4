import { queryOptions } from "@tanstack/react-query";
import { z } from "zod";

import { HttpMethodType, requestLaraApi } from "./lara-api-client";
import { getRuntimeMode } from "./runtime-mode";
import { apiClient } from "./api-client";
import { assignNumeric, ulidFor } from "./preview-id-map";
import type { AdminUser } from "@/generated/api/schema";

/**
 * Plan 17 Step 11 (v0.650.0): preview bridge for the admin user surface.
 *
 * Root cause: `userRolesQueryOptions`, `userRoleAssignmentsQueryOptions`,
 * `grantUserRole`, and `revokeUserRole` all called `requestLaraApi(...)`
 * unconditionally; every route under `_authenticated/admin.users*`
 * suspends on those loaders, so in `Mode=preview` the admin users list
 * and detail routes threw `LaraApiError: requestLaraApi invoked in
 * preview mode` before mount. Fix: branch each function on
 * `getRuntimeMode().Mode === "preview"` and adapt modern `AdminUser`
 * (ULID Id, string Roles, ResellerId) into the legacy `LaraUser`
 * (positive-int UserId, positive-int TenantId nullable) via the
 * deterministic `preview-id-map` from v0.643.0.
 */

const USER_LIMIT = 200;

export const appRoleSchema = z.enum(["Admin", "Reseller", "AppBuilder", "EndUser"]);
export type AppRoleType = z.infer<typeof appRoleSchema>;

export const APP_ROLE_VALUES: readonly AppRoleType[] = [
  "Admin",
  "Reseller",
  "AppBuilder",
  "EndUser",
];

export const laraUserSchema = z.object({
  UserId: z.number().int().positive(),
  Email: z.string().email(),
  TenantId: z.number().int().positive().nullable(),
  IsActive: z.boolean(),
  CreatedAt: z.string().datetime(),
  UpdatedAt: z.string().datetime(),
});

export type LaraUser = z.infer<typeof laraUserSchema>;

async function adaptAdminUser(au: AdminUser): Promise<LaraUser> {
  const userId = await assignNumeric("admin-users", au.Id);
  const tenantId = au.ResellerId ? await assignNumeric("resellers", au.ResellerId) : null;

  return {
    UserId: userId,
    Email: au.Email,
    TenantId: tenantId,
    IsActive: au.IsActive,
    CreatedAt: au.CreatedAt,
    UpdatedAt: au.UpdatedAt,
  };
}

async function fetchPreviewUsers(): Promise<LaraUser[]> {
  const res = await apiClient.call("admin.users.list", { Cursor: null });
  const rows = await Promise.all(res.Items.map(adaptAdminUser));
  console.info("lara-user-role:preview-bridge:list", { Count: rows.length });

  return rows;
}

export const userRolesQueryOptions = queryOptions({
  queryKey: ["LaraApi", "Users", USER_LIMIT],
  queryFn: ({ signal }) =>
    getRuntimeMode().Mode === "preview"
      ? fetchPreviewUsers()
      : requestLaraApi(`/Users?Limit=${USER_LIMIT}`, laraUserSchema, { signal }),
  retry: false,
});

export const userRoleEntrySchema = z.object({
  UserId: z.number().int().positive(),
  Roles: z.array(appRoleSchema),
});
export type UserRoleEntry = z.infer<typeof userRoleEntrySchema>;

function filterClosedSetRoles(roles: readonly string[]): AppRoleType[] {
  return roles.filter((r): r is AppRoleType => (APP_ROLE_VALUES as readonly string[]).includes(r));
}

async function loadPreviewAdminUser(userId: number): Promise<AdminUser> {
  const ulid = await ulidFor("admin-users", userId);
  if (!ulid) throw new Error(`admin-users ulid missing for numeric id ${userId}`);
  const res = await apiClient.call("admin.users.list", { Cursor: null });
  const found = res.Items.find((u) => u.Id === ulid);
  if (!found) throw new Error(`admin-users row not found for ulid ${ulid}`);

  return found;
}

async function fetchPreviewRoles(userId: number): Promise<UserRoleEntry[]> {
  const admin = await loadPreviewAdminUser(userId);
  const roles = filterClosedSetRoles(admin.Roles);
  console.info("lara-user-role:preview-bridge:roles", { UserId: userId, Roles: roles });

  return [{ UserId: userId, Roles: roles }];
}

export function userRoleAssignmentsQueryOptions(userId: number) {
  return queryOptions({
    queryKey: ["LaraApi", "Users", "Roles", userId],
    queryFn: ({ signal }) =>
      getRuntimeMode().Mode === "preview"
        ? fetchPreviewRoles(userId)
        : requestLaraApi(`/Admin/Users/${userId}/Roles`, userRoleEntrySchema, { signal }),
    retry: false,
  });
}

export const roleGrantResultSchema = z.object({
  UserId: z.number().int().positive(),
  Role: appRoleSchema,
  GrantedAt: z.string().datetime(),
});
export type RoleGrantResult = z.infer<typeof roleGrantResultSchema>;

async function previewPatchRoles(userId: number, next: string[]): Promise<AdminUser> {
  const current = await loadPreviewAdminUser(userId);
  const res = await apiClient.call("admin.users.update", {
    Id: current.Id,
    IfMatch: String(current.Version),
    Roles: next,
  });

  return res;
}

async function grantPreviewRole(userId: number, role: AppRoleType): Promise<RoleGrantResult> {
  const current = await loadPreviewAdminUser(userId);
  const next = Array.from(new Set([...current.Roles, role]));
  await previewPatchRoles(userId, next);
  console.info("lara-user-role:preview-bridge:grant", { UserId: userId, Role: role });

  return { UserId: userId, Role: role, GrantedAt: new Date().toISOString() };
}

export async function grantUserRole(userId: number, role: AppRoleType): Promise<RoleGrantResult> {
  if (getRuntimeMode().Mode === "preview") return grantPreviewRole(userId, role);
  const [granted] = await requestLaraApi(`/Users/${userId}/Roles`, roleGrantResultSchema, {
    method: HttpMethodType.Post,
    body: { Role: role },
  });

  return granted;
}

async function revokePreviewRole(userId: number, role: AppRoleType): Promise<void> {
  const current = await loadPreviewAdminUser(userId);
  const next = current.Roles.filter((r) => r !== role);
  await previewPatchRoles(userId, next);
  console.info("lara-user-role:preview-bridge:revoke", { UserId: userId, Role: role });
}

export async function revokeUserRole(userId: number, role: AppRoleType): Promise<void> {
  if (getRuntimeMode().Mode === "preview") return revokePreviewRole(userId, role);
  await requestLaraApi(`/Users/${userId}/Roles/${role}`, z.unknown(), {
    method: HttpMethodType.Delete,
  });
}
