/**
 * Typed React Query hooks over the single `apiClient.call(...)` entrypoint.
 *
 * Plan 16 Step 61 (v0.567.0): every modernized UI surface consumes the
 * runtime-typed API through these hooks so that:
 *   - `LaraApiError` propagates unchanged (no try/catch swallow, no fallback
 *     values). React Query surfaces it via `error`.
 *   - `Retry-After` and `RequestId` headers flow through `apiClient.call`
 *     `onResponseHeaders` / `onAttributes` callbacks and are re-exposed here.
 *   - Preview / dev / production modes are transparent (all go through
 *     `apiClient.call`, which fans out per `getRuntimeMode()`).
 *
 * Keep function bodies <= 15 lines per coding guidelines.
 */
import {
  useMutation,
  useQuery,
  type UseMutationOptions,
  type UseMutationResult,
  type UseQueryOptions,
  type UseQueryResult,
} from "@tanstack/react-query";
import {
  apiClient,
  type ApiCallOptions,
  type OperationId,
  type OperationRequest,
  type OperationResponse,
} from "@/lib/api-client";
import { LaraApiError } from "@/lib/lara-api-error";

export type ApiQueryKey<K extends OperationId> = readonly ["api", K, OperationRequest<K>];

export function apiQueryKey<K extends OperationId>(
  id: K,
  params: OperationRequest<K>,
): ApiQueryKey<K> {
  return ["api", id, params] as const;
}

export interface UseApiOptions<K extends OperationId> extends Omit<
  UseQueryOptions<OperationResponse<K>, LaraApiError, OperationResponse<K>, ApiQueryKey<K>>,
  "queryKey" | "queryFn"
> {
  call?: Omit<ApiCallOptions, "signal">;
}

/**
 * Attaches the failing `operationId` onto a caught `LaraApiError` so
 * every downstream renderer (`RouteErrorState`, `StateError`, support
 * tooling) can show WHICH call broke without threading it through every
 * transport. `requestId` is already carried on `LaraApiError` from
 * `laraFetch` / preview transports; we do NOT overwrite it. Plan 17 Step 40.
 */
function tagOperationId<K extends OperationId>(id: K, error: unknown): never {
  if (error instanceof LaraApiError && error.operationId === undefined) {
    error.operationId = id;
  }
  throw error;
}

/**
 * Typed `useQuery` wrapper. Passes React Query's AbortSignal through so
 * unmounts cancel in-flight fetches; forwards `LaraApiError` unchanged
 * except for a best-effort `operationId` tag (see `tagOperationId`).
 */
export function useApi<K extends OperationId>(
  id: K,
  params: OperationRequest<K>,
  options: UseApiOptions<K> = {},
): UseQueryResult<OperationResponse<K>, LaraApiError> {
  const { call, ...rest } = options;

  return useQuery<OperationResponse<K>, LaraApiError, OperationResponse<K>, ApiQueryKey<K>>({
    queryKey: apiQueryKey(id, params),
    queryFn: ({ signal }) =>
      apiClient.call(id, params, { ...(call ?? {}), signal }).catch((e) => tagOperationId(id, e)),
    ...rest,
  });
}

export interface ApiMutationVariables<K extends OperationId> {
  params: OperationRequest<K>;
  call?: Omit<ApiCallOptions, "signal">;
}

export type UseApiMutationOptions<K extends OperationId> = Omit<
  UseMutationOptions<OperationResponse<K>, LaraApiError, ApiMutationVariables<K>>,
  "mutationFn"
>;

/**
 * Typed `useMutation` wrapper. Callers pass `{ params, call }` to
 * `mutate` / `mutateAsync`; `LaraApiError` (including `retryAfterSeconds`,
 * `requestId`, and the `operationId` tag added here) reaches `onError`
 * unchanged.
 */
export function useApiMutation<K extends OperationId>(
  id: K,
  options: UseApiMutationOptions<K> = {},
): UseMutationResult<OperationResponse<K>, LaraApiError, ApiMutationVariables<K>> {
  return useMutation<OperationResponse<K>, LaraApiError, ApiMutationVariables<K>>({
    mutationFn: ({ params, call }) =>
      apiClient.call(id, params, call ?? {}).catch((e) => tagOperationId(id, e)),
    ...options,
  });
}
