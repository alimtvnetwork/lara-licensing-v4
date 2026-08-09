import { createFileRoute } from "@tanstack/react-router";

import { StateNotFound } from "../components/state";

/**
 * Plan 09 Step 65: global splat catchall route. TanStack Router already
 * routes unmatched URLs to `__root.tsx` `notFoundComponent`, but that path
 * inherits the root `<head>` (title "Licensing Portal", indexable). This
 * splat file owns a dedicated head for unknown URLs: `noindex,nofollow`
 * and a "Page not found" title, and emits `RouteNotFound` telemetry with
 * the actual attempted path (via `useStateTelemetry` inside
 * `<StateNotFound />`) so we can measure broken-link surfaces instead of
 * silently 200-ing them.
 *
 * Named `$.tsx` (not `$catchall.tsx`) because we need splat semantics that
 * match multi-segment unknown paths; a single param would only capture one
 * segment. Reachable via `_splat` if the boundary ever needs the raw tail.
 */
export const Route = createFileRoute("/$")({
  head: () => ({
    meta: [
      { title: "Page not found | Licensing Portal" },
      { name: "robots", content: "noindex,nofollow" },
      {
        name: "description",
        content: "The page you requested does not exist on Licensing Portal.",
      },
    ],
  }),
  component: NotFoundCatchall,
});

function NotFoundCatchall() {
  const path = typeof window === "undefined" ? "" : window.location.pathname;

  return (
    <main className="mx-auto flex min-h-[70vh] max-w-5xl items-center justify-center px-4 py-16">
      <StateNotFound route="__catchall__" attemptedPath={path} />
    </main>
  );
}
