/**
 * Plan 17 Step 19: per-handler timing logs.
 *
 * Every `dispatchPreview` call must emit a `console.info` line under
 * `preview-transport:<operationId>` at start and end, carrying the
 * request id, seed, scenario, status, and duration in ms. The debug
 * drawer log tail keys off this stable tag; regressions here render the
 * drawer's timing column blank.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import {
  clearPreviewHandlersForTest,
  dispatchPreview,
  registerPreviewHandler,
} from "@/lib/preview-transport";

const ctx = {
  Params: {} as never,
  Headers: {},
  Signal: new AbortController().signal,
  Seed: "default" as const,
  Scenario: null,
  RequestId: "req_timing_1",
};

describe("preview-transport per-handler timing (Plan 17 Step 19)", () => {
  let info: ReturnType<typeof vi.spyOn>;
  beforeEach(() => {
    clearPreviewHandlersForTest();
    info = vi.spyOn(console, "info").mockImplementation(() => {});
  });
  afterEach(() => info.mockRestore());

  it("logs start + end with DurationMs on success", async () => {
    const now = "2026-07-21T00:00:00Z";
    const tokens = {
      AccessToken: "at",
      AccessTokenExpiresAt: now,
      RefreshToken: "rt",
      RefreshTokenExpiresAt: now,
      User: {
        Id: "01JABCDEF00000000000000000",
        Email: "a@b.co",
        DisplayName: "A",
        Roles: [],
        ResellerId: null,
        CreatedAt: now,
        UpdatedAt: now,
      },
    };
    registerPreviewHandler("auth.login", async () => tokens as never);
    await dispatchPreview("auth.login", ctx as never);
    const tag = "preview-transport:auth.login";
    const start = info.mock.calls.find(([t, p]) => t === tag && (p as { Phase: string }).Phase === "start");
    const end = info.mock.calls.find(([t, p]) => t === tag && (p as { Phase: string }).Phase === "end");
    expect(start?.[1]).toMatchObject({ OperationId: "auth.login", RequestId: "req_timing_1", Seed: "default" });
    expect(end?.[1]).toMatchObject({ OperationId: "auth.login", Status: "ok" });
    expect(typeof (end?.[1] as { DurationMs: number }).DurationMs).toBe("number");
  });

  it("logs Status:error + ErrorName and re-throws on handler failure", async () => {
    registerPreviewHandler("auth.login", async () => {
      const e = new Error("boom");
      e.name = "TestBoom";
      throw e;
    });
    await expect(dispatchPreview("auth.login", ctx as never)).rejects.toThrow("boom");
    const end = info.mock.calls.find(([t, p]) => t === "preview-transport:auth.login" && (p as { Phase: string }).Phase === "end");
    expect(end?.[1]).toMatchObject({ Status: "error", ErrorName: "TestBoom" });
  });
});
