/**
 * Plan 16 Step 63 (v0.569.0): pin the admin.quotas surface conflict handling
 * to the closed-set codes the preview fixture and backend actually return:
 *   - 412 PreconditionFailed  -> inline conflict banner (no toast).
 *   - 422 ValidationFailed    -> floor notice (no toast).
 *   - other LaraApiError      -> fromApiError toast.
 *
 * We verify by driving `useApiMutation("admin.quotas.update")` directly and
 * asserting `LaraApiError` propagates unchanged (per hook contract from Step 61).
 */
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { renderHook, waitFor } from "@testing-library/react";
import { afterEach, describe, expect, it, vi } from "vitest";
import React from "react";
import { useApiMutation } from "@/hooks/use-api";
import { apiClient } from "@/lib/api-client";
import { ApiErrorCodeType, LaraApiError } from "@/lib/lara-api-error";

function wrapper() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
  const Provider = ({ children }: { children: React.ReactNode }) =>
    React.createElement(QueryClientProvider, { client }, children);
  return { client, Provider };
}

afterEach(() => {
  vi.restoreAllMocks();
});

describe("admin.quotas.update conflict parity", () => {
  it("propagates PreconditionFailed (412) unchanged for If-Match mismatch", async () => {
    const err = new LaraApiError("If-Match 1 != 2", ApiErrorCodeType.PreconditionFailed, 412, "req_1");
    vi.spyOn(apiClient, "call").mockRejectedValue(err);
    const { Provider } = wrapper();
    const { result } = renderHook(() => useApiMutation("admin.quotas.update"), { wrapper: Provider });
    await expect(
      result.current.mutateAsync({ params: { Id: "q1", IfMatch: "1", Allocated: 10 } }),
    ).rejects.toBe(err);
    await waitFor(() => expect(result.current.error).toBe(err));
    expect(result.current.error?.errorCode).toBe(ApiErrorCodeType.PreconditionFailed);
    expect(result.current.error?.httpStatus).toBe(412);
  });

  it("propagates ValidationFailed (422) unchanged for below-floor Allocated", async () => {
    const err = new LaraApiError(
      "Allocated 5 cannot be below net consumption 8",
      ApiErrorCodeType.ValidationFailed,
      422,
      "req_2",
    );
    vi.spyOn(apiClient, "call").mockRejectedValue(err);
    const { Provider } = wrapper();
    const { result } = renderHook(() => useApiMutation("admin.quotas.update"), { wrapper: Provider });
    await expect(
      result.current.mutateAsync({ params: { Id: "q1", IfMatch: "3", Allocated: 5 } }),
    ).rejects.toBe(err);
    await waitFor(() => expect(result.current.error).toBe(err));
    expect(result.current.error?.errorCode).toBe(ApiErrorCodeType.ValidationFailed);
    expect(result.current.error?.httpStatus).toBe(422);
  });

  it("forwards typed params to apiClient.call on success", async () => {
    const spy = vi
      .spyOn(apiClient, "call")
      .mockResolvedValue({ Id: "q1", Version: 4, Allocated: 12 } as never);
    const { Provider } = wrapper();
    const { result } = renderHook(() => useApiMutation("admin.quotas.update"), { wrapper: Provider });
    await result.current.mutateAsync({ params: { Id: "q1", IfMatch: "3", Allocated: 12 } });
    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(spy).toHaveBeenCalledWith(
      "admin.quotas.update",
      { Id: "q1", IfMatch: "3", Allocated: 12 },
      {},
    );
  });
});
