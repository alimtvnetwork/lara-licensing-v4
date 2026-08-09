import { queryOptions } from "@tanstack/react-query";
import { z } from "zod";

import { apiClient } from "./api-client";
import { requestLaraApi } from "./lara-api-client";
import { getRuntimeMode } from "./runtime-mode";
import type { MeUser } from "@/generated/api/schema";

/**
 * Current-user identity client for GET /Users/Me per
 * spec/21-app/11-api-contracts/06-user-contracts.md (Users self-read) and
 * spec/21-app/04-roles.md (RoleName closed set).
 *
 * Plan 17 step 3 (preview bridge): legacy consumers call
 * `meQueryOptions()`, which used to always hit
 * `requestLaraApi("/Users/Me")`. In `Mode=preview` that path is blocked
 * by `assertRequestNotPreview` and every page reading `me` crashed. A
 * typed preview handler `auth.me` already exists in
 * `src/lib/preview-fixtures/auth.ts`; this module bridges the two by
 * adapting `MeUser` (preview schema, string ULIDs) into the legacy
 * `MeResource` shape (numeric ids + closed-set RoleName). Live mode is
 * unchanged.
 */

export const meResourceSchema = z.object({
  UserId: z.number().int().positive(),
  Email: z.string().email(),
  RoleName: z.enum(["SuperAdmin", "Admin", "Reseller", "Support", "Auditor", "EndUser"]),
  ResellerId: z.number().int().positive().nullable().optional(),
  DisplayName: z.string().min(1).max(200).nullable().optional(),
});
export type MeResource = z.infer<typeof meResourceSchema>;

const RoleMap: Record<string, MeResource["RoleName"]> = {
  superadmin: "SuperAdmin",
  admin: "Admin",
  reseller: "Reseller",
  support: "Support",
  auditor: "Auditor",
  enduser: "EndUser",
};

function mapRole(roles: string[]): MeResource["RoleName"] {
  for (const raw of roles) {
    const key = raw.toLowerCase();
    if (RoleMap[key]) return RoleMap[key];
  }

  return "EndUser";
}

// Deterministic non-cryptographic hash → positive int stable across reloads.
function ulidToPositiveInt(id: string): number {
  let h = 0;
  for (let i = 0; i < id.length; i++) h = (h * 31 + id.charCodeAt(i)) | 0;

  return Math.abs(h) + 1;
}

function adaptMe(u: MeUser): MeResource {
  return {
    UserId: ulidToPositiveInt(u.Id),
    Email: u.Email,
    RoleName: mapRole(u.Roles),
    ResellerId: u.ResellerId ? ulidToPositiveInt(u.ResellerId) : null,
    DisplayName: u.DisplayName,
  };
}

async function fetchPreview(signal?: AbortSignal): Promise<MeResource[]> {
  const res = await apiClient.call("auth.me", {}, { signal });
  const adapted = adaptMe(res);
  console.info("lara-me:preview-bridge", {
    UserId: adapted.UserId,
    RoleName: adapted.RoleName,
    ResellerId: adapted.ResellerId ?? null,
  });

  return [adapted];
}

export function meQueryOptions() {
  return queryOptions<MeResource[]>({
    queryKey: ["LaraApi", "Users", "Me"],
    queryFn: ({ signal }) => {
      if (getRuntimeMode().Mode === "preview") return fetchPreview(signal);

      return requestLaraApi("/Users/Me", meResourceSchema, { signal });
    },
    retry: false,
    staleTime: 60_000,
  });
}
