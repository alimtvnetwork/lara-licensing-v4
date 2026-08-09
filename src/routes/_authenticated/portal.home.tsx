import { useQuery } from "@tanstack/react-query";
import { createFileRoute } from "@tanstack/react-router";

import { PageHeader } from "@/components/shell/PageHeader";
import { SerialLookupPanel } from "@/components/portal/serial-lookup-panel";
import { meQueryOptions } from "@/lib/lara-me";

/**
 * End-user portal home per Plan 09 step 49 and step 50.
 *
 * Renders the reusable `<SerialLookupPanel>` (step 50) so the same widget can
 * be embedded in future reseller/admin surfaces without copy-paste. The panel
 * owns the three-state result rendering and the recent-lookups persistence;
 * this route only supplies the greeting + description.
 */
export const Route = createFileRoute("/_authenticated/portal/home")({
  ssr: false,
  head: () => ({
    meta: [
      { title: "Portal | Licensing Portal" },
      {
        name: "description",
        content: "Verify a license serial and view its authorization status.",
      },
      { name: "robots", content: "noindex,nofollow" },
    ],
  }),
  component: PortalHome,
});

function PortalHome() {
  const meQuery = useQuery(meQueryOptions());
  const me = meQuery.data?.[0];
  const displayName = me?.DisplayName ?? me?.Email ?? "there";

  return (
    <main
      className="mx-auto flex w-full max-w-3xl flex-col gap-6 px-[clamp(1rem,0.75rem+1vw,1.5rem)] py-[clamp(1.25rem,1rem+1vw,2rem)]"
      data-page-region="portal-home"
    >
      <PageHeader
        title={`Hello, ${displayName}`}
        description="Enter a serial to verify its authorization status. This does not consume a runtime handshake key."
      />
      <SerialLookupPanel testIdPrefix="portal-home-serial" />
    </main>
  );
}
