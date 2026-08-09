/**
 * Preview fixtures: auth domain (Plan 16 Step 40).
 *
 * Registers preview handlers for the four auth operations:
 *   - auth.login   (POST /api/auth/login)
 *   - auth.refresh (POST /api/auth/refresh)
 *   - auth.logout  (POST /api/auth/logout)
 *   - auth.me      (GET  /api/auth/me)
 *
 * Behaviour:
 *   * Credentials are matched against the `auth::credentials` map seeded
 *     by `src/lib/preview-seeds/{default,empty,error}.ts`. The "current"
 *     `MeUser` record lives at `me::current` and is written on successful
 *     login so `auth.me` reads it back.
 *   * Preview tokens are opaque marker strings (`preview.<seed>.<userId>`)
 *     and are NOT valid production JWTs. `laraFetch` in live mode never
 *     sees them because api-client routes preview calls through
 *     `dispatchPreview` before any live transport runs.
 *   * When the active seed is "error" every handler throws the canonical
 *     `AuthUnauthorized` envelope per `ERROR_SEED_DOMAIN_CODE.auth`.
 *
 * INV-RM-04: after this file loads, the four `auth.*` operations
 * disappear from `findMissingPreviewHandlers()`.
 */

import type { PreviewFixtureModule } from "./_module";
import { previewError, previewSuccess } from "./_shared";
import { read, write, list } from "@/lib/preview-store";
import { registerPreviewHandler } from "@/lib/preview-transport";
import { ApiErrorCodeType } from "@/lib/lara-api-error";
import type {
  AdminUser,
  AuthLoginResponse,
  AuthRefreshResponse,
  EmptyResponse,
  MeUser,
} from "@/generated/api/schema";

const ACCESS_TOKEN_TTL_SECONDS = 60 * 60; // 1h
const REFRESH_TOKEN_TTL_SECONDS = 60 * 60 * 24 * 7; // 7d
const HTTP_UNAUTHORIZED = 401;

function nowIso(): string {
  return new Date().toISOString();
}

function isoInSeconds(seconds: number): string {
  return new Date(Date.now() + seconds * 1000).toISOString();
}

function tokenFor(seed: string, kind: "access" | "refresh", userId: string): string {
  return `preview.${seed}.${kind}.${userId}`;
}

async function readCredentials(): Promise<Record<string, string>> {
  const map = await read<Record<string, string>>("auth", "credentials");

  return map ?? {};
}

async function readCurrentUser(): Promise<MeUser | undefined> {
  return read<MeUser>("me", "current");
}

async function findUserByEmail(email: string): Promise<MeUser | undefined> {
  const users = await read<MeUser>("me", "current");
  if (users && users.Email.toLowerCase() === email.toLowerCase()) return users;

  return undefined;
}

function rejectIfErrorSeed(seed: string, requestId: string): void {
  if (seed === "error") {
    previewError(
      ApiErrorCodeType.AuthUnauthorized,
      "Preview error seed active: auth calls always fail (INV-RM-06).",
      HTTP_UNAUTHORIZED,
      requestId,
    );
  }
}

function buildTokenPair(
  seed: string,
  userId: string,
): {
  AccessToken: string;
  AccessTokenExpiresAt: string;
  RefreshToken: string;
  RefreshTokenExpiresAt: string;
} {
  return {
    AccessToken: tokenFor(seed, "access", userId),
    AccessTokenExpiresAt: isoInSeconds(ACCESS_TOKEN_TTL_SECONDS),
    RefreshToken: tokenFor(seed, "refresh", userId),
    RefreshTokenExpiresAt: isoInSeconds(REFRESH_TOKEN_TTL_SECONDS),
  };
}

