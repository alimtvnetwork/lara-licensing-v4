import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import {
  clearPreviewHandlersForTest,
  dispatchPreview,
  findMissingPreviewHandlers,
  hasPreviewHandler,
  listRegisteredPreviewHandlers,
  registerPreviewHandler,
  type PreviewContext,
} from "@/lib/preview-transport";
import { Operations, type OperationId } from "@/generated/api/operations";
import { ApiErrorCodeType, LaraApiError } from "@/lib/lara-api-error";

/**
 * Plan 16 step 70. Contract tests for `src/lib/preview-transport.ts`.
 *
 * Root cause pinned by this test:
 *   Step 33 shipped `registerPreviewHandler` / `dispatchPreview` /
 *   `findMissingPreviewHandlers` / `clearPreviewHandlersForTest` with
 *   zero unit coverage. Any future refactor that swallows the
 *   missing-handler throw, drops the `Operations` guard at register
 *   time, or breaks INV-RM-04 accounting would ship silently and only
 *   surface as blank preview screens after handler registration in
 *   steps 40..50. This suite locks the four public exports.
 */

const CTX_BASE = {
  Params: {} as never,
  Headers: {},
  Signal: new AbortController().signal,
  Seed: "default",
  Scenario: null,
  RequestId: "req_test_preview_transport",
} as const;

function ctxFor<K extends OperationId>(): PreviewContext<K> {
  return CTX_BASE as unknown as PreviewContext<K>;
}

describe("preview-transport registry contract", () => {
  beforeEach(() => {
    clearPreviewHandlersForTest();
  });

  afterEach(() => {
    clearPreviewHandlersForTest();
    vi.restoreAllMocks();
  });

  it("dispatchPreview returns the registered handler payload and forwards ctx", async () => {
    const now = "2026-07-21T00:00:00Z";
    const meUser = {
      Id: "01JABCDEF00000000000000000",
      Email: "root@lara.test",
      DisplayName: "Root",
      Roles: ["admin"],
      ResellerId: null,
      CreatedAt: now,
      UpdatedAt: now,
    };
    const spy = vi.fn(async (ctx: PreviewContext<"auth.me">) => {
      expect(ctx.RequestId).toBe(CTX_BASE.RequestId);
      expect(ctx.Seed).toBe("default");
      return meUser as never;
    });
    registerPreviewHandler("auth.me", spy);

    const result = await dispatchPreview("auth.me", ctxFor<"auth.me">());

    expect(spy).toHaveBeenCalledTimes(1);
    expect(result).toEqual(meUser);
    expect(hasPreviewHandler("auth.me")).toBe(true);
    expect(listRegisteredPreviewHandlers()).toEqual(["auth.me"]);
  });

  it("dispatchPreview throws PreviewHandlerMissingError (ServerError, 0) and logs when no handler is registered", async () => {
    const errSpy = vi.spyOn(console, "error").mockImplementation(() => {});

    await expect(dispatchPreview("auth.me", ctxFor<"auth.me">())).rejects.toMatchObject({
      name: "PreviewHandlerMissingError",
      errorCode: ApiErrorCodeType.ServerError,
      httpStatus: 0,
      operationId: "auth.me",
    });

    // The thrown object is a real LaraApiError instance (not just shape-matched).
    try {
      await dispatchPreview("auth.me", ctxFor<"auth.me">());
    } catch (thrown) {
      expect(thrown).toBeInstanceOf(LaraApiError);
      expect((thrown as LaraApiError).message).toContain("INV-RM-04");
      expect((thrown as LaraApiError).message).toContain('"auth.me"');
    }

    // Observability: preview-transport MUST log the miss with OperationId + RequestId.
    expect(errSpy).toHaveBeenCalled();
    const [msg, meta] = errSpy.mock.calls[0]!;
    expect(msg).toBe("preview-transport:handler-missing");
    expect(meta).toMatchObject({ OperationId: "auth.me", RequestId: CTX_BASE.RequestId });
  });


  it("registerPreviewHandler rejects unknown operationId at register time", () => {
    expect(() =>
      registerPreviewHandler(
        "not.a.real.op" as OperationId,
        (async () => ({}) as never) as never,
      ),
    ).toThrow(/unknown operationId "not\.a\.real\.op"/);
    expect(listRegisteredPreviewHandlers()).toEqual([]);
  });

  it("findMissingPreviewHandlers returns every unregistered operationId (INV-RM-04)", () => {
    const totalOps = (Object.keys(Operations) as OperationId[]).length;
    expect(findMissingPreviewHandlers()).toHaveLength(totalOps);

    registerPreviewHandler("auth.me", async () => ({}) as never);
    const missing = findMissingPreviewHandlers();
    expect(missing).toHaveLength(totalOps - 1);
    expect(missing).not.toContain("auth.me");
  });

  it("clearPreviewHandlersForTest empties the registry between tests", () => {
    registerPreviewHandler("auth.me", async () => ({}) as never);
    registerPreviewHandler("auth.logout", async () => ({}) as never);
    expect(listRegisteredPreviewHandlers()).toHaveLength(2);

    clearPreviewHandlersForTest();
    expect(listRegisteredPreviewHandlers()).toEqual([]);
    expect(hasPreviewHandler("auth.me")).toBe(false);
  });
});
