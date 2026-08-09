/**
 * Plan 17 Step 42: verify `dispatchPreview` runs every fixture response
 * through `assertPreviewShape`. A handler that returns a malformed
 * payload MUST throw `PreviewFixtureShapeError` (a `LaraApiError` with
 * `ApiErrorCodeType.ServerError`) instead of leaking `undefined` into
 * the UI. Also verifies the registry has an entry for every operationId
 * so drift in `PREVIEW_RESPONSE_SHAPES` fails CI first.
 */

import { describe, it, expect, afterEach } from "vitest";
import { Operations, type OperationId } from "@/generated/api/operations";
import {
  registerPreviewHandler,
  dispatchPreview,
  unregisterPreviewHandlerForTest,
  type PreviewContext,
} from "@/lib/preview-transport";
import {
  PREVIEW_RESPONSE_SHAPES,
  PreviewFixtureShapeError,
} from "@/lib/preview-fixtures/_shapes";
import { ApiErrorCodeType } from "@/lib/lara-api-error";

function ctx<K extends OperationId>(op: K): PreviewContext<K> {
  return {
    Params: {} as never,
    Headers: {},
    Signal: new AbortController().signal,
    Seed: "default",
    Scenario: null,
    RequestId: "req_test_shape",
  };
}

describe("preview-fixtures/_shapes", () => {
  afterEach(() => {
    unregisterPreviewHandlerForTest("auth.me");
  });

  it("registers a Zod schema for every OperationId", () => {
    for (const op of Object.keys(Operations) as OperationId[]) {
      expect(PREVIEW_RESPONSE_SHAPES[op]).toBeDefined();
    }
  });

  it("throws PreviewFixtureShapeError when a handler returns a malformed payload", async () => {
    registerPreviewHandler("auth.me", async () => {
      // Missing every required field on MeUser.
      return { Id: 42 } as never;
    });
    await expect(dispatchPreview("auth.me", ctx("auth.me"))).rejects.toBeInstanceOf(
      PreviewFixtureShapeError,
    );
  });

  it("PreviewFixtureShapeError carries operationId, requestId, ServerError code", async () => {
    registerPreviewHandler("auth.me", async () => ({}) as never);
    try {
      await dispatchPreview("auth.me", ctx("auth.me"));
      throw new Error("expected shape error");
    } catch (err) {
      expect(err).toBeInstanceOf(PreviewFixtureShapeError);
      const e = err as PreviewFixtureShapeError;
      expect(e.operationId).toBe("auth.me");
      expect(e.requestId).toBe("req_test_shape");
      expect(e.errorCode).toBe(ApiErrorCodeType.ServerError);
      expect(e.issues.length).toBeGreaterThan(0);
    }
  });

  it("passes valid payloads through unchanged", async () => {
    const now = "2026-07-21T00:00:00Z";
    const user = {
      Id: "01JABCDEF00000000000000000",
      Email: "a@b.co",
      DisplayName: "A",
      Roles: ["admin"],
      ResellerId: null,
      CreatedAt: now,
      UpdatedAt: now,
    };
    registerPreviewHandler("auth.me", async () => user);
    const out = await dispatchPreview("auth.me", ctx("auth.me"));
    expect(out).toEqual(user);
  });
});
