import "fake-indexeddb/auto";
import { describe, it, expect, beforeEach } from "vitest";
import { resetAll } from "@/lib/preview-store";
import { loadDefaultSeed } from "@/lib/preview-seeds/default";
import serialsModule from "@/lib/preview-fixtures/serials";
import {
  clearPreviewHandlersForTest,
  dispatchPreview,
  type PreviewContext,
} from "@/lib/preview-transport";
import type { OperationId } from "@/generated/api/operations";
import { LaraApiError } from "@/lib/lara-api-error";

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
    RequestId: `req-ser-${Math.random().toString(16).slice(2, 8)}`,
  };
}

describe("preview-fixtures serials (Plan 18 Step 68)", () => {
  beforeEach(async () => {
    clearPreviewHandlersForTest();
    await resetAll();
    serialsModule.register();
  });

  it("successfully looks up a seeded serial", async () => {
    await loadDefaultSeed();
    // From default.ts: seedLicenses writes reverse index LARA-AAAA-0001 -> LIC00001
    const res = await dispatchPreview<"portal.serials.lookup">(
      "portal.serials.lookup",
      ctx({ Serial: "LARA-AAAA-0001" })
    );

    expect(res.Serial).toBe("LARA-AAAA-0001");
    expect(res.Status).toBe("active");
  });

  it("normalises serial case and whitespace", async () => {
    await loadDefaultSeed();
    const res = await dispatchPreview<"portal.serials.lookup">(
      "portal.serials.lookup",
      ctx({ Serial: " lara-aaaa-0001 " })
    );
    expect(res.Serial).toBe("LARA-AAAA-0001");
  });

  it("throws SerialNotFound for non-existent serial", async () => {
    await loadDefaultSeed();
    try {
      await dispatchPreview<"portal.serials.lookup">(
        "portal.serials.lookup",
        ctx({ Serial: "LARA-NONE-0000" })
      );
      throw new Error("Should have thrown SerialNotFound");
    } catch (err: any) {
      expect(err).toBeInstanceOf(LaraApiError);
      expect(err.errorCode).toBe("SerialNotFound");
    }
  });

  it("seeds at least four serials (Step 68 contract)", async () => {
    await loadDefaultSeed();
    // We expanded licenses to 6, and each writes a serial.
    // LARA-AAAA-0001 through LARA-FFFF-0006
    const serials = ["LARA-AAAA-0001", "LARA-BBBB-0002", "LARA-CCCC-0003", "LARA-DDDD-0004"];
    for (const s of serials) {
      const res = await dispatchPreview<"portal.serials.lookup">(
        "portal.serials.lookup",
        ctx({ Serial: s })
      );
      expect(res.Serial).toBe(s);
    }
  });
});
