/**
 * Plan 16 Step 46 tests: preview admin.impersonation.* handlers.
 *
 * Verifies:
 *  - start persists an active session with actor=me and target=admin-user.
 *  - start rejects when actor lacks the admin role (403).
 *  - start rejects self-target (422).
 *  - start rejects empty Reason (422).
 *  - start rejects when a session is already active (422).
 *  - stop clears the active session and is followed by a NOT_FOUND on repeat.
 *  - error seed rejects both ops with AuthForbidden (403).
 */
import "fake-indexeddb/auto";
import { beforeEach, describe, expect, it } from "vitest";
import { resetAll, read, write } from "../src/lib/preview-store";
import { loadDefaultSeed } from "../src/lib/preview-seeds/default";
import { loadErrorSeed } from "../src/lib/preview-seeds/error";
import impersonationModule from "../src/lib/preview-fixtures/impersonation";
import authModule from "../src/lib/preview-fixtures/auth";
import {
  clearPreviewHandlersForTest,
  dispatchPreview,
  type PreviewContext,
} from "../src/lib/preview-transport";
import { ApiErrorCodeType, LaraApiError } from "../src/lib/lara-api-error";
import type { OperationId } from "../src/generated/api/operations";
import type { ImpersonationSession, MeUser } from "../src/generated/api/schema";

const ADMIN_ID = "01H0000000000000000ADMIN1";
const RESELLER_ID = "01H0000000000000000RSLL01";

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
    RequestId: `req-imp-${Math.random().toString(16).slice(2, 8)}`,
  };
}

describe("preview-fixtures impersonation (Plan 16 Step 46)", () => {
  beforeEach(async () => {
    clearPreviewHandlersForTest();
    await resetAll();
    impersonationModule.register();
    authModule.register();
  });

  it("start persists an active session (admin -> reseller)", async () => {
    await loadDefaultSeed();
    const res = await dispatchPreview("admin.impersonation.start", ctx({
      TargetUserId: RESELLER_ID,
      Reason: "customer support ticket #42",
    }));
    expect(res.ActorUser.Id).toBe(ADMIN_ID);
    expect(res.TargetUser.Id).toBe(RESELLER_ID);
    expect(res.SessionId).toMatch(/^01H0/);
    const stored = await read<ImpersonationSession>("impersonation", "active");
    expect(stored?.SessionId).toBe(res.SessionId);
  });

  it("start rejects non-admin actor with 403 AuthForbidden", async () => {
    await loadDefaultSeed();
    const reseller = await read<MeUser>("admin-users", RESELLER_ID);
    await write<MeUser>("me", "current", reseller!);
    await expect(
      dispatchPreview("admin.impersonation.start", ctx({
        TargetUserId: ADMIN_ID,
        Reason: "nope",
      })),
    ).rejects.toMatchObject({ errorCode: ApiErrorCodeType.AuthForbidden, httpStatus: 403 });
  });

  it("start rejects self-target with 422 ValidationFailed", async () => {
    await loadDefaultSeed();
    await expect(
      dispatchPreview("admin.impersonation.start", ctx({
        TargetUserId: ADMIN_ID,
        Reason: "self",
      })),
    ).rejects.toMatchObject({ errorCode: ApiErrorCodeType.ValidationFailed, httpStatus: 422 });
  });

  it("start rejects empty Reason", async () => {
    await loadDefaultSeed();
    await expect(
      dispatchPreview("admin.impersonation.start", ctx({
        TargetUserId: RESELLER_ID,
        Reason: "   ",
      })),
    ).rejects.toBeInstanceOf(LaraApiError);
  });

  it("start rejects when a session is already active (422)", async () => {
    await loadDefaultSeed();
    await dispatchPreview("admin.impersonation.start", ctx({
      TargetUserId: RESELLER_ID,
      Reason: "first",
    }));
    await expect(
      dispatchPreview("admin.impersonation.start", ctx({
        TargetUserId: RESELLER_ID,
        Reason: "second",
      })),
    ).rejects.toMatchObject({ errorCode: ApiErrorCodeType.ValidationFailed, httpStatus: 422 });
  });

  it("stop clears the active session; second stop is 404", async () => {
    await loadDefaultSeed();
    await dispatchPreview("admin.impersonation.start", ctx({
      TargetUserId: RESELLER_ID,
      Reason: "ok",
    }));
    await dispatchPreview("admin.impersonation.stop", ctx({}));
    expect(await read("impersonation", "active")).toBeUndefined();
    await expect(
      dispatchPreview("admin.impersonation.stop", ctx({})),
    ).rejects.toMatchObject({ httpStatus: 404 });
  });

  it("error seed rejects both ops with AuthForbidden (403)", async () => {
    await loadErrorSeed();
    await expect(
      dispatchPreview("admin.impersonation.start", ctx({
        TargetUserId: RESELLER_ID,
        Reason: "x",
      }, "error")),
    ).rejects.toMatchObject({ errorCode: ApiErrorCodeType.AuthForbidden, httpStatus: 403 });
    await expect(
      dispatchPreview("admin.impersonation.stop", ctx({}, "error")),
    ).rejects.toMatchObject({ errorCode: ApiErrorCodeType.AuthForbidden, httpStatus: 403 });
  });
});
