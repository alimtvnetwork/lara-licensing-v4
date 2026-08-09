/**
 * Preview fixtures: admin impersonation domain (Plan 16 Step 46).
 *
 * Registers two operations:
 *   - admin.impersonation.start (POST /api/admin/impersonation/start)
 *   - admin.impersonation.stop  (POST /api/admin/impersonation/stop)
 *
 * Behaviour:
 *   * Active session (if any) is persisted at `impersonation::active`
 *     as an `ImpersonationSession`. Only ONE active session per preview
 *     store: start while active returns 422 ValidationFailed (mirrors
 *     the backend guard in `App\Services\ImpersonationService`).
 *   * Actor MUST be the currently signed-in `me::current` and MUST
 *     carry the "admin" role, otherwise 403 AuthForbidden.
 *   * Target MUST resolve to an `admin-users::<TargetUserId>` record,
 *     MUST be `IsActive`, and MUST NOT be the actor themselves.
 *   * `Reason` is required and non-empty (trim).
 *   * Session TTL: 60 min. `ExpiresAt = now + 3600s`.
 *   * Stop is idempotent under happy paths but returns 404 when there
 *     is no active session so the UI can distinguish "already stopped"
 *     from "never started". Under the error seed both ops fail with
 *     `ERROR_SEED_DOMAIN_CODE.impersonation` (AuthForbidden, 403) per
 *     INV-RM-06.
 *
 * Every function body respects the 15-line cap; INV-RM-05 preserved.
 */

import type { PreviewFixtureModule } from "./_module";
import { previewError, previewSuccess } from "./_shared";
import { read, write, remove } from "@/lib/preview-store";
import { registerPreviewHandler } from "@/lib/preview-transport";
import { ApiErrorCodeType } from "@/lib/lara-api-error";
import { ERROR_SEED_DOMAIN_CODE } from "@/lib/preview-seeds/error";
import type {
  AdminUser,
  EmptyResponse,
  ImpersonationSession,
  ImpersonationStartResponse,
  MeUser,
} from "@/generated/api/schema";

const ACTIVE_KEY = "active";
const SESSION_TTL_SECONDS = 60 * 60;
const HTTP_FORBIDDEN = 403;
const HTTP_NOT_FOUND = 404;
const HTTP_UNPROCESSABLE = 422;

function nowIso(): string {
  return new Date().toISOString();
}

function expiresInIso(seconds: number): string {
  return new Date(Date.now() + seconds * 1000).toISOString();
}

function newSessionId(): string {
  const rand = Math.random().toString(16).slice(2, 10).toUpperCase();

  return `01H0000000000000IMP${rand}`.slice(0, 26);
}

function rejectIfErrorSeed(seed: string, requestId: string): void {
  if (seed !== "error") return;
  previewError(
    ERROR_SEED_DOMAIN_CODE.impersonation,
    "Preview error seed active: impersonation is denied (INV-RM-06).",
    HTTP_FORBIDDEN,
    requestId,
  );
}

async function readActor(requestId: string): Promise<MeUser> {
  const actor = await read<MeUser>("me", "current");
  const isFailed = !actor;
  if (isFailed) {
    previewError(
      ApiErrorCodeType.AuthUnauthorized,
      "No active preview session; sign in before impersonating.",
      HTTP_FORBIDDEN,
      requestId,
    );
  }

  return actor;
}

function assertAdmin(actor: MeUser, requestId: string): void {
  if (actor.Roles.includes("admin")) return;
  console.warn("preview-fixtures:impersonation:actor-not-admin", {
    RequestId: requestId,
    ActorId: actor.Id,
    Roles: actor.Roles,
  });
  previewError(
    ApiErrorCodeType.AuthForbidden,
    "Only admins can impersonate other users.",
    HTTP_FORBIDDEN,
    requestId,
  );
}

async function readTarget(id: string, requestId: string): Promise<AdminUser> {
  const target = await read<AdminUser>("admin-users", id);
  const isFailed = !target;
  if (isFailed) {
    previewError(
      ApiErrorCodeType.ValidationFailed,
      `Target user ${id} not found.`,
      HTTP_NOT_FOUND,
      requestId,
    );
  }

  return target;
}

