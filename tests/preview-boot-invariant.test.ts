/**
 * Plan 17 Step 17: preview-boot invariant.
 *
 * `assertPreviewBootReady()` must fail loud (never silent) when the
 * fixture barrel or a domain module regresses to zero registrations.
 * Enforces INV-RM-04 / SA-013 at boot rather than per-dispatch.
 */
import { beforeEach, describe, expect, it, vi } from "vitest";
import {
  PreviewBootIncompleteError,
  assertPreviewBootReady,
  clearPreviewHandlersForTest,
  registerPreviewHandler,
} from "@/lib/preview-transport";
import { LaraApiError } from "@/lib/lara-api-error";

describe("preview-boot invariant (Plan 17 Step 17)", () => {
  beforeEach(() => clearPreviewHandlersForTest());

  it("throws PreviewBootIncompleteError when the registry is empty", () => {
    const spy = vi.spyOn(console, "error").mockImplementation(() => {});
    expect(() => assertPreviewBootReady(13)).toThrow(PreviewBootIncompleteError);
    spy.mockRestore();
  });

  it("throws when moduleCount is zero even if handlers exist", () => {
    const spy = vi.spyOn(console, "error").mockImplementation(() => {});
    registerPreviewHandler("auth.login", async () => ({}) as never);
    expect(() => assertPreviewBootReady(0)).toThrow(PreviewBootIncompleteError);
    spy.mockRestore();
  });

  it("carries structured module/handler/missing counts on the error", () => {
    const spy = vi.spyOn(console, "error").mockImplementation(() => {});
    try {
      assertPreviewBootReady(13);
      throw new Error("expected throw");
    } catch (err) {
      expect(err).toBeInstanceOf(LaraApiError);
      const boot = err as PreviewBootIncompleteError;
      expect(boot.moduleCount).toBe(13);
      expect(boot.handlerCount).toBe(0);
      expect(boot.missingOperationIds.length).toBeGreaterThan(0);
    } finally {
      spy.mockRestore();
    }
  });

  it("logs the structured boot-incomplete line", () => {
    const spy = vi.spyOn(console, "error").mockImplementation(() => {});
    try { assertPreviewBootReady(13); } catch { /* expected */ }
    const call = spy.mock.calls.find(([tag]) => tag === "preview-transport:boot-incomplete");
    expect(call).toBeDefined();
    expect(call?.[1]).toMatchObject({ ModuleCount: 13, HandlerCount: 0 });
    spy.mockRestore();
  });

  it("does not throw when both modules and handlers are non-empty", () => {
    registerPreviewHandler("auth.login", async () => ({}) as never);
    expect(() => assertPreviewBootReady(13)).not.toThrow();
  });
});
