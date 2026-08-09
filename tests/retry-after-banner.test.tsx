import { describe, expect, it, vi, beforeEach, afterEach } from "vitest";
import { act, render, screen, cleanup } from "@testing-library/react";
import { RetryAfterBanner } from "@/components/retry-after-banner";
import { ApiErrorCodeType, LaraApiError } from "@/lib/lara-api-error";

describe("<RetryAfterBanner />", () => {
  beforeEach(() => vi.useFakeTimers());
  afterEach(() => {
    vi.useRealTimers();
    cleanup();
  });

  it("renders nothing when error is not RateLimited", () => {
    const err = new LaraApiError("x", ApiErrorCodeType.ValidationFailed, 400, "r");
    const { container } = render(<RetryAfterBanner error={err} />);
    expect(container.firstChild).toBeNull();
  });

  it("shows bucket, request id, and disabled retry button until countdown elapses", () => {
    const err = new LaraApiError("slow", ApiErrorCodeType.RateLimited, 429, "req-42", {
      retryAfterSeconds: 3,
      bucket: "issue",
    });
    const onRetry = vi.fn();
    render(<RetryAfterBanner error={err} onRetry={onRetry} />);

    expect(screen.getByText("issue")).toBeDefined();
    expect(screen.getByText("req-42")).toBeDefined();
    const button = screen.getByRole("button") as HTMLButtonElement;
    expect(button.disabled).toBe(true);
    expect(button.textContent).toBe("Retry in 3s");

    act(() => {
      vi.advanceTimersByTime(3000);
    });
    expect(button.disabled).toBe(false);
    expect(button.textContent).toBe("Retry now");
    button.click();
    expect(onRetry).toHaveBeenCalledOnce();
  });

  it("does not fabricate a countdown when Retry-After header is missing", () => {
    const err = new LaraApiError("slow", ApiErrorCodeType.RateLimited, 429, "req-9", {});
    render(<RetryAfterBanner error={err} onRetry={() => {}} />);
    expect(screen.getByText(/Retry-After was not provided/)).toBeDefined();
    const button = screen.getByRole("button") as HTMLButtonElement;
    expect(button.disabled).toBe(false);
  });
});
