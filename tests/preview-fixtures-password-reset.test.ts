/**
 * Plan 16 Step 50 tests: preview password-reset.* handlers.
 */
import "fake-indexeddb/auto";
import { beforeEach, describe, expect, it } from "vitest";
import { resetAll, read, write } from "../src/lib/preview-store";
import { loadDefaultSeed } from "../src/lib/preview-seeds/default";
import { loadErrorSeed } from "../src/lib/preview-seeds/error";
import passwordResetModule, {
  _resetPasswordResetRateLimitForTest,
} from "../src/lib/preview-fixtures/password-reset";
import {
  clearPreviewHandlersForTest,
  dispatchPreview,
  type PreviewContext,
} from "../src/lib/preview-transport";
import { ApiErrorCodeType, LaraApiError } from "../src/lib/lara-api-error";
import type { OperationId } from "../src/generated/api/operations";

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
    RequestId: `req-pwr-${Math.random().toString(16).slice(2, 8)}`,
  };
}

interface StoredToken {
  Token: string;
  Email: string;
  ExpiresAt: string;
  ConsumedAt: string | null;
}

async function findIssuedToken(email: string): Promise<StoredToken | undefined> {
  const { list } = await import("../src/lib/preview-store");
  const rows = await list<StoredToken>("password-reset");
  return rows.map(([, v]) => v).find((r) => r.Email.toLowerCase() === email.toLowerCase());
}

describe("preview-fixtures password-reset (Plan 16 Step 50)", () => {
  beforeEach(async () => {
    clearPreviewHandlersForTest();
    _resetPasswordResetRateLimitForTest();
    await resetAll();
    passwordResetModule.register();
  });

  it("request issues a token for a known email", async () => {
    await loadDefaultSeed();
    await dispatchPreview("password-reset.request", ctx({ Email: "admin@lara.local" }));
    const row = await findIssuedToken("admin@lara.local");
    expect(row).toBeDefined();
    expect(row?.ConsumedAt).toBeNull();
  });

  it("request silently succeeds for an unknown email (no enumeration)", async () => {
    await loadDefaultSeed();
    await dispatchPreview(
      "password-reset.request",
      ctx({ Email: "ghost@lara.local" }),
    );
    const row = await findIssuedToken("ghost@lara.local");
    expect(row).toBeUndefined();
  });

  it("request rate-limits after 5 attempts (RateLimited 429)", async () => {
    await loadDefaultSeed();
    for (let i = 0; i < 5; i++) {
      await dispatchPreview(
        "password-reset.request",
        ctx({ Email: "admin@lara.local" }),
      );
    }
    await expect(
      dispatchPreview("password-reset.request", ctx({ Email: "admin@lara.local" })),
    ).rejects.toMatchObject({
      errorCode: ApiErrorCodeType.RateLimited,
      httpStatus: 429,
    } satisfies Partial<LaraApiError>);
  });

  it("confirm rewrites credentials and marks the token consumed", async () => {
    await loadDefaultSeed();
    await dispatchPreview("password-reset.request", ctx({ Email: "admin@lara.local" }));
    const row = (await findIssuedToken("admin@lara.local"))!;
    await dispatchPreview(
      "password-reset.confirm",
      ctx({ Token: row.Token, NewPassword: "brand-new-pw" }),
    );
    const creds = await read<Record<string, string>>("auth", "credentials");
    expect(creds?.["admin@lara.local"]).toBe("brand-new-pw");
    const after = (await findIssuedToken("admin@lara.local"))!;
    expect(after.ConsumedAt).not.toBeNull();
  });

  it("confirm on a replayed token rejects with VerifyKeyConsumed (410)", async () => {
    await loadDefaultSeed();
    await dispatchPreview("password-reset.request", ctx({ Email: "admin@lara.local" }));
    const row = (await findIssuedToken("admin@lara.local"))!;
    await dispatchPreview(
      "password-reset.confirm",
      ctx({ Token: row.Token, NewPassword: "once" }),
    );
    await expect(
      dispatchPreview(
        "password-reset.confirm",
        ctx({ Token: row.Token, NewPassword: "twice" }),
      ),
    ).rejects.toMatchObject({
      errorCode: ApiErrorCodeType.VerifyKeyConsumed,
      httpStatus: 410,
    } satisfies Partial<LaraApiError>);
  });

  it("confirm on an expired token rejects with VerifyKeyExpired (410)", async () => {
    await loadDefaultSeed();
    const expiredToken = "pwr_expired_fixture";
    await write("password-reset", expiredToken, {
      Token: expiredToken,
      Email: "admin@lara.local",
      ExpiresAt: new Date(Date.now() - 60_000).toISOString(),
      ConsumedAt: null,
    });
    await expect(
      dispatchPreview(
        "password-reset.confirm",
        ctx({ Token: expiredToken, NewPassword: "x" }),
      ),
    ).rejects.toMatchObject({
      errorCode: ApiErrorCodeType.VerifyKeyExpired,
      httpStatus: 410,
    } satisfies Partial<LaraApiError>);
  });

  it("confirm on an unknown token rejects with VerifyKeyMismatch (410)", async () => {
    await loadDefaultSeed();
    await expect(
      dispatchPreview(
        "password-reset.confirm",
        ctx({ Token: "does-not-exist", NewPassword: "x" }),
      ),
    ).rejects.toMatchObject({
      errorCode: ApiErrorCodeType.VerifyKeyMismatch,
      httpStatus: 410,
    } satisfies Partial<LaraApiError>);
  });

  it("error seed rejects both ops with ValidationFailed (422)", async () => {
    await loadErrorSeed();
    for (const op of [
      "password-reset.request",
      "password-reset.confirm",
    ] as OperationId[]) {
      await expect(
        dispatchPreview(
          op,
          ctx({ Email: "x@y.z", Token: "t", NewPassword: "p" } as never, "error"),
        ),
      ).rejects.toMatchObject({
        errorCode: ApiErrorCodeType.ValidationFailed,
        httpStatus: 422,
      } satisfies Partial<LaraApiError>);
    }
  });
});
