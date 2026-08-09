// Plan 11 step 29: GlobalErrorModal renders only fatal entries from
// `error-store` (NoRetry / FatalClear) and hides for retryable ones so
// step 30 (toast) can own those without double-notification.

import { describe, it, expect, beforeEach, afterEach, vi } from "vitest";
import { render, screen, act, cleanup } from "@testing-library/react";

import { GlobalErrorModal } from "@/components/global/GlobalErrorModal";
import { ApiErrorCodeType, LaraApiError } from "@/lib/lara-api-error";
import {
  __resetErrorStoreForTests,
  pushLaraApiError,
} from "@/lib/error-store";

beforeEach(() => {
  __resetErrorStoreForTests();
});

afterEach(() => {
  cleanup();
  __resetErrorStoreForTests();
});

describe("GlobalErrorModal (Plan 11 step 29)", () => {
  it("stays closed when no error is present", () => {
    render(<GlobalErrorModal />);
    expect(screen.queryByTestId("global-error-modal")).toBeNull();
  });

  it("opens on a NoRetry (fatal) entry and shows ErrorCode + Request + Error ID", () => {
    render(<GlobalErrorModal />);
    act(() => {
      pushLaraApiError(
        new LaraApiError(
          "Field invalid",
          ApiErrorCodeType.ValidationFailed,
          422,
          "req-abc",
          undefined,
          undefined,
          [{ Field: "email" }],
        ),
      );
    });
    expect(screen.getByTestId("global-error-modal")).toBeTruthy();
    expect(screen.getByText(ApiErrorCodeType.ValidationFailed)).toBeTruthy();
    expect(screen.getByTestId("global-error-request-id").textContent).toBe("req-abc");
  });

  it("shows the Error ID row and Copy button for a FatalClear 5xx entry", () => {
    render(<GlobalErrorModal />);
    act(() => {
      pushLaraApiError(
        new LaraApiError(
          "Refresh reused",
          ApiErrorCodeType.AuthRefreshReused,
          401,
          "req-x",
          undefined,
          "33333333-3333-4333-8333-333333333333",
        ),
      );
    });
    expect(screen.getByTestId("global-error-error-id").textContent).toBe(
      "33333333-3333-4333-8333-333333333333",
    );
    expect(screen.getByTestId("global-error-copy-id")).toBeTruthy();
  });

  it("stays closed for a retryable entry (ExpBackoff 500) so the toast can own it", () => {
    render(<GlobalErrorModal />);
    act(() => {
      pushLaraApiError(
        new LaraApiError("boom", ApiErrorCodeType.ServerError, 500, "req-r"),
      );
    });
    expect(screen.queryByTestId("global-error-modal")).toBeNull();
  });

  it("copies the Error ID via navigator.clipboard.writeText", async () => {
    const writeText = vi.fn().mockResolvedValue(undefined);
    Object.assign(navigator, { clipboard: { writeText } });
    render(<GlobalErrorModal />);
    act(() => {
      pushLaraApiError(
        new LaraApiError(
          "fatal",
          ApiErrorCodeType.AuthRefreshReused,
          401,
          "req-c",
          undefined,
          "44444444-4444-4444-8444-444444444444",
        ),
      );
    });
    const btn = screen.getByTestId("global-error-copy-id") as HTMLButtonElement;
    await act(async () => {
      btn.click();
    });
    expect(writeText).toHaveBeenCalledWith("44444444-4444-4444-8444-444444444444");
  });

  it("shows Timestamp + Source rows and Copy All copies the full diagnostic payload", async () => {
    const writeText = vi.fn().mockResolvedValue(undefined);
    Object.assign(navigator, { clipboard: { writeText } });
    render(<GlobalErrorModal />);
    act(() => {
      pushLaraApiError(
        new LaraApiError(
          "boom",
          ApiErrorCodeType.AuthRefreshReused,
          401,
          "req-t",
          undefined,
          "55555555-5555-4555-8555-555555555555",
        ),
        1_732_000_000_000,
        "AdminUsersPage",
      );
    });
    expect(screen.getByTestId("global-error-timestamp").textContent).toBe(
      new Date(1_732_000_000_000).toISOString(),
    );
    expect(screen.getByTestId("global-error-source").textContent).toBe("AdminUsersPage");
    const copyAll = screen.getByTestId("global-error-copy-all") as HTMLButtonElement;
    await act(async () => {
      copyAll.click();
    });
    expect(writeText).toHaveBeenCalledTimes(1);
    const payload = JSON.parse(writeText.mock.calls[0]![0] as string);
    expect(payload.ErrorCode).toBe(ApiErrorCodeType.AuthRefreshReused);
    expect(payload.RequestId).toBe("req-t");
    expect(payload.ErrorId).toBe("55555555-5555-4555-8555-555555555555");
    expect(payload.SourceComponent).toBe("AdminUsersPage");
    expect(payload.HttpStatus).toBe(401);
    expect(payload.Timestamp).toBe(new Date(1_732_000_000_000).toISOString());
  });

  it("falls back to a textarea + execCommand when navigator.clipboard is unavailable", async () => {
    Object.assign(navigator, { clipboard: undefined });
    const execCommand = vi.fn().mockReturnValue(true);
    Object.assign(document, { execCommand });
    render(<GlobalErrorModal />);
    act(() => {
      pushLaraApiError(
        new LaraApiError(
          "boom",
          ApiErrorCodeType.AuthRefreshReused,
          401,
          "req-f",
          undefined,
          "66666666-6666-4666-8666-666666666666",
        ),
      );
    });
    const copyAll = screen.getByTestId("global-error-copy-all") as HTMLButtonElement;
    await act(async () => {
      copyAll.click();
    });
    expect(execCommand).toHaveBeenCalledWith("copy");
  });
});
