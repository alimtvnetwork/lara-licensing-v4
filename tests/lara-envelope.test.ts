import { describe, expect, it } from "vitest";
import { z } from "zod";

import { ApiErrorCodeType } from "@/lib/lara-api-error";
import {
  apiFailureSchema,
  decodeLaraFailure,
  decodeLaraSuccess,
} from "@/lib/lara-envelope";

const baseAttrs = { RequestId: "req_1", RequestedAt: "2026-07-19T00:00:00Z" };

describe("lara-envelope", () => {
  it("decodes a strict failure envelope with Details and ErrorId", () => {
    const body = {
      Status: { IsSuccess: false, Code: 422, Message: "Invalid" },
      Attributes: {
        ...baseAttrs,
        ErrorId: "err_abc",
        Error: {
          ErrorCode: ApiErrorCodeType.ValidationFailed,
          ErrorMessage: "bad input",
          Details: [{ field: "Email" }],
        },
      },
      Results: [],
    };
    const decoded = decodeLaraFailure(body);
    expect(decoded.kind).toBe("strict");
    if (decoded.kind !== "strict") return;
    expect(decoded.failure.Attributes.ErrorId).toBe("err_abc");
    expect(decoded.failure.Attributes.Error.Details).toEqual([{ field: "Email" }]);
  });

  it("falls back to lenient decode when ErrorCode is unknown", () => {
    const body = {
      Status: { IsSuccess: false, Code: 500, Message: "Boom" },
      Attributes: {
        ...baseAttrs,
        Error: { ErrorCode: "FreshlyMintedServerCode", ErrorMessage: "new" },
      },
      Results: [],
    };
    const decoded = decodeLaraFailure(body);
    expect(decoded.kind).toBe("lenient");
  });

  it("reports mismatch with Zod issues when envelope is malformed", () => {
    const decoded = decodeLaraFailure({ nope: true });
    expect(decoded.kind).toBe("mismatch");
    if (decoded.kind !== "mismatch") return;
    expect(decoded.issues.length).toBeGreaterThan(0);
  });

  it("decodes a success envelope against a caller schema", () => {
    const body = {
      Status: { IsSuccess: true, Code: 200, Message: "OK" },
      Attributes: baseAttrs,
      Results: [{ Id: 1 }, { Id: 2 }],
    };
    const decoded = decodeLaraSuccess(body, z.object({ Id: z.number() }));
    expect(decoded.kind).toBe("success");
    if (decoded.kind !== "success") return;
    expect(decoded.results).toHaveLength(2);
  });

  it("re-exports the strict failure schema for contract snapshotting", () => {
    // Sanity check that step 42 (snapshot) can import the schema directly.
    expect(apiFailureSchema).toBeDefined();
  });
});
