/**
 * Plan 17 Step 15: preview-transport surfaces `PreviewHandlerMissingError`
 * with a first-class `operationId` property (and a stable structured
 * `console.error` line) when a dispatch hits an unregistered handler.
 */
import { beforeEach, describe, expect, it, vi } from "vitest";
import {
  PreviewHandlerMissingError,
  clearPreviewHandlersForTest,
  dispatchPreview,
} from "@/lib/preview-transport";
import { LaraApiError } from "@/lib/lara-api-error";

const ctx = {
  Params: {} as never,
  Headers: {},
  Signal: new AbortController().signal,
  Seed: "default" as const,
  Scenario: null,
  RequestId: "req_test_1",
};

describe("preview-transport handler-missing surface (Plan 17 Step 15)", () => {
  beforeEach(() => clearPreviewHandlersForTest());

  it("throws PreviewHandlerMissingError carrying the operationId", async () => {
    const spy = vi.spyOn(console, "error").mockImplementation(() => {});
    await expect(
      dispatchPreview("auth.login", ctx as never),
    ).rejects.toBeInstanceOf(PreviewHandlerMissingError);
    spy.mockRestore();
  });

  it("keeps LaraApiError compatibility so existing surfaces work", async () => {
    const spy = vi.spyOn(console, "error").mockImplementation(() => {});
    try {
      await dispatchPreview("auth.login", ctx as never);
      throw new Error("expected throw");
    } catch (err) {
      expect(err).toBeInstanceOf(LaraApiError);
      expect((err as PreviewHandlerMissingError).operationId).toBe("auth.login");
      expect((err as PreviewHandlerMissingError).requestId).toBe("req_test_1");
    } finally {
      spy.mockRestore();
    }
  });

  it("logs the structured handler-missing line with OperationId + RequestId", async () => {
    const spy = vi.spyOn(console, "error").mockImplementation(() => {});
    await dispatchPreview("auth.login", ctx as never).catch(() => {});
    const call = spy.mock.calls.find(([tag]) => tag === "preview-transport:handler-missing");
    expect(call).toBeDefined();
    expect(call?.[1]).toMatchObject({ OperationId: "auth.login", RequestId: "req_test_1" });
    spy.mockRestore();
  });
});
