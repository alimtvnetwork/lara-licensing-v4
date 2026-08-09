import { useSuspenseQuery } from "@tanstack/react-query";
import { Navigate, createFileRoute } from "@tanstack/react-router";

import { meQueryOptions } from "../../lib/lara-me";

/**
 * Authenticated landing router. Reads `GET /Users/Me` per
 * spec/21-app/11-api-contracts/06-user-contracts.md and dispatches the
 * caller to the correct portal home without requiring them to type
 * their own ResellerId into the URL, which they generally do not know.
 *
 * SuperAdmin / Admin  -> /admin
 * Reseller            -> /reseller/{ResellerId}/quota-requests
 * Support / Auditor   -> /admin (read-only surfaces; server enforces scope)
 * Reseller without a bound ResellerId is a server invariant break and is
 * surfaced as a hard error rather than a silent 404. This is intentional
 * per the "no silent failures" project rule.
 */
export const Route = createFileRoute("/_authenticated/portal")({
  ssr: false,
  head: () => ({
    meta: [{ title: "Portal | Licensing Portal" }, { name: "robots", content: "noindex,nofollow" }],
  }),
  component: PortalRedirect,
});

function PortalRedirect() {
  const { data } = useSuspenseQuery(meQueryOptions());
  const [me] = data;
  const isFailed = !me;
  if (isFailed) {
    throw new Error(
      "Users.Me returned an empty envelope; server invariant break per AC-API-USR-001",
    );
  }
  if (me.RoleName === "Reseller") {
    if (typeof me.ResellerId !== "number") {
      throw new Error(
        `Reseller user ${me.UserId} has no ResellerId binding; refusing to redirect (spec/21-app/40-permissions.md Row-scope)`,
      );
    }

    return (
      <Navigate
        to="/reseller/$resellerId/quota-requests"
        params={{ resellerId: me.ResellerId }}
        replace
      />
    );
  }
  if (me.RoleName === "EndUser") {
    return <Navigate to="/portal/home" replace />;
  }

  return <Navigate to="/admin" replace />;
}
