/**
 * Preview fixtures: password reset domain (Plan 18 Phase D).
 *
 * Registers handlers for the password reset flow:
 *   - password-reset.request (POST /api/password-reset/request)
 *   - password-reset.confirm (POST /api/password-reset/confirm)
 *
 * Behaviour:
 *   * Request always succeeds in preview mode unless the error seed is active.
 *   * Confirm verifies the token shape and updates the seeded credential in
 *     the `auth::credentials` store if a matching email is found.
 */

import type { PreviewFixtureModule } from "./_module";
import { previewError, previewSuccess } from "./_shared";
import { read, write, list } from "@/lib/preview-store";
import { registerPreviewHandler } from "@/lib/preview-transport";
import { ApiErrorCodeType } from "@/lib/lara-api-error";
import type {
  EmptyResponse,
  PasswordResetConfirmRequest,
  PasswordResetRequestRequest,
} from "@/generated/api/schema";

const HTTP_GONE = 410;
const HTTP_RATE_LIMITED = 429;
const HTTP_UNPROCESSABLE = 422;

let attempts = 0;

/** @internal */
export function _resetPasswordResetRateLimitForTest(): void {
  attempts = 0;
}

function rejectIfErrorSeed(seed: string, requestId: string): void {
  if (seed === "error") {
    previewError(
      ApiErrorCodeType.ValidationFailed,
      "Preview error seed active: password reset blocked.",
      HTTP_UNPROCESSABLE,
      requestId,
    );
  }
}

interface StoredToken {
  Token: string;
  Email: string;
  ExpiresAt: string;
  ConsumedAt: string | null;
}

const mod: PreviewFixtureModule = {
  name: "password-reset",
  operations: ["password-reset.request", "password-reset.confirm"],
  register(): void {
    registerPreviewHandler("password-reset.request", async (ctx): Promise<EmptyResponse> => {
      rejectIfErrorSeed(ctx.Seed, ctx.RequestId);

      attempts++;
      if (attempts > 5) {
        previewError(
          ApiErrorCodeType.RateLimited,
          "Too many reset requests.",
          HTTP_RATE_LIMITED,
          ctx.RequestId,
        );
      }

      const params = ctx.Params as PasswordResetRequestRequest;

      // We only "send" an email if it's one of our demo emails
      const demoEmails = ["admin@lara.local", "reseller@lara.local", "user@lara.local"];
      if (demoEmails.includes(params.Email.toLowerCase())) {
        const token = `pwr_${Math.random().toString(36).slice(2, 10)}`;
        await write<StoredToken>("password-reset", token, {
          Token: token,
          Email: params.Email,
          ExpiresAt: new Date(Date.now() + 3600_000).toISOString(),
          ConsumedAt: null,
        });
      }

      console.info("preview-fixtures:password-reset.request", {
        RequestId: ctx.RequestId,
        Email: params.Email,
      });

      return previewSuccess<"password-reset.request">({} as EmptyResponse);
    });

    registerPreviewHandler("password-reset.confirm", async (ctx): Promise<EmptyResponse> => {
      rejectIfErrorSeed(ctx.Seed, ctx.RequestId);
      const { Token, NewPassword } = ctx.Params as PasswordResetConfirmRequest;

      const stored = await read<StoredToken>("password-reset", Token);
      const isFailed = !stored;
      if (isFailed) {
        previewError(
          ApiErrorCodeType.VerifyKeyMismatch,
          "Token mismatch.",
          HTTP_GONE,
          ctx.RequestId,
        );
      }

      if (stored.ConsumedAt) {
        previewError(
          ApiErrorCodeType.VerifyKeyConsumed,
          "Token already used.",
          HTTP_GONE,
          ctx.RequestId,
        );
      }

      if (new Date(stored.ExpiresAt) < new Date()) {
        previewError(ApiErrorCodeType.VerifyKeyExpired, "Token expired.", HTTP_GONE, ctx.RequestId);
      }

      // In preview, we update the demo identity
      const creds = (await read<Record<string, string>>("auth", "credentials")) || {};
      creds[stored.Email] = NewPassword;
      await write("auth", "credentials", creds);

      // Mark token as consumed
      stored.ConsumedAt = new Date().toISOString();
      await write("password-reset", Token, stored);

      console.info("preview-fixtures:password-reset.confirm", {
        RequestId: ctx.RequestId,
        Token: Token,
      });

      return previewSuccess<"password-reset.confirm">({} as EmptyResponse);
    });
  },
};

export default mod;
