/**
 * Production tree-shake guard for `PreviewDebugDrawer` (Plan 16 Step 84).
 *
 * The drawer itself is gated by `isPreview() || isDev()` at render-time,
 * but a static import keeps its full module (and the preview-scenario /
 * version-json-loader chain it pulls in) inside the production bundle.
 * This wrapper flips the import to a dynamic `import()` behind a runtime
 * predicate, so Vite/Rollup emits the drawer as its own chunk that is
 * NEVER fetched in production. INV-RM-04 (no preview code paths reachable
 * in production) is enforced structurally, not by convention.
 *
 * The paired linter `linter-scripts/check-preview-in-prod-bundle.py`
 * bans static `from "...PreviewDebugDrawer"` imports outside this file
 * and the tests directory so the guard cannot silently regress.
 */

import { Suspense, lazy } from "react";

import { isDev, isPreview } from "../../lib/runtime-mode";

const LazyPreviewDebugDrawer = lazy(async () => {
  const mod = await import("./PreviewDebugDrawer");

  return { default: mod.PreviewDebugDrawer };
});

export function PreviewDebugDrawerLazy() {
  if (!(isPreview() || isDev())) return null;

  return (
    <Suspense fallback={null}>
      <LazyPreviewDebugDrawer />
    </Suspense>
  );
}
