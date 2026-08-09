/**
 * v0.676.0 (Plan 17 Step 31). Preview-store metrics.
 *
 * Instruments IndexedDB access in `preview-store.ts` so the debug
 * drawer can show per-domain rows loaded, op counts, and cumulative ms
 * without pulling a profiler. Zero cost in `production` mode because
 * `preview-store` is only imported behind `isPreview()`.
 *
 * Root invariant: metrics are best-effort observability, never a
 * correctness signal. Errors in the metric recorder MUST NOT bubble
 * into store callers (INV-RM-11: never swallow real errors, but a
 * telemetry counter overflow is not a real error).
 */
import type { PreviewDomain } from "./preview-store";

export type PreviewStoreOp = "read" | "write" | "list" | "listKeys" | "remove";

export interface PreviewStoreDomainMetric {
  Domain: PreviewDomain;
  Reads: number;
  Writes: number;
  Lists: number;
  Removes: number;
  RowsLoaded: number;
  TotalMs: number;
  LastMs: number;
  LastAt: string | null;
}

type Bucket = PreviewStoreDomainMetric;
const buckets = new Map<PreviewDomain, Bucket>();
const listeners = new Set<() => void>();
const EMPTY_SNAPSHOT: readonly PreviewStoreDomainMetric[] = Object.freeze([]);
let snapshot: readonly PreviewStoreDomainMetric[] = EMPTY_SNAPSHOT;

function bucketFor(domain: PreviewDomain): Bucket {
  let b = buckets.get(domain);
  if (!b) {
    b = {
      Domain: domain,
      Reads: 0,
      Writes: 0,
      Lists: 0,
      Removes: 0,
      RowsLoaded: 0,
      TotalMs: 0,
      LastMs: 0,
      LastAt: null,
    };
    buckets.set(domain, b);
  }

  return b;
}

function rebuildSnapshot(): void {
  if (buckets.size === 0) {
    snapshot = EMPTY_SNAPSHOT;

    return;
  }
  snapshot = Object.freeze(Array.from(buckets.values(), (b) => ({ ...b })));
}

function notify(): void {
  rebuildSnapshot();
  for (const fn of listeners) {
    try {
      fn();
    } catch {
      /* observer isolation */
    }
  }
}

export function recordPreviewStoreOp(
  domain: PreviewDomain,
  op: PreviewStoreOp,
  ms: number,
  rows: number = 0,
): void {
  const b = bucketFor(domain);
  if (op === "read") b.Reads += 1;
  else if (op === "write") b.Writes += 1;
  else if (op === "list") b.Lists += 1;
  else if (op === "listKeys") b.Lists += 1;
  else if (op === "remove") b.Removes += 1;
  b.RowsLoaded += rows;
  b.TotalMs += ms;
  b.LastMs = ms;
  b.LastAt = new Date().toISOString();
  notify();
}

export function getPreviewStoreMetrics(): readonly PreviewStoreDomainMetric[] {
  return snapshot;
}

export function resetPreviewStoreMetrics(): void {
  buckets.clear();
  notify();
}

export function subscribePreviewStoreMetrics(fn: () => void): () => void {
  listeners.add(fn);

  return () => {
    listeners.delete(fn);
  };
}
