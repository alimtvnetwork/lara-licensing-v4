import { Outlet, createFileRoute, redirect } from "@tanstack/react-router";

import { ImpersonationBanner } from "../components/impersonation-banner";
import { UpdateBanner } from "../components/update-banner";
import { getLaraAccessToken } from "../lib/lara-api-session";
import { PlatformType } from "../lib/lara-self-update";

export const Route = createFileRoute("/_authenticated")({
  beforeLoad: ({ location }) => {
    if (typeof window === "undefined") return;
    if (typeof getLaraAccessToken() === "string") return;
    throw redirect({ to: "/admin/login", search: { redirect: location.href } });
  },
  component: AuthenticatedLayout,
});

/**
 * Renders the cross-shell update banner above the outlet. Per
 * spec/21-app/16-ui-surfaces.md §3a the banner is role-gated to
 * `AppBuilder` / `EndUser`; the `Admin` shell does not set
 * `LaraShellRoleContext`, so `<UpdateBanner />` renders nothing there.
 */
function AuthenticatedLayout() {
  return (
    <>
      <ImpersonationBanner />
      <UpdateBanner
        product="lara-cli"
        currentVersion="0.0.0"
        platform={PlatformType.WindowsAmd64}
        viewUpdateHref="/app/update"
      />
      <Outlet />
    </>
  );
}
