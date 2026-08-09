/**
 * Plan 16 Step 61 (v0.567.0): pin the useApi / useApiMutation hooks against
 * the api-client contract. We assert that:
 *   - useApi calls apiClient.call with (id, params, { signal })
 *   - LaraApiError propagates unchanged into `error`
 *   - useApiMutation forwards `{ params, call }` and surfaces retryAfterSeconds
 */
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { renderHook, waitFor } from "@testing-library/react";
import { afterEach, describe, expect, it, vi } from "vitest";
import React from "react";
import { useApi, useApiMutation, apiQueryKey } from "@/hooks/use-api";
import { apiClient } from "@/lib/api-client";
import { LaraApiError, ApiErrorCodeType } from "@/lib/lara-api-error";

function wrapper() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
  const Provider = ({ children }: { children: React.ReactNode }) =>
    React.createElement(QueryClientProvider, { client }, children);
  return { client, Provider };
}

afterEach(() => {
  vi.restoreAllMocks();
});

describe("useApi", () => {
  it("invokes apiClient.call with id + params and returns typed data", async () => {
    const spy = vi
      .spyOn(apiClient, "call")
      .mockResolvedValue({ RequestId: "req_1", Version: 1 } as never);
    const { Provider } = wrapper();
    const { result } = renderHook(
      () => useApi("admin.runtime-config.show", {} as never),
      { wrapper: Provider },
    );
    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(spy).toHaveBeenCalledWith(
      "admin.runtime-config.show",
      {},
      expect.objectContaining({ signal: expect.any(AbortSignal) }),
    );
  });

  it("propagates LaraApiError unchanged (retryAfterSeconds preserved)", async () => {
    const err = new LaraApiError(
      "rate limited",
      ApiErrorCodeType.RateLimited,
      429,
      "req_2",
      { retryAfterSeconds: 42 },
    );
    vi.spyOn(apiClient, "call").mockRejectedValue(err);
    const { Provider } = wrapper();
    const { result } = renderHook(
      () => useApi("admin.runtime-config.show", {} as never),
      { wrapper: Provider },
    );
    await waitFor(() => expect(result.current.isError).toBe(true));
    expect(result.current.error).toBe(err);
    expect(result.current.error?.rateLimit?.retryAfterSeconds).toBe(42);
  });

  it("apiQueryKey is stable and includes id + params", () => {
    const k = apiQueryKey("admin.runtime-config.show", { foo: 1 } as never);
    expect(k).toEqual(["api", "admin.runtime-config.show", { foo: 1 }]);
  });
});

describe("useApiMutation", () => {
  it("forwards { params, call } to apiClient.call and returns response", async () => {
    const spy = vi
      .spyOn(apiClient, "call")
      .mockResolvedValue({ RequestId: "req_3", Version: 2 } as never);
    const { Provider } = wrapper();
    const { result } = renderHook(
      () => useApiMutation("admin.runtime-config.update"),
      { wrapper: Provider },
    );
    await result.current.mutateAsync({
      params: { Mode: "dev" } as never,
      call: { ifMatch: 'W/"abc"' },
    });
    expect(spy).toHaveBeenCalledWith(
      "admin.runtime-config.update",
      { Mode: "dev" },
      { ifMatch: 'W/"abc"' },
    );
  });

  it("surfaces LaraApiError on failure without swallowing", async () => {
    const err = new LaraApiError("stale", ApiErrorCodeType.RuntimeConfigConflict, 412, "req_4");
    vi.spyOn(apiClient, "call").mockRejectedValue(err);
    const { Provider } = wrapper();
    const { result } = renderHook(
      () => useApiMutation("admin.runtime-config.update"),
      { wrapper: Provider },
    );
    await expect(
      result.current.mutateAsync({ params: {} as never }),
    ).rejects.toBe(err);
  });
});
