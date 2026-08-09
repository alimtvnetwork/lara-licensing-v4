import { createFileRoute } from "@tanstack/react-router";

import { LicenseCreateWizard } from "../../components/admin/license-create-wizard";
import { PageHeader } from "../../components/shell/PageHeader";

export const Route = createFileRoute("/_authenticated/admin/licenses/new")({
  ssr: false,
  head: () => ({
    meta: [
      { title: "Issue license | Licensing Portal" },
      { name: "robots", content: "noindex,nofollow" },
    ],
  }),
  component: IssueLicensePage,
});

function IssueLicensePage() {
  return (
    <>
      <PageHeader
        title="Issue license"
        breadcrumbs={[{ label: "Admin", to: "/admin" }, { label: "Issue license" }]}
        description="5-step wizard: select reseller, tier, features, environment, then confirm."
      />
      <LicenseCreateWizard />
    </>
  );
}
