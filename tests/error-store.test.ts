import { beforeEach, describe, expect, it } from "vitest";

import { ApiErrorCodeType, LaraApiError } from "@/lib/lara-api-error";
import {
  __resetErrorStoreForTests,
  clearErrorStore,
  getErrorStoreSnapshot,
  pushLaraApiError,
  subscribeErrorStore,
} from "@/lib/error-store";
import { RetryPolicyType } from "@/lib/lara-retry";

beforeEach(() => {
  __resetErrorStoreForTests();
});

function makeError(
  code: ApiErrorCodeType,
  httpStatus: number,
  extras: { requestId?: string; errorId?: string; details?: ReadonlyArray<unknown> } = {},
): LaraApiError {
  return new LaraApiError(
    `${code} failed`,
    code,
    httpStatus,
    extras.requestId,
    undefined,
    extras.errorId,
    extras.details,
  );
}

describe("error-store", () => {
  it("normalizes a 5xx LaraApiError with errorId and marks it retryable", () => {
    const entry = pushLaraApiError(
      makeError(ApiErrorCodeType.ServerError, 500, {
        requestId: "req-1",
        errorId: "11111111-1111-4111-8111-111111111111",
      }),
    );
    expect(entry.errorCode).toBe(ApiErrorCodeType.ServerError);
    expect(entry.httpStatus).toBe(500);
    expect(entry.errorId).toBe("11111111-1111-4111-8111-111111111111");
    expect(entry.requestId).toBe("req-1");
    expect(entry.retryable).toBe(true);
    expect(entry.retryPolicy).toBe(RetryPolicyType.ExpBackoff);
    expect(getErrorStoreSnapshot()).toHaveLength(1);
  });

  it("preserves 4xx Details verbatim", () => {
    const details = [{ Field: "email", Value: "bad" }];
    const entry = pushLaraApiError(
      makeError(ApiErrorCodeType.ValidationFailed, 422, { requestId: "req-2", details }),
    );
    expect(entry.details).toEqual(details);
    expect(entry.retryable).toBe(false);
    expect(entry.retryPolicy).toBe(RetryPolicyType.NoRetry);
  });

  it("dedupes the same errorId within the 1.5s window", () => {
    const now = 1_000_000;
    const err = makeError(ApiErrorCodeType.ServerError, 500, {
      errorId: "22222222-2222-4222-8222-222222222222",
    });
    pushLaraApiError(err, now);
    pushLaraApiError(err, now + 500);
    expect(getErrorStoreSnapshot()).toHaveLength(1);
  });

  it("keeps entries when errorId differs even if code matches", () => {
    pushLaraApiError(
      makeError(ApiErrorCodeType.ServerError, 500, { errorId: "aaaa" }),
      1_000_000,
    );
    pushLaraApiError(
      makeError(ApiErrorCodeType.ServerError, 500, { errorId: "bbbb" }),
      1_000_100,
    );
    expect(getErrorStoreSnapshot()).toHaveLength(2);
  });

  it("notifies subscribers and stops after unsubscribe", () => {
    const calls: number[] = [];
    const unsubscribe = subscribeErrorStore((entries) => calls.push(entries.length));
    pushLaraApiError(makeError(ApiErrorCodeType.RateLimited, 429));
    unsubscribe();
    pushLaraApiError(makeError(ApiErrorCodeType.ServerError, 500));
    expect(calls).toEqual([1]);
  });

  it("clearErrorStore emits once and empties the snapshot", () => {
    pushLaraApiError(makeError(ApiErrorCodeType.ServerError, 500));
    const seen: number[] = [];
    subscribeErrorStore((entries) => seen.push(entries.length));
    clearErrorStore();
    expect(getErrorStoreSnapshot()).toHaveLength(0);
    expect(seen).toEqual([0]);
  });

  it("caps history at 50 entries (newest first)", () => {
    for (let i = 0; i < 55; i += 1) {
      pushLaraApiError(
        makeError(ApiErrorCodeType.ServerError, 500, { errorId: `id-${i}` }),
        1_000_000 + i * 10,
      );
    }
    const snap = getErrorStoreSnapshot();
    expect(snap).toHaveLength(50);
    expect(snap[0]!.errorId).toBe("id-54");
  });

  it("captures network-wrapped errors (httpStatus 0) as retryable ExpBackoff", () => {
    const entry = pushLaraApiError(
      new LaraApiError("Network request failed: down", ApiErrorCodeType.ServerError, 0),
    );
    expect(entry.httpStatus).toBe(0);
    expect(entry.retryable).toBe(true);
    expect(entry.retryPolicy).toBe(RetryPolicyType.ExpBackoff);
  });
});
