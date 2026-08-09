/**
 * Preview seed contract (Plan 16 Step 36 seed; used by Steps 37, 38).
 *
 * A `PreviewSeedFn` is an async function that writes typed records into
 * `preview-store` via the domain-scoped `write` API. Seeds run through
 * `runSeed()`, which wraps `hydrateOnce()` so a reload does not clobber
 * user mutations. Seeds MUST be pure w.r.t. inputs (deterministic IDs,
 * fixed timestamps) so screenshots stay stable across runs.
 */

import { hydrateOnce } from "../preview-store";

export type PreviewSeedId = "default" | "empty" | "error";

export type PreviewSeedFn = () => Promise<void>;

export async function runSeed(
  id: PreviewSeedId,
  fn: PreviewSeedFn,
): Promise<{ Hydrated: boolean }> {
  return hydrateOnce(`seed:${id}`, fn);
}
