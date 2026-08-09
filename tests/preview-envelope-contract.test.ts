/**
 * Plan 16 Step 78: Preview-handler error-envelope contract tests.
 *
 * Every preview failure MUST surface as a `LaraApiError` carrying:
 *   - a non-empty `message`
 *   - an `errorCode` that is a member of the closed-set `ApiErrorCodeType`
 *   - a positive `httpStatus` (transport preserves it end-to-end)
 *   - the `requestId` from the dispatch context (RequestId propagation,
 *     spec/21-app/20-observability.md; INV-ERR-04)
 *
 * A plain `Error` or a rejected promise with a string here would break
 * `formatLaraApiError` and the Global Error Modal contract from Plan 11.
 * This test locks the envelope surface for high-value error paths.
 */
import "fake-indexeddb/auto";
import { beforeEach, describe, expect, it } from "vitest";
import { resetAll } from "../src/lib/preview-store";
import { loadDefaultSeed } from "../src/lib/preview-seeds/default";
import {
  clearPreviewHandlersForTest,
  dispatchPreview,
  type PreviewContext,
  type PreviewSeed,
} from "../src/lib/preview-transport";
import { registerAllPreviewHandlers } from "../src/lib/preview-fixtures";
import { ApiErrorCodeType, LaraApiError } from "../src/lib/lara-api-error";
import type { OperationId } from "../src/generated/api/operations";

const CLOSED_SET_CODES = new Set<string>(Object.values(ApiErrorCodeType));

function ctx<K extends OperationId>(
  Params: unknown,
  seed: PreviewSeed = "default",
  requestId = `req-envelope-${Math.random().toString(16).slice(2, 8)}`,
): PreviewContext<K> {
  return {
    Params: Params as never,
    Headers: {},
    Signal: new AbortController().signal,
    Seed: seed,
    Scenario: null,
    RequestId: requestId,
  };
}

interface ErrorCase {
  name: string;
  op: OperationId;
  params: unknown;
  seed?: PreviewSeed;
  expectedCode: ApiErrorCodeType;
  expectedStatus: number;
}

const CASES: readonly ErrorCase[] = [
  {
    name: "admin.licenses.show unknown Id -> LicenseNotFound (404)",
    op: "admin.licenses.show",
    params: { Id: "01H00000000000000LIC99999" },
    expectedCode: ApiErrorCodeType.LicenseNotFound,
    expectedStatus: 404,
  },
  {
    name: "admin.licenses.update stale IfMatch -> PreconditionFailed (412)",
    op: "admin.licenses.update",
    params: { Id: "01H00000000000000LIC00001", IfMatch: "0" },
    expectedCode: ApiErrorCodeType.PreconditionFailed,
    expectedStatus: 412,
  },
  {
    name: "admin.quotas.update stale IfMatch -> PreconditionFailed (412)",
    op: "admin.quotas.update",
    params: { Id: "01H0000000000000000QUOTA1", IfMatch: "0", Allocated: 100 },
    expectedCode: ApiErrorCodeType.PreconditionFailed,
    expectedStatus: 412,
  },
  {
    name: "portal.serials.lookup unknown serial -> SerialNotFound (404)",
    op: "portal.serials.lookup",
    params: { Serial: "LARA-ZZZZ-9999" },
    expectedCode: ApiErrorCodeType.SerialNotFound,
    expectedStatus: 404,
  },
  {
    name: "admin.runtime-config.update stale IfMatch -> RuntimeConfigConflict (412)",
    op: "admin.runtime-config.update",
    params: {
      IfMatch: "1900-01-01T00:00:00Z",
      Mode: "preview",
      ApiBaseUrl: null,
      PreviewSeed: "default",
      AllowRuntimeToggle: true,
    },
    expectedCode: ApiErrorCodeType.RuntimeConfigConflict,
    expectedStatus: 412,
  },
];

describe("preview-envelope-contract: LaraApiError surface parity", () => {
  beforeEach(async () => {
    await resetAll();
    clearPreviewHandlersForTest();
    registerAllPreviewHandlers();
    await loadDefaultSeed();
  });

  for (const c of CASES) {
    it(c.name, async () => {
      const requestId = `req-envelope-${c.op}`;
      let caught: unknown;
      try {
        await dispatchPreview(c.op, ctx(c.params, c.seed ?? "default", requestId));
      } catch (e) {
        caught = e;
      }
      if (!(caught instanceof LaraApiError)) {
        console.error("preview-envelope-contract:not-lara-api-error", {
          op: c.op,
          caughtType: caught === undefined ? "undefined" : (caught as object)?.constructor?.name,
          caught,
        });
      }
      expect(caught).toBeInstanceOf(LaraApiError);
      const err = caught as LaraApiError;
      expect(err.message.length).toBeGreaterThan(0);
      expect(CLOSED_SET_CODES.has(err.errorCode)).toBe(true);
      expect(err.errorCode).toBe(c.expectedCode);
      expect(err.httpStatus).toBe(c.expectedStatus);
      expect(err.requestId).toBe(requestId);
    });
  }

  it("dispatchPreview on unregistered op throws LaraApiError with ServerError (INV-RM-04)", async () => {
    clearPreviewHandlersForTest(); // wipe registration done in beforeEach
    let caught: unknown;
    try {
      await dispatchPreview("admin.licenses.list", ctx({}, "default", "req-envelope-missing"));
    } catch (e) {
      caught = e;
    }
    expect(caught).toBeInstanceOf(LaraApiError);
    const err = caught as LaraApiError;
    expect(err.errorCode).toBe(ApiErrorCodeType.ServerError);
    expect(CLOSED_SET_CODES.has(err.errorCode)).toBe(true);
  });
});
