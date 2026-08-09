// v0.326.0. Admin user-create transport. Plan 17 Step 11 (v0.650.0): preview bridge.
//
// Root cause of the preview crash on `_authenticated/admin.users.new`:
// `createUser` unconditionally posted to `/Users`, and `requestLaraApi`
// throws in preview mode via `assertRequestNotPreview`. Fix: branch on
// `getRuntimeMode().Mode === "preview"` and delegate to
// `apiClient.call("admin.users.create", ...)` (the preview handler is
// registered in `src/lib/preview-fixtures/admin-users.ts` under the
// `admin-users` domain), then adapt modern `AdminUser` back to the
// legacy `LaraUser` shape used by the admin list route via the
// deterministic id-map (`preview-id-map.ts`).

import { HttpMethodType, requestLaraApi } from "./lara-api-client";
import { laraUserSchema, type LaraUser } from "./lara-user-role";
import { getRuntimeMode } from "./runtime-mode";
import { apiClient } from "./api-client";
import { assignNumeric } from "./preview-id-map";
import type { AdminUser } from "@/generated/api/schema";

export interface CreateUserInput {
  Email: string;
  Password: string;
  TenantId: number | null;
  IsActive: boolean;
}

async function adaptCreatedAdminUser(au: AdminUser, tenantId: number | null): Promise<LaraUser> {
  const userId = await assignNumeric("admin-users", au.Id);

  return {
    UserId: userId,
    Email: au.Email,
    TenantId: tenantId,
    IsActive: au.IsActive,
    CreatedAt: au.CreatedAt,
    UpdatedAt: au.UpdatedAt,
  };
}

async function createPreviewUser(input: CreateUserInput): Promise<LaraUser> {
  const displayName = input.Email.split("@")[0] ?? input.Email;
  const created = await apiClient.call("admin.users.create", {
    Email: input.Email,
    DisplayName: displayName,
    Roles: ["EndUser"],
    ResellerId: null,
    InitialPassword: input.Password,
  });
  console.info("lara-user-create:preview-bridge", { Email: input.Email, Id: created.Id });

  return adaptCreatedAdminUser(created, input.TenantId);
}

export async function createUser(input: CreateUserInput): Promise<LaraUser> {
  if (getRuntimeMode().Mode === "preview") return createPreviewUser(input);
  const body: Record<string, unknown> = {
    Email: input.Email,
    Password: input.Password,
    IsActive: input.IsActive,
  };
  if (input.TenantId !== null) body.TenantId = input.TenantId;
  const [created] = await requestLaraApi(`/Users`, laraUserSchema, {
    method: HttpMethodType.Post,
    body,
  });

  return created;
}
