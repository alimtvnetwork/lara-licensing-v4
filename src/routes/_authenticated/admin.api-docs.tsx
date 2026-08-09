import { createFileRoute } from "@tanstack/react-router";
import { PageHeader } from "@/components/shell/PageHeader";

export const Route = createFileRoute("/_authenticated/admin/api-docs")({
  ssr: false,
  head: () => ({
    meta: [
      { title: "API Documentation | Licensing Portal" },
      { name: "robots", content: "noindex,nofollow" },
    ],
  }),
  component: ApiDocsPage,
});

function ApiDocsPage() {
  return (
    <>
      <PageHeader
        title="API Documentation"
        breadcrumbs={[{ label: "Admin", to: "/admin" }, { label: "API Docs" }]}
        description="Interactive Swagger UI for the Lara Licensing backend API."
      />
      <div className="mt-6 flex flex-col items-center justify-center overflow-hidden rounded-md border bg-white shadow-sm">
        <iframe
          src="/api/documentation"
          title="Swagger UI"
          className="h-[800px] w-full border-none"
        />
      </div>
    </>
  );
}