function assertImpersonable(target: AdminUser, actor: MeUser, requestId: string): void {
  if (target.Id === actor.Id) {
    previewError(
      ApiErrorCodeType.ValidationFailed,
      "Cannot impersonate yourself.",
      HTTP_UNPROCESSABLE,
      requestId,
    );
  }
  const isFailed = !target.IsActive;
  if (isFailed) {
    previewError(
      ApiErrorCodeType.ValidationFailed,
      `Target user ${target.Id} is deactivated.`,
      HTTP_UNPROCESSABLE,
      requestId,
    );
  }
}

function assertNoActiveSession(active: ImpersonationSession | undefined, requestId: string): void {
  const isFailed = !active;
  if (isFailed) return;
  console.warn("preview-fixtures:impersonation:already-active", {
    RequestId: requestId,
    SessionId: active.SessionId,
    TargetId: active.TargetUser.Id,
  });
  previewError(
    ApiErrorCodeType.ValidationFailed,
    `An impersonation session (${active.SessionId}) is already active; stop it first.`,
    HTTP_UNPROCESSABLE,
    requestId,
  );
}

function assertReason(raw: string, requestId: string): string {
  const trimmed = (raw ?? "").trim();
  if (trimmed.length === 0) {
    previewError(
      ApiErrorCodeType.ValidationFailed,
      "Impersonation reason is required.",
      HTTP_UNPROCESSABLE,
      requestId,
    );
  }

  return trimmed;
}

function toMeUser(u: AdminUser): MeUser {
  return {
    Id: u.Id,
    Email: u.Email,
    DisplayName: u.DisplayName,
    Roles: u.Roles,
    ResellerId: u.ResellerId,
    CreatedAt: u.CreatedAt,
    UpdatedAt: u.UpdatedAt,
  };
}

function buildSession(actor: MeUser, target: AdminUser): ImpersonationSession {
  return {
    SessionId: newSessionId(),
    StartedAt: nowIso(),
    ExpiresAt: expiresInIso(SESSION_TTL_SECONDS),
    ActorUser: actor,
    TargetUser: toMeUser(target),
  };
}

const mod: PreviewFixtureModule = {
  name: "impersonation",
  operations: ["admin.impersonation.start", "admin.impersonation.stop"],
  register(): void {
    registerPreviewHandler(
      "admin.impersonation.start",
      async (ctx): Promise<ImpersonationStartResponse> => {
        rejectIfErrorSeed(ctx.Seed, ctx.RequestId);
        const actor = await readActor(ctx.RequestId);
        assertAdmin(actor, ctx.RequestId);
        const params = ctx.Params as any;
        const reason = assertReason(params.Reason, ctx.RequestId);
        const target = await readTarget(params.TargetUserId, ctx.RequestId);
        assertImpersonable(target, actor, ctx.RequestId);
        const active = await read<ImpersonationSession>("impersonation", ACTIVE_KEY);
        assertNoActiveSession(active, ctx.RequestId);
        const session = buildSession(actor, target);
        await write<ImpersonationSession>("impersonation", ACTIVE_KEY, session);
        console.info("preview-fixtures:admin.impersonation.start", {
          RequestId: ctx.RequestId,
          SessionId: session.SessionId,
          ActorId: actor.Id,
          TargetId: target.Id,
          Reason: reason,
        });

        return previewSuccess<"admin.impersonation.start">(session);
      },
    );

    registerPreviewHandler("admin.impersonation.stop", async (ctx): Promise<EmptyResponse> => {
      rejectIfErrorSeed(ctx.Seed, ctx.RequestId);
      const active = await read<ImpersonationSession>("impersonation", ACTIVE_KEY);
      const isFailed = !active;
      if (isFailed) {
        previewError(
          ApiErrorCodeType.ValidationFailed,
          "No active impersonation session to stop.",
          HTTP_NOT_FOUND,
          ctx.RequestId,
        );
      }
      await remove("impersonation", ACTIVE_KEY);
      console.info("preview-fixtures:admin.impersonation.stop", {
        RequestId: ctx.RequestId,
        SessionId: active.SessionId,
        TargetId: active.TargetUser.Id,
      });

      return previewSuccess<"admin.impersonation.stop">({} as EmptyResponse);
    });
  },
};

export default mod;
