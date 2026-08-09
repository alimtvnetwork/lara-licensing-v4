import "fake-indexeddb/auto";
import { describe, it, expect, beforeEach } from "vitest";
import { resetAll } from "@/lib/preview-store";
import { loadDefaultSeed } from "@/lib/preview-seeds/default";
import featuresModule from "@/lib/preview-fixtures/features";
import {
  clearPreviewHandlersForTest,
  dispatchPreview,
  type PreviewContext,
} from "@/lib/preview-transport";
import type { OperationId } from "@/generated/api/operations";

function ctx<K extends OperationId>(
  Params: unknown,
  seed: "default" | "empty" | "error" = "default",
): PreviewContext<K> {
  return {
    Params: Params as never,
    Headers: {},
    Signal: new AbortController().signal,
    Seed: seed,
    Scenario: null,
    RequestId: `req-ft-${Math.random().toString(16).slice(2, 8)}`,
  };
}

describe("preview-fixtures features (Plan 18 Step 69)", () => {
  beforeEach(async () => {
    clearPreviewHandlersForTest();
    await resetAll();
    featuresModule.register();
  });

  it("returns the full seeded feature catalog", async () => {
    await loadDefaultSeed();
    const res = await dispatchPreview<"admin.features.list">(
      "admin.features.list",
      ctx({})
    );

    // We seeded 6 features in default.ts
    expect(res.Items.length).toBeGreaterThanOrEqual(6);
    
    const codes = res.Items.map(f => f.Code);
    expect(codes).toContain("core.reports");
    expect(codes).toContain("addon.api");
  });

  it("sorts by Category then Code", async () => {
    await loadDefaultSeed();
    const res = await dispatchPreview<"admin.features.list">(
      "admin.features.list",
      ctx({})
    );

    const items = res.Items;
    for (let i = 0; i < items.length - 1; i++) {
      const a = items[i];
      const b = items[i+1];
      if (a.Category === b.Category) {
        expect(a.Code.localeCompare(b.Code)).toBeLessThanOrEqual(0);
      } else {
        expect(a.Category.localeCompare(b.Category)).toBeLessThanOrEqual(0);
      }
    }
  });
});
