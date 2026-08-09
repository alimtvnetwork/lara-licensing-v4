import { queryOptions } from "@tanstack/react-query";
import { z } from "zod";

import { HttpMethodType, requestLaraApi } from "./lara-api-client";
import { getRuntimeMode } from "./runtime-mode";

/**
 * v0.298.0. Admin surface for Root AuthSessions (spec 31 + spec 47).
 *
 * GET /Api/Admin/Users/{UserId}/Sessions?IncludeEnded=&Limit=
 * DELETE /Api/Admin/Sessions/{SessionId}  (writes RevokeReason=AdminForced)
 *
 * Plan 17 Step 10 (v0.649.0): preview-mode bridge.
 *
 * Root cause: both `userSessionsQueryOptions` and `revokeAuthSession`
 * unconditionally called `requestLaraApi(...)`, which
 * `assertRequestNotPreview` blocks in `Mode=preview`. That crashed
 * `UserSessionsPanel` (`useSuspenseQuery(userSessionsQueryOptions(...))`)
 * on the admin user detail route before mount. The modern OpenAPI has
 * no `admin.sessions.*` operation and no preview handler is registered,
 * so the correct minimum bridge is an explicit empty result with a
 * structured info log; the panel already renders "No sessions match
 * the current filter." when the list is empty.
 */

export const authSessionSchema = z.object({
  SessionId: z.string().uuid(),
  UserId: z.number().int().positive(),
  Kind: z.enum(["Normal", "Impersonation", "ServiceAccount"]),
  ImpersonatorUserId: z.number().int().positive().nullable(),
  ParentSessionId: z.string().uuid().nullable(),
  CreatedAt: z.string().nullable(),
  ExpiresAt: z.string().nullable(),
  EndedAt: z.string().nullable(),
  RevokeReason: z.string().nullable(),
  IsActive: z.boolean(),
});

export type AuthSession = z.infer<typeof authSessionSchema>;

const revokeResultSchema = z.object({
  SessionId: z.string().uuid(),
  RevokeReason: z.string(),
});

const LIMIT = 100;

async function fetchPreviewUserSessions(
  userId: number,
  includeEnded: boolean,
): Promise<AuthSession[]> {
  console.info("lara-sessions:preview-bridge:list", {
    UserId: userId,
    IncludeEnded: includeEnded,
    Count: 0,
  });

  return [];
}

export function userSessionsQueryOptions(userId: number, includeEnded: boolean) {
  const qs = `IncludeEnded=${includeEnded ? "1" : "0"}&Limit=${LIMIT}`;

  return queryOptions({
    queryKey: ["LaraApi", "Admin", "UserSessions", userId, includeEnded],
    queryFn: ({ signal }) =>
      getRuntimeMode().Mode === "preview"
        ? fetchPreviewUserSessions(userId, includeEnded)
        : requestLaraApi(`/Admin/Users/${userId}/Sessions?${qs}`, authSessionSchema, { signal }),
    retry: false,
    staleTime: 15_000,
  });
}

export async function revokeAuthSession(sessionId: string): Promise<void> {
  if (getRuntimeMode().Mode === "preview") {
    console.info("lara-sessions:preview-bridge:revoke:noop", { SessionId: sessionId });

    return;
  }
  await requestLaraApi(`/Admin/Sessions/${sessionId}`, revokeResultSchema, {
    method: HttpMethodType.Delete,
  });
}
