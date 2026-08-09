import { beforeEach, describe, expect, it, vi } from "vitest";

import { ApiErrorCodeType, LaraApiError } from "@/lib/lara-api-error";
import {
  __resetErrorStoreForTests,
  pushLaraApiError,
} from "@/lib/error-store";
import {
  __resetLaraErrorToastForTests,
  useLaraErrorToast,
} from "@/hooks/use-lara-error-toast";
import { renderHook } from "@testing-library/react";

const toastMock = vi.hoisted(() => ({
  warning: vi.fn(),
  error: vi.fn(),
  success: vi.fn(),
  info: vi.fn(),
}));

vi.mock("sonner", () => ({ toast: toastMock }));

beforeEach(() => {
  __resetErrorStoreForTests();
  __resetLaraErrorToastForTests();
  toastMock.warning.mockClear();
  toastMock.error.mockClear();
});

function makeError(
  code: ApiErrorCodeType,
  httpStatus: number,
  extras: { requestId?: string; details?: ReadonlyArray<unknown> } = {},
): LaraApiError {
  return new LaraApiError(
    `${code} failed`,
    code,
    httpStatus,
    extras.requestId,
    undefined,
    undefined,
    extras.details,
  );
}

describe("useLaraErrorToast", () => {
  it("does NOT toast RateLimited (banner-owned, spec §23.4)", () => {
    renderHook(() => useLaraErrorToast());
    pushLaraApiError(makeError(ApiErrorCodeType.RateLimited, 429, { requestId: "req-1" }));
    expect(toastMock.warning).not.toHaveBeenCalled();
    expect(toastMock.error).not.toHaveBeenCalled();
  });

  it("does NOT toast AbuseBlocked or MachineRebindCooldownActive (banner-owned)", () => {
    renderHook(() => useLaraErrorToast());
    pushLaraApiError(makeError(ApiErrorCodeType.AbuseBlocked, 429));
    pushLaraApiError(makeError(ApiErrorCodeType.MachineRebindCooldownActive, 409));
    expect(toastMock.warning).not.toHaveBeenCalled();
    expect(toastMock.error).not.toHaveBeenCalled();
  });

  it("renders a warning toast for non-banner RetryAfter (LoginCaptchaRequired)", () => {
    renderHook(() => useLaraErrorToast());
    pushLaraApiError(
      makeError(ApiErrorCodeType.LoginCaptchaRequired, 400, { requestId: "req-2" }),
    );
    expect(toastMock.warning).toHaveBeenCalledTimes(1);
    const [title, opts] = toastMock.warning.mock.calls[0]!;
    expect(title).toBe("Please retry shortly");
    expect(opts.description).toContain("req-2");
  });

  it("renders an error toast for ExpBackoff entries", () => {
    renderHook(() => useLaraErrorToast());
    pushLaraApiError(makeError(ApiErrorCodeType.ServerError, 500));
    expect(toastMock.error).toHaveBeenCalledTimes(1);
  });

  it("does not surface NoRetry entries (owned by modal)", () => {
    renderHook(() => useLaraErrorToast());
    pushLaraApiError(makeError(ApiErrorCodeType.ValidationFailed, 422));
    expect(toastMock.warning).not.toHaveBeenCalled();
    expect(toastMock.error).not.toHaveBeenCalled();
  });

  it("does not surface FatalClear entries", () => {
    renderHook(() => useLaraErrorToast());
    pushLaraApiError(makeError(ApiErrorCodeType.AuthRefreshReused, 401));
    expect(toastMock.warning).not.toHaveBeenCalled();
    expect(toastMock.error).not.toHaveBeenCalled();
  });
});

