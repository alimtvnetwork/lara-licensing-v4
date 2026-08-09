/**
 * Preview fixtures: admin-users domain (Plan 16 Step 49).
 *
 * Registers preview handlers for the four admin user operations:
 *   - admin.users.list   (GET    /api/admin/users)
 *   - admin.users.create (POST   /api/admin/users)
 *   - admin.users.update (PATCH  /api/admin/users/:Id)  [If-Match]
 *   - admin.users.delete (DELETE /api/admin/users/:Id)  [If-Match]
 *
 * Behaviour:
 *   * Rows live at `admin-users::<Id>` (seeded in default seed).
 *   * List paginates deterministically by Email ASC (Id ASC tiebreaker),
 *     honours `Query` (Email/DisplayName substring, case-insensitive) and
 *     `Role` (exact match against Roles[]). Cursor is a numeric offset.
 *   * Create rejects duplicate Email (case-insensitive) with 422
 *     ValidationFailed and requires at least one Role.
 *   * Update/delete honour `If-Match: <Version>` and bump Version on
 *     success. Delete refuses to remove the currently signed-in user
 *     (`me::current`) with 422 ValidationFailed so the preview session
 *     cannot orphan itself.
 *   * Under the `error` seed every operation rejects with
 *     `ERROR_SEED_DOMAIN_CODE["admin-users"]` (AuthForbidden, 403).
 *
 * Function bodies obey the 15-line cap.
 */

import type { PreviewFixtureModule } from "./_module";
import { previewError, previewSuccess } from "./_shared";
import { list, read, remove, write } from "@/lib/preview-store";
import { registerPreviewHandler } from "@/lib/preview-transport";
import { ApiErrorCodeType } from "@/lib/lara-api-error";
import { ERROR_SEED_DOMAIN_CODE } from "@/lib/preview-seeds/error";
import type {
  AdminUser,
  AdminUserCreateRequest,
  AdminUserCreateResponse,
  AdminUserDeleteRequest,
  AdminUserDeleteResponse,
  AdminUserUpdateRequest,
  AdminUserUpdateResponse,
  AdminUsersListRequest,
  AdminUsersListResponse,
  MeUser,
} from "@/generated/api/schema";

const HTTP_FORBIDDEN = 403;
const HTTP_NOT_FOUND = 404;
const HTTP_PRECONDITION_FAILED = 412;
const HTTP_UNPROCESSABLE = 422;
const DEFAULT_PAGE_SIZE = 25;

function nowIso(): string {
  return new Date().toISOString();
}

function newUserId(): string {
  const suffix = Math.random().toString(16).slice(2, 10).toUpperCase().padEnd(8, "0");

  return `01H00000000000000USRNEW${suffix}`.slice(0, 26);
}

function rejectIfErrorSeed(seed: string, requestId: string): void {
  if (seed !== "error") return;
  previewError(
    ERROR_SEED_DOMAIN_CODE["admin-users"],
    "Preview error seed active: admin-users calls always fail (INV-RM-06).",
    HTTP_FORBIDDEN,
    requestId,
  );
}

async function loadAllUsers(): Promise<AdminUser[]> {
  const rows = await list<AdminUser>("admin-users");

  return rows.map(([, v]) => v);
}

async function readUser(id: string, requestId: string): Promise<AdminUser> {
  const found = await read<AdminUser>("admin-users", id);
  const isFailed = !found;
  if (isFailed) {
    previewError(
      ApiErrorCodeType.UserNotFound,
      `User ${id} not found in preview store.`,
      HTTP_NOT_FOUND,
      requestId,
    );
  }

  return found;
}

function assertVersionMatch(user: AdminUser, ifMatch: string, requestId: string): void {
  if (String(user.Version) === String(ifMatch)) return;
  console.warn("preview-fixtures:admin-users:if-match-mismatch", {
    RequestId: requestId,
    UserId: user.Id,
    ExpectedVersion: user.Version,
    ProvidedIfMatch: ifMatch,
  });
  previewError(
    ApiErrorCodeType.PreconditionFailed,
    `If-Match ${ifMatch} does not match current Version ${user.Version}.`,
    HTTP_PRECONDITION_FAILED,
    requestId,
  );
}

function matchesFilters(u: AdminUser, p: AdminUsersListRequest): boolean {
  if (p.Role && !u.Roles.includes(p.Role)) return false;
  if (!p.Query) return true;
  const q = p.Query.toLowerCase();

  return u.Email.toLowerCase().includes(q) || u.DisplayName.toLowerCase().includes(q);
}

function sortUsers(items: AdminUser[]): AdminUser[] {
  return [...items].sort((a, b) => {
    const e = a.Email.localeCompare(b.Email);

    return e !== 0 ? e : a.Id.localeCompare(b.Id);
  });
}

function paginate(items: AdminUser[], cursor: string | null | undefined): AdminUsersListResponse {
  const start = cursor ? Number.parseInt(cursor, 10) || 0 : 0;
  const end = start + DEFAULT_PAGE_SIZE;
  const slice = items.slice(start, end);
  const next = end < items.length ? String(end) : null;

  return { Items: slice, Cursor: next, Total: items.length };
}

