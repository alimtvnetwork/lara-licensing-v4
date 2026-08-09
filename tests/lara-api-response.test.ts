import { describe, expect, it, vi } from "vitest";
import { z } from "zod";

import { parseLaraResponse } from "@/lib/lara-api-response";
import { ApiErrorCodeType, LaraApiError } from "@/lib/lara-api-error";

const ItemSchema = z.object({ Id: z.string() });

function buildResponse(body: unknown, init: ResponseInit): Response {
  return new Response(JSON.stringify(body), init);
}

const attrs = { RequestId: "envelope-req", RequestedAt: "2026-01-01T00:00:00Z" };

describe("parseLaraResponse", () => {
  it("returns Results on success", async () => {
    const body = {
      Status: { IsSuccess: true, Code: 200, Message: "ok" },
      Attributes: attrs,
      Results: [{ Id: "a" }, { Id: "b" }],
    };
    const res = buildResponse(body, { status: 200 });
    const out = await parseLaraResponse(res, "/x", ItemSchema);
    expect(out).toEqual([{ Id: "a" }, { Id: "b" }]);
  });

  it("throws LaraApiError with request id from X-Request-Id header (header wins over envelope)", async () => {
    const body = {
      Status: { IsSuccess: false, Code: 400, Message: "bad" },
      Attributes: {
        ...attrs,
        Error: { ErrorCode: ApiErrorCodeType.ValidationFailed, ErrorMessage: "nope" },
      },
      Results: [],
    };
    const res = buildResponse(body, {
      status: 400,
      headers: { "X-Request-Id": "header-req" },
    });
    await expect(parseLaraResponse(res, "/x", ItemSchema)).rejects.toMatchObject({
      errorCode: ApiErrorCodeType.ValidationFailed,
      httpStatus: 400,
      requestId: "header-req",
      message: "nope",
    });
  });

  it("falls back to envelope RequestId when header is absent", async () => {
    const body = {
      Status: { IsSuccess: false, Code: 400, Message: "bad" },
      Attributes: {
        ...attrs,
        Error: { ErrorCode: ApiErrorCodeType.ValidationFailed, ErrorMessage: "nope" },
      },
      Results: [],
    };
    const res = buildResponse(body, { status: 400 });
    try {
      await parseLaraResponse(res, "/x", ItemSchema);
      throw new Error("expected throw");
    } catch (e) {
      expect(e).toBeInstanceOf(LaraApiError);
      expect((e as LaraApiError).requestId).toBe("envelope-req");
    }
  });

  it("attaches rate limit metadata ONLY for RateLimited errors", async () => {
    const body = {
      Status: { IsSuccess: false, Code: 429, Message: "slow" },
      Attributes: {
        ...attrs,
        Error: { ErrorCode: ApiErrorCodeType.RateLimited, ErrorMessage: "throttled" },
      },
      Results: [],
    };
    const res = buildResponse(body, {
      status: 429,
      headers: {
        "X-Request-Id": "r1",
        "Retry-After": "12",
        "X-RateLimit-Bucket": "issue",
        "X-RateLimit-Limit": "60",
        "X-RateLimit-Window": "60",
        "X-RateLimit-Reset": "1735689600",
      },
    });
    try {
      await parseLaraResponse(res, "/x", ItemSchema);
      throw new Error("expected throw");
    } catch (e) {
      const err = e as LaraApiError;
      expect(err.errorCode).toBe(ApiErrorCodeType.RateLimited);
      expect(err.rateLimit).toEqual({
        retryAfterSeconds: 12,
        bucket: "issue",
        limit: 60,
        windowSeconds: 60,
        resetAt: 1735689600,
      });
    }
  });

  it("does NOT populate rate limit metadata for non-RateLimited codes", async () => {
    const body = {
      Status: { IsSuccess: false, Code: 400, Message: "bad" },
      Attributes: {
        ...attrs,
        Error: { ErrorCode: ApiErrorCodeType.ValidationFailed, ErrorMessage: "nope" },
      },
      Results: [],
    };
    const res = buildResponse(body, {
      status: 400,
      headers: { "Retry-After": "12", "X-RateLimit-Bucket": "issue" },
    });
    try {
      await parseLaraResponse(res, "/x", ItemSchema);
      throw new Error("expected throw");
    } catch (e) {
      expect((e as LaraApiError).rateLimit).toBeUndefined();
    }
  });

  it("throws ServerError with response status when JSON is invalid", async () => {
    const res = new Response("not json", { status: 502 });
    const spy = vi.spyOn(console, "error").mockImplementation(() => {});
    try {
      await parseLaraResponse(res, "/x", ItemSchema);
      throw new Error("expected throw");
    } catch (e) {
      const err = e as LaraApiError;
      expect(err.errorCode).toBe(ApiErrorCodeType.ServerError);
      expect(err.httpStatus).toBe(502);
    } finally {
      spy.mockRestore();
    }
  });

  it("throws ServerError on success envelope mismatch (contract violation)", async () => {
    const body = {
      Status: { IsSuccess: true, Code: 200, Message: "ok" },
      Attributes: attrs,
      Results: [{ Nope: 1 }],
    };
    const res = buildResponse(body, { status: 200 });
    const spy = vi.spyOn(console, "error").mockImplementation(() => {});
    try {
      await parseLaraResponse(res, "/x", ItemSchema);
      throw new Error("expected throw");
    } catch (e) {
      expect((e as LaraApiError).errorCode).toBe(ApiErrorCodeType.ServerError);
    } finally {
      spy.mockRestore();
    }
  });

  it("ignores non-numeric Retry-After without fabricating a value", async () => {
    const body = {
      Status: { IsSuccess: false, Code: 429, Message: "slow" },
      Attributes: {
        ...attrs,
        Error: { ErrorCode: ApiErrorCodeType.RateLimited, ErrorMessage: "throttled" },
      },
      Results: [],
    };
    const res = buildResponse(body, {
      status: 429,
      headers: { "Retry-After": "Wed, 21 Oct 2026 07:28:00 GMT" },
    });
    try {
      await parseLaraResponse(res, "/x", ItemSchema);
      throw new Error("expected throw");
    } catch (e) {
      expect((e as LaraApiError).rateLimit?.retryAfterSeconds).toBeUndefined();
    }
  });

  it("returns UnknownServerError and warns with raw code for unrecognised ErrorCode (F4)", async () => {
    const body = {
      Status: { IsSuccess: false, Code: 418, Message: "teapot" },
      Attributes: {
        ...attrs,
        Error: { ErrorCode: "TotallyNewServerCode", ErrorMessage: "brew failed" },
      },
      Results: [],
    };
    const res = buildResponse(body, {
      status: 418,
      headers: { "X-Request-Id": "req-unknown" },
    });
    const warnSpy = vi.spyOn(console, "warn").mockImplementation(() => {});
    const errSpy = vi.spyOn(console, "error").mockImplementation(() => {});
    try {
      await parseLaraResponse(res, "/x", ItemSchema);
      throw new Error("expected throw");
    } catch (e) {
      const err = e as LaraApiError;
      expect(err.errorCode).toBe(ApiErrorCodeType.UnknownServerError);
      expect(err.httpStatus).toBe(418);
      expect(err.requestId).toBe("req-unknown");
      expect(err.message).toContain("TotallyNewServerCode");
      expect(warnSpy).toHaveBeenCalledWith(
        "Lara API unknown error code",
        expect.objectContaining({
          path: "/x",
          status: 418,
          requestId: "req-unknown",
          unknownCode: "TotallyNewServerCode",
        }),
      );
      expect(errSpy).not.toHaveBeenCalled();
    } finally {
      warnSpy.mockRestore();
      errSpy.mockRestore();
    }
  });
});
