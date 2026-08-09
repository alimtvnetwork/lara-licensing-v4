/**
 * Plan 17 Step 27: registration coverage.
 *
 * Root invariant (INV-RM-04): every operationId declared in
 * `src/generated/api/operations.ts` MUST have a registered preview
 * handler after `registerAllPreviewHandlers()` runs. Any drift means a
 * preview route will fall through to `PreviewHandlerMissingError` at
 * runtime instead of being caught at CI.
 */
import { describe, it, expect, beforeAll } from "vitest";

import { Operations } from "@/generated/api/operations";
import {
  findMissingPreviewHandlers,
  hasPreviewHandler,
  type OperationId,
} from "@/lib/preview-transport";
import { registerAllPreviewHandlers } from "@/lib/preview-fixtures";

beforeAll(() => {
  registerAllPreviewHandlers();
});

describe("preview-transport registration coverage", () => {
  it("registers a handler for every operationId in Operations", () => {
    const missing = findMissingPreviewHandlers();
    expect(missing, `Missing preview handlers: ${missing.join(", ")}`).toEqual([]);
  });

  it("every Operations key resolves via hasPreviewHandler()", () => {
    const keys = Object.keys(Operations) as OperationId[];
    expect(keys.length).toBeGreaterThan(0);
    for (const key of keys) {
      expect(hasPreviewHandler(key), `no handler for ${key}`).toBe(true);
    }
  });

  it("registerAllPreviewHandlers() is idempotent", () => {
    registerAllPreviewHandlers();
    registerAllPreviewHandlers();
    expect(findMissingPreviewHandlers()).toEqual([]);
  });
});
