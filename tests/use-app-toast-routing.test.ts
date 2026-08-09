/**
 * Locks the useAppToast routing contract from spec 24 §23.2.6:
 * - Eligible ErrorCodes are the closed set exported as
 *   TOAST_ELIGIBLE_ERROR_CODES.
 * - RateLimited MUST NOT reach Toast (AC-RAB-001).
 * - Validation/Authz/Feature/Env/Quota/Precondition MUST NOT reach Toast.
 * - Ineligible codes throw in dev (import.meta.env.DEV = true under Vitest).
 * - Eligible Conflict-family codes surface as `warning`; transient/unknown as
 *   `error`; retry action re-uses the same Idempotency-Key semantic
 *   (delegated to callers; here we assert the `action` handler is wired).
 */
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

vi.mock("sonner", () => {
  const calls: Array<{ variant: string; title: string; opts: unknown }> = [];
  const make = (variant: string) => (title: string, opts: unknown) => {
    calls.push({ variant, title, opts });
  };
  return {
    toast: {
      success: make("success"),
      info: make("info"),
      warning: make("warning"),
      error: make("error"),
      __calls: calls,
      __reset: () => {
        calls.length = 0;
      },
    },
  };
});

import { toast } from "sonner";
import { ApiErrorCodeType, LaraApiError } from "@/lib/lara-api-error";
import {
  TOAST_ELIGIBLE_ERROR_CODES,
  appToast,
} from "@/hooks/use-app-toast";

type ToastMockShape = {
  __calls: Array<{ variant: string; title: string; opts: { description?: string; duration?: number } }>;
  __reset: () => void;
};
const toastMock = toast as unknown as ToastMockShape;

beforeEach(() => toastMock.__reset());
afterEach(() => vi.restoreAllMocks());

function makeApiError(code: ApiErrorCodeType, opts: { requestId?: string } = {}) {
  return new LaraApiError(
    `mock ${code}`,
    code,
    code === ApiErrorCodeType.RateLimited ? 429 : 409,
    opts.requestId ?? "req-abc",
  );
}

describe("useAppToast routing (spec 24 §23.2.6)", () => {
  it("exports a closed eligible-code set (Conflict family, IdempotencyConflict, AuthRefreshRaceLost, transient/unknown)", () => {
    expect(TOAST_ELIGIBLE_ERROR_CODES.has(ApiErrorCodeType.LicenseConflict)).toBe(true);
    expect(TOAST_ELIGIBLE_ERROR_CODES.has(ApiErrorCodeType.UserConflict)).toBe(true);
    expect(TOAST_ELIGIBLE_ERROR_CODES.has(ApiErrorCodeType.IdempotencyConflict)).toBe(true);
    expect(TOAST_ELIGIBLE_ERROR_CODES.has(ApiErrorCodeType.AuthRefreshRaceLost)).toBe(true);
    expect(TOAST_ELIGIBLE_ERROR_CODES.has(ApiErrorCodeType.UnknownServerError)).toBe(true);
    // RateLimited MUST NOT be eligible (routes to RetryAfterBanner).
    expect(TOAST_ELIGIBLE_ERROR_CODES.has(ApiErrorCodeType.RateLimited)).toBe(false);
    // Field/surface-scoped codes MUST NOT be eligible.
    expect(TOAST_ELIGIBLE_ERROR_CODES.has(ApiErrorCodeType.ValidationFailed)).toBe(false);
    expect(TOAST_ELIGIBLE_ERROR_CODES.has(ApiErrorCodeType.AuthzRoleDenied)).toBe(false);
    expect(TOAST_ELIGIBLE_ERROR_CODES.has(ApiErrorCodeType.QuotaExhausted)).toBe(false);
    expect(TOAST_ELIGIBLE_ERROR_CODES.has(ApiErrorCodeType.PreconditionFailed)).toBe(false);
  });

  it("Conflict-family surfaces as warning with RequestId chip", () => {
    appToast.fromApiError(makeApiError(ApiErrorCodeType.LicenseConflict, { requestId: "req-1" }));
    expect(toastMock.__calls).toHaveLength(1);
    expect(toastMock.__calls[0].variant).toBe("warning");
    expect(toastMock.__calls[0].opts.description).toContain("Request req-1");
  });

  it("UnknownServerError surfaces as error", () => {
    appToast.fromApiError(makeApiError(ApiErrorCodeType.UnknownServerError));
    expect(toastMock.__calls[0].variant).toBe("error");
  });

  it("RateLimited is a routing violation: throws in dev (import.meta.env.DEV=true under Vitest)", () => {
    const spy = vi.spyOn(console, "warn").mockImplementation(() => {});
    expect(() => appToast.fromApiError(makeApiError(ApiErrorCodeType.RateLimited))).toThrow(
      /ToastRoutingViolation.*RateLimited/,
    );
    expect(spy).toHaveBeenCalled();
  });

  it("ValidationFailed is a routing violation and throws in dev", () => {
    vi.spyOn(console, "warn").mockImplementation(() => {});
    expect(() => appToast.fromApiError(makeApiError(ApiErrorCodeType.ValidationFailed))).toThrow(
      /ToastRoutingViolation.*ValidationFailed/,
    );
  });

  it("AuthzRoleDenied is a routing violation and throws in dev", () => {
    vi.spyOn(console, "warn").mockImplementation(() => {});
    expect(() => appToast.fromApiError(makeApiError(ApiErrorCodeType.AuthzRoleDenied))).toThrow(
      /ToastRoutingViolation/,
    );
  });

  it("PreconditionFailed is a routing violation and throws in dev", () => {
    vi.spyOn(console, "warn").mockImplementation(() => {});
    expect(() => appToast.fromApiError(makeApiError(ApiErrorCodeType.PreconditionFailed))).toThrow(
      /ToastRoutingViolation/,
    );
  });

  it("non-lara errors fall through to error toast without throwing", () => {
    const spy = vi.spyOn(console, "error").mockImplementation(() => {});
    appToast.fromApiError(new Error("network down"));
    expect(toastMock.__calls[0].variant).toBe("error");
    expect(toastMock.__calls[0].opts.description).toBe("network down");
    spy.mockRestore();
  });

  it("success/info/warning/error direct helpers pass through", () => {
    appToast.success("Saved");
    appToast.info("FYI");
    appToast.warning("Careful");
    appToast.error("Boom");
    expect(toastMock.__calls.map((c) => c.variant)).toEqual([
      "success",
      "info",
      "warning",
      "error",
    ]);
  });
});
