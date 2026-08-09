import { createFileRoute } from "@tanstack/react-router";

import { ResellerCreateWizard } from "../../components/admin/reseller-create-wizard";
import { PageHeader } from "../../components/shell/PageHeader";

export const Route = createFileRoute("/_authenticated/admin/resellers/new")({
  ssr: false,
  head: () => ({
    meta: [
      { title: "New reseller | Licensing Portal" },
      { name: "robots", content: "noindex,nofollow" },
    ],
  }),
  component: NewResellerPage,
});

function NewResellerPage() {
  return (
    <>
      <PageHeader
        title="Create reseller"
        breadcrumbs={[
          { label: "Admin", to: "/admin" },
          { label: "Resellers", to: "/admin/resellers" },
          { label: "New" },
        ]}
        description="Register a new reseller organization in three steps: identity, prefixes, confirm."
      />
      <ResellerCreateWizard />
    </>
  );
}