async function assertEmailUnique(email: string, requestId: string): Promise<void> {
  const all = await loadAllUsers();
  const clash = all.some((u) => u.Email.toLowerCase() === email.toLowerCase());
  const isFailed = !clash;
  if (isFailed) return;
  previewError(
    ApiErrorCodeType.ValidationFailed,
    `Email ${email} already exists.`,
    HTTP_UNPROCESSABLE,
    requestId,
  );
}

function assertHasRole(p: AdminUserCreateRequest, requestId: string): void {
  if (Array.isArray(p.Roles) && p.Roles.length > 0) return;
  previewError(
    ApiErrorCodeType.ValidationFailed,
    "At least one Role is required.",
    HTTP_UNPROCESSABLE,
    requestId,
  );
}

function buildNewUser(p: AdminUserCreateRequest): AdminUser {
  const now = nowIso();

  return {
    Id: newUserId(),
    Email: p.Email,
    DisplayName: p.DisplayName,
    Roles: p.Roles,
    ResellerId: p.ResellerId,
    IsActive: true,
    LastLoginAt: null,
    Version: 1,
    CreatedAt: now,
    UpdatedAt: now,
  } satisfies AdminUser & MeUser;
}

function applyPatch(current: AdminUser, patch: AdminUserUpdateRequest): AdminUser {
  return {
    ...current,
    DisplayName: patch.DisplayName ?? current.DisplayName,
    Roles: patch.Roles ?? current.Roles,
    IsActive: patch.IsActive ?? current.IsActive,
    ResellerId: patch.ResellerId !== undefined ? patch.ResellerId : current.ResellerId,
    Version: current.Version + 1,
    UpdatedAt: nowIso(),
  };
}

async function assertNotSelfDelete(userId: string, requestId: string): Promise<void> {
  const me = await read<MeUser>("me", "current");
  if (!me || me.Id !== userId) return;
  previewError(
    ApiErrorCodeType.ValidationFailed,
    "Cannot delete the currently signed-in user.",
    HTTP_UNPROCESSABLE,
    requestId,
  );
}

const mod: PreviewFixtureModule = {
  name: "admin-users",
  operations: [
    "admin.users.list",
    "admin.users.create",
    "admin.users.update",
    "admin.users.delete",
  ],
  register(): void {
    registerPreviewHandler("admin.users.list", async (ctx): Promise<AdminUsersListResponse> => {
      rejectIfErrorSeed(ctx.Seed, ctx.RequestId);
      const all = await loadAllUsers();
      const params = ctx.Params as AdminUsersListRequest;
      const filtered = sortUsers(all.filter((u) => matchesFilters(u, params)));
      console.info("preview-fixtures:admin.users.list", {
        RequestId: ctx.RequestId,
        Total: filtered.length,
      });

      return previewSuccess<"admin.users.list">(paginate(filtered, params.Cursor));
    });

    registerPreviewHandler("admin.users.create", async (ctx): Promise<AdminUserCreateResponse> => {
      rejectIfErrorSeed(ctx.Seed, ctx.RequestId);
      const params = ctx.Params as AdminUserCreateRequest;
      assertHasRole(params, ctx.RequestId);
      await assertEmailUnique(params.Email, ctx.RequestId);
      const user = buildNewUser(params);
      await write<AdminUser>("admin-users", user.Id, user);
      console.info("preview-fixtures:admin.users.create", {
        RequestId: ctx.RequestId,
        UserId: user.Id,
        Email: user.Email,
      });

      return previewSuccess<"admin.users.create">(user);
    });

    registerPreviewHandler("admin.users.update", async (ctx): Promise<AdminUserUpdateResponse> => {
      rejectIfErrorSeed(ctx.Seed, ctx.RequestId);
      const params = ctx.Params as AdminUserUpdateRequest;
      const current = await readUser(params.Id, ctx.RequestId);
      assertVersionMatch(current, params.IfMatch, ctx.RequestId);
      const next = applyPatch(current, params);
      await write<AdminUser>("admin-users", next.Id, next);
      console.info("preview-fixtures:admin.users.update", {
        RequestId: ctx.RequestId,
        UserId: next.Id,
        FromVersion: current.Version,
        ToVersion: next.Version,
      });

      return previewSuccess<"admin.users.update">(next);
    });

    registerPreviewHandler("admin.users.delete", async (ctx): Promise<AdminUserDeleteResponse> => {
      rejectIfErrorSeed(ctx.Seed, ctx.RequestId);
      const params = ctx.Params as AdminUserDeleteRequest;
      const current = await readUser(params.Id, ctx.RequestId);
      assertVersionMatch(current, params.IfMatch, ctx.RequestId);
      await assertNotSelfDelete(current.Id, ctx.RequestId);
      await remove("admin-users", current.Id);
      console.info("preview-fixtures:admin.users.delete", {
        RequestId: ctx.RequestId,
        UserId: current.Id,
      });

      return previewSuccess<"admin.users.delete">({} as AdminUserDeleteResponse);
    });
  },
};

export default mod;
