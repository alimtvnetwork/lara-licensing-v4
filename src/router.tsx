import { QueryClient } from "@tanstack/react-query";
import { createRouter } from "@tanstack/react-router";
import { routeTree } from "./routeTree.gen";
import { StateError, StateNotFound } from "./components/state";
import { bootRuntimeConfig } from "./lib/version-json-loader";
import { dispatchPreviewSeed } from "./lib/preview-seed-dispatcher";
import { PREVIEW_FIXTURE_MODULES, registerAllPreviewHandlers } from "./lib/preview-fixtures";
import { assertPreviewBootReady } from "./lib/preview-transport";
import {
  getPreviewScenario,
  setPreviewScenario,
  parseScenarioFromSearch,
} from "./lib/preview-scenario";
import { getRuntimeMode } from "./lib/runtime-mode";

// Kick off the runtime-mode resolve exactly once per client boot (INV-RM-08).
// Guarded so SSR/prerender never fetches /version.json server-side (F-04).
// After the store freezes we (a) register preview handlers when Mode is
// "preview" so `apiClient.call()` can serve typed responses from IndexedDB
// (Step 40), then (b) dispatch the preview seed (Step 39). Both are no-ops
// outside preview mode and idempotent across reloads. Failures surface
// through `logRuntimeError` (INV-RM-11); we never swallow them.
if (typeof window !== "undefined") {
  // Plan 17 Step 2: register preview handlers eagerly at module load
  // (idempotent; Map.set) so route loaders that fire before
  // `bootRuntimeConfig()` resolves still find a handler under
  // `apiClient.call()`. Previous fire-and-forget ordering left the
  // registry empty when `/admin/audit` mounted, surfacing as
  // `Preview handler not registered for operation "admin.audit.list"
  // (INV-RM-04)` in the console. Registration is safe outside preview
  // too: handlers only run when `apiClient.call()` sees Mode=preview.
  try {
    registerAllPreviewHandlers();
  } catch (err) {
    console.error("[preview-fixtures] eager register failed", err);
  }
  void bootRuntimeConfig()
    .then(() => {
      if (getRuntimeMode().Mode === "preview") {
        // Plan 17 Step 17: fail loud if the fixture barrel or a domain
        // module regressed to a no-op register(). Never silent (INV-RM-11).
        assertPreviewBootReady(PREVIEW_FIXTURE_MODULES.length);
        const urlScenario = parseScenarioFromSearch(window.location.search);
        if (urlScenario !== undefined) setPreviewScenario(urlScenario);
        (window as unknown as { __LARA_PREVIEW__?: unknown }).__LARA_PREVIEW__ = {
          getScenario: getPreviewScenario,
          setScenario: setPreviewScenario,
        };
      }

      return dispatchPreviewSeed();
    })
    .catch((err) => {
      console.error("[runtime-mode] boot failed", err);
    });
}

function RouterDefaultError({ error, reset }: { error: Error; reset: () => void }) {
  return <StateError route="__router__" error={error} reset={reset} />;
}

function RouterDefaultNotFound() {
  const path = typeof window === "undefined" ? "" : window.location.pathname;

  return <StateNotFound route="__router__" attemptedPath={path} />;
}

export const getRouter = () => {
  const queryClient = new QueryClient();
  if (typeof window !== "undefined") {
    // Step 34: expose the shared client so `applyRuntimeConfigChange`
    // can invalidate all queries during a seed-only fast-path swap
    // without triggering a full page reload.
    (window as unknown as { __LARA_QUERY_CLIENT__?: QueryClient }).__LARA_QUERY_CLIENT__ =
      queryClient;
  }

  const router = createRouter({
    routeTree,
    context: { queryClient },
    scrollRestoration: true,
    defaultPreloadStaleTime: 0,
    defaultPendingMs: 150,
    defaultErrorComponent: RouterDefaultError,
    defaultNotFoundComponent: RouterDefaultNotFound,
  });

  return router;
};
