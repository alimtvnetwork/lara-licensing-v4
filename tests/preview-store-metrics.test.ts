import { describe, expect, it, beforeEach } from "vitest";
import {
  getPreviewStoreMetrics,
  recordPreviewStoreOp,
  resetPreviewStoreMetrics,
  subscribePreviewStoreMetrics,
} from "@/lib/preview-store-metrics";

describe("preview-store-metrics (Plan 17 Step 31)", () => {
  beforeEach(() => resetPreviewStoreMetrics());

  it("accumulates reads/writes/list rows and cumulative ms per domain", () => {
    recordPreviewStoreOp("audit", "list", 12.5, 25);
    recordPreviewStoreOp("audit", "read", 1.5, 1);
    recordPreviewStoreOp("audit", "write", 3.0, 1);
    const [m] = getPreviewStoreMetrics().filter((x) => x.Domain === "audit");
    expect(m.Lists).toBe(1);
    expect(m.Reads).toBe(1);
    expect(m.Writes).toBe(1);
    expect(m.RowsLoaded).toBe(27);
    expect(m.TotalMs).toBeCloseTo(17, 3);
    expect(m.LastMs).toBeCloseTo(3, 3);
    expect(m.LastAt).not.toBeNull();
  });

  it("reset clears all buckets and notifies subscribers", () => {
    recordPreviewStoreOp("licenses", "list", 5, 3);
    let notified = 0;
    const off = subscribePreviewStoreMetrics(() => { notified += 1; });
    resetPreviewStoreMetrics();
    expect(getPreviewStoreMetrics()).toEqual([]);
    expect(notified).toBeGreaterThanOrEqual(1);
    off();
  });

  it("snapshot reference is stable across reads until a mutation occurs", () => {
    recordPreviewStoreOp("me", "read", 1, 1);
    const a = getPreviewStoreMetrics();
    const b = getPreviewStoreMetrics();
    expect(a).toBe(b);
    recordPreviewStoreOp("me", "read", 1, 1);
    const c = getPreviewStoreMetrics();
    expect(c).not.toBe(a);
  });

});
