import { createFileRoute, Outlet } from "@tanstack/react-router";
import { StateForbidden } from "@/components/state";
import { useCapability } from "@/lib/capabilities";
import { PageHeader } from "@/components/shell/PageHeader";

export const Route = createFileRoute("/_authenticated/admin/snapshots")({
  ssr: false,
  head: () => ({
    meta: [{ title: "Snapshots | Admin" }, { name: "robots", content: "noindex,nofollow" }],
  }),
  component: AdminSnapshotsLayout,
});

function AdminSnapshotsLayout() {
  const isAllowed = useCapability("Snapshot.View");

  const isFailed = !isAllowed;
  if (isFailed) {
    return <StateForbidden route={Route.fullPath} attemptedPermissionKey="Snapshot.View" />;
  }

  return (
    <>
      <PageHeader
        title="Snapshots"
        breadcrumbs={[{ label: "Admin", to: "/admin" }, { label: "Snapshots" }]}
      />
      <div className="mt-4">
        <Outlet />
      </div>
    </>
  );
}
