/**
 * Plan 16 Step 56: laraFetch preview-mode guard.
 *
 * Root cause it prevents (one sentence): resource libs in `src/lib/lara-*.ts`
 * call `laraFetch` directly, so in preview mode any UI wired through them
 * would silently hit an unreachable network instead of the preview transport.
 */
import { afterEach, describe, expect, it, vi } from "vitest";
import { laraFetch, PREVIEW_BYPASS_MESSAGE } from "@/lib/lara-fetch";
import { ApiErrorCodeType, LaraApiError } from "@/lib/lara-api-error";
import { freezeRuntimeMode, resetRuntimeMode, getCompileTimeDefault } from "@/lib/runtime-mode";
import { z } from "zod";

afterEach(() => {
  resetRuntimeMode();
  vi.restoreAllMocks();
});

describe("laraFetch preview-mode guard (INV-RM-05)", () => {
  it("throws LaraApiError(ServerError, 0) when mode is preview", async () => {
    freezeRuntimeMode({ ...getCompileTimeDefault(), Mode: "preview" });
    vi.spyOn(console, "error").mockImplementation(() => {});
    try {
      await laraFetch("/api/admin/licenses", z.unknown(), {});
      throw new Error("expected throw");
    } catch (err) {
      expect(err).toBeInstanceOf(LaraApiError);
      const e = err as LaraApiError;
      expect(e.errorCode).toBe(ApiErrorCodeType.ServerError);
      expect(e.httpStatus).toBe(0);
      expect(e.message).toContain(PREVIEW_BYPASS_MESSAGE);
      expect(e.message).toContain("/api/admin/licenses");
    }
  });

  it("logs the bypassed path so the offender is observable", async () => {
    freezeRuntimeMode({ ...getCompileTimeDefault(), Mode: "preview" });
    const spy = vi.spyOn(console, "error").mockImplementation(() => {});
    await laraFetch("/api/portal/updates", z.unknown(), {}).catch(() => {});
    expect(spy).toHaveBeenCalledWith("laraFetch preview bypass", { path: "/api/portal/updates" });
  });
});
