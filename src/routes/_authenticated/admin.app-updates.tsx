import { useState } from "react";
import { useSuspenseQuery, useQueryClient } from "@tanstack/react-query";
import { createFileRoute } from "@tanstack/react-router";
import { Plus } from "lucide-react";

import { PageHeader } from "../../components/shell/PageHeader";
import { PageActions } from "../../components/shell/AppShell";
import { PublishBuildDialog } from "../../components/admin/PublishBuildDialog";
import { AppUpdatesDataTable } from "../../components/admin/app-updates-data-table";
import { RoutePending, RouteErrorState } from "../../components/shell/RouteFallbacks";
import { appUpdatesQueryOptions } from "../../lib/lara-app-updates";

/**
 * Plan 09 step 44. App updates route refit onto shared DataTable +
 * FilterBar primitives.
 *
 * Root cause this closes: v0.294.0 landed the publish history but kept
 * a hand-rolled `<table>` scaffold + `window.confirm()` for yank, which
 * diverged from the resellers/users/audit list convergence work
 * (v0.311.0..v0.313.0) and bypassed the Spec 24 §7.5 lineage signal
 * required before destructive mutations under impersonation.
 */
export const Route = createFileRoute("/_authenticated/admin/app-updates")({
  ssr: false,
  loader: ({ context }) => context.queryClient.ensureQueryData(appUpdatesQueryOptions()),
  head: () => ({
    meta: [
      { title: "App updates | Licensing Portal" },
      { name: "robots", content: "noindex,nofollow" },
    ],
  }),
  pendingComponent: () => (
    <RoutePending
      title="App updates"
      description="Self-update publish history for lara-cli (Stable channel)."
    />
  ),
  errorComponent: ({ error, reset }) => (
    <RouteErrorState title="App updates" error={error} reset={reset} />
  ),
  notFoundComponent: UpdatesNotFound,
  component: UpdatesPage,
});

function UpdatesPage() {
  const query = useSuspenseQuery(appUpdatesQueryOptions());
  const queryClient = useQueryClient();
  const [publishOpen, setPublishOpen] = useState(false);
  const rows = query.data;

  return (
    <>
      <PageHeader
        title="App updates"
        description="Self-update publish history for lara-cli (Stable channel)."
      />
      <PageActions>
        <button
          type="button"
          onClick={() => setPublishOpen(true)}
          className="focus-ring inline-flex h-9 items-center gap-2 rounded-md bg-primary px-3 text-sm font-semibold text-primary-foreground hover:bg-primary/90"
        >
          <Plus aria-hidden="true" className="size-4" /> Publish build
        </button>
      </PageActions>
      <AppUpdatesDataTable rows={rows} />
      {publishOpen ? (
        <PublishBuildDialog
          onClose={() => setPublishOpen(false)}
          onPublished={() =>
            queryClient.invalidateQueries({
              queryKey: ["LaraApi", "Admin", "AppUpdates"],
            })
          }
        />
      ) : null}
    </>
  );
}

function UpdatesNotFound() {
  return (
    <PageHeader
      title="App updates unavailable"
      description="AppUpdates.Manage is required to view this page."
    />
  );
}