const mod: PreviewFixtureModule = {
  name: "auth",
  operations: ["auth.login", "auth.refresh", "auth.logout", "auth.me"],
  register(): void {
    registerPreviewHandler("auth.login", async (ctx): Promise<AuthLoginResponse> => {
      rejectIfErrorSeed(ctx.Seed, ctx.RequestId);
      const { Email, Password } = ctx.Params;
      const creds = await readCredentials();
      const expected = creds[Email.toLowerCase()];
      if (!expected || expected !== Password) {
        previewError(
          ApiErrorCodeType.AuthInvalidCredentials,
          "Invalid email or password.",
          HTTP_UNAUTHORIZED,
          ctx.RequestId,
        );
      }
      const user = await findUserByEmail(Email);
      const isFailed = !user;
      if (isFailed) {
        previewError(
          ApiErrorCodeType.AuthInvalidCredentials,
          "Seeded credential has no matching MeUser (seed inconsistency).",
          HTTP_UNAUTHORIZED,
          ctx.RequestId,
        );
      }
      await write<MeUser>("me", "current", { ...user, UpdatedAt: nowIso() });
      const tokens = buildTokenPair(ctx.Seed, user.Id);
      const response: AuthLoginResponse = { ...tokens, User: user };
      console.info("preview-fixtures:auth.login", {
        RequestId: ctx.RequestId,
        UserId: user.Id,
        Seed: ctx.Seed,
      });

      return previewSuccess<"auth.login">(response);
    });

    registerPreviewHandler("auth.refresh", async (ctx): Promise<AuthRefreshResponse> => {
      rejectIfErrorSeed(ctx.Seed, ctx.RequestId);
      const user = await readCurrentUser();
      const isFailed = !user;
      if (isFailed) {
        previewError(
          ApiErrorCodeType.AuthSessionNotFound,
          "No active preview session to refresh.",
          HTTP_UNAUTHORIZED,
          ctx.RequestId,
        );
      }
      const tokens = buildTokenPair(ctx.Seed, user.Id);
      console.info("preview-fixtures:auth.refresh", {
        RequestId: ctx.RequestId,
        UserId: user.Id,
      });

      return previewSuccess<"auth.refresh">(tokens);
    });

    registerPreviewHandler("auth.logout", async (ctx): Promise<EmptyResponse> => {
      // Logout succeeds even under the error seed: closing a session is
      // idempotent and MUST NOT strand the user on an error banner.
      const user = await readCurrentUser();
      console.info("preview-fixtures:auth.logout", {
        RequestId: ctx.RequestId,
        UserId: user?.Id ?? null,
      });

      return previewSuccess<"auth.logout">({} as EmptyResponse);
    });

    registerPreviewHandler("auth.me", async (ctx): Promise<MeUser> => {
      rejectIfErrorSeed(ctx.Seed, ctx.RequestId);

      // Check for synthetic seed tokens first (Plan 18 Phase C)
      if (ctx.Token?.startsWith("seed_access_")) {
        const parts = ctx.Token.split("_");
        const identityId = parts[2]; // seed_access_{identityId}_{timestamp}

        const rows = await list<AdminUser>("admin-users");
        const users = rows.map(([_, u]) => u);
        const emailMap: Record<string, string> = {
          admin: "admin@lara.local",
          reseller: "reseller@lara.local",
          portal: "user@licensingportal.local",
        };

        const targetEmail = emailMap[identityId];
        const match = users.find((u) => u.Email === targetEmail);
        if (match) {
          return {
            Id: match.Id,
            Email: match.Email,
            DisplayName: match.DisplayName,
            Roles: match.Roles,
            ResellerId: match.ResellerId,
            CreatedAt: match.CreatedAt,
            UpdatedAt: match.UpdatedAt,
          };
        }
      }

      const user = await readCurrentUser();
      const isFailed = !user;
      if (isFailed) {
        previewError(
          ApiErrorCodeType.AuthUnauthorized,
          "No active preview session.",
          HTTP_UNAUTHORIZED,
          ctx.RequestId,
        );
      }

      return previewSuccess<"auth.me">(user);
    });
  },
};

export default mod;
