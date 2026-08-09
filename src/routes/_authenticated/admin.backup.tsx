import { createFileRoute, Outlet } from "@tanstack/react-router";
import { StateForbidden } from "@/components/state";
import { useCapability } from "@/lib/capabilities";
import { PageHeader } from "@/components/shell/PageHeader";

export const Route = createFileRoute("/_authenticated/admin/backup")({
  ssr: false,
  head: () => ({
    meta: [{ title: "Backup | Admin" }, { name: "robots", content: "noindex,nofollow" }],
  }),
  component: AdminBackupLayout,
});

function AdminBackupLayout() {
  const isAllowed = useCapability("Backup.View");

  const isFailed = !isAllowed;
  if (isFailed) {
    return <StateForbidden route={Route.fullPath} attemptedPermissionKey="Backup.View" />;
  }

  return (
    <>
      <PageHeader
        title="Backup & Restore"
        breadcrumbs={[{ label: "Admin", to: "/admin" }, { label: "Backup & Restore" }]}
      />
      <div className="mt-4">
        <Outlet />
      </div>
    </>
  );
}
