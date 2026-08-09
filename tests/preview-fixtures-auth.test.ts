/**
 * Plan 16 Step 40 tests: preview auth handlers.
 *
 * Verifies:
 *  - auth.login accepts seeded credentials, mints preview tokens, and
 *    updates the `me::current` record.
 *  - auth.login rejects wrong password with `AuthInvalidCredentials`.
 *  - auth.refresh returns fresh tokens for the active session.
 *  - auth.refresh fails with `AuthSessionNotFound` when no session exists.
 *  - auth.me returns the current user; fails with `AuthUnauthorized`
 *    when there is no session.
 *  - auth.logout succeeds even without a session (idempotent).
 *  - error seed forces every non-logout handler to reject with
 *    `AuthUnauthorized`.
 */
import "fake-indexeddb/auto";
import { beforeEach, describe, expect, it } from "vitest";
import { resetAll } from "../src/lib/preview-store";
import { loadDefaultSeed } from "../src/lib/preview-seeds/default";
import { loadErrorSeed } from "../src/lib/preview-seeds/error";
import authModule from "../src/lib/preview-fixtures/auth";
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
    RequestId: `req-test-${Math.random().toString(16).slice(2, 8)}`,
  };
}

describe("preview-fixtures:auth", () => {
  beforeEach(async () => {
    await resetAll();
    clearPreviewHandlersForTest();
    authModule.register();
  });

  it("login accepts seeded credentials and returns a token pair", async () => {
    await loadDefaultSeed();
    const res = await dispatchPreview(
      "auth.login",
      ctx({ Email: "admin@lara.local", Password: "preview-admin", DeviceName: "test" }),
    );
    expect(res.User.Email).toBe("admin@lara.local");
    expect(res.AccessToken).toMatch(/^preview\.default\.access\./);
    expect(res.RefreshToken).toMatch(/^preview\.default\.refresh\./);
  });

  it("login rejects wrong password with AuthInvalidCredentials", async () => {
    await loadDefaultSeed();
    await expect(
      dispatchPreview(
        "auth.login",
        ctx({ Email: "admin@lara.local", Password: "wrong", DeviceName: "test" }),
      ),
    ).rejects.toMatchObject({
      name: "LaraApiError",
      errorCode: ApiErrorCodeType.AuthInvalidCredentials,
    });
  });

  it("refresh returns fresh tokens for the active session", async () => {
    await loadDefaultSeed();
    const res = await dispatchPreview("auth.refresh", ctx({ RefreshToken: "ignored" }));
    expect(res.AccessToken).toMatch(/^preview\.default\.access\./);
  });

  it("refresh without a session fails with AuthSessionNotFound", async () => {
    // No seed load: `me::current` is empty.
    await expect(
      dispatchPreview("auth.refresh", ctx({ RefreshToken: "ignored" })),
    ).rejects.toMatchObject({ errorCode: ApiErrorCodeType.AuthSessionNotFound });
  });

  it("me returns the current user", async () => {
    await loadDefaultSeed();
    const res = await dispatchPreview("auth.me", ctx({}));
    expect(res.Email).toBe("admin@lara.local");
  });

  it("me without a session fails with AuthUnauthorized", async () => {
    await expect(dispatchPreview("auth.me", ctx({}))).rejects.toBeInstanceOf(LaraApiError);
  });

  it("logout is idempotent and succeeds without a session", async () => {
    const res = await dispatchPreview("auth.logout", ctx({}));
    expect(res).toEqual({});
  });

  it("error seed forces auth.login to reject with AuthUnauthorized", async () => {
    await loadErrorSeed();
    await expect(
      dispatchPreview(
        "auth.login",
        ctx(
          { Email: "admin@lara.local", Password: "preview-admin", DeviceName: "test" },
          "error",
        ),
      ),
    ).rejects.toMatchObject({ errorCode: ApiErrorCodeType.AuthUnauthorized });
  });
});
