import { useQuery } from "@tanstack/react-query";
import { UserCog } from "lucide-react";

import { meQueryOptions } from "../../lib/lara-me";
import { readActiveImpersonation } from "../../lib/lara-impersonation";

/**
 * Plan 09 Step 22. Audit-lineage chip rendered inside destructive
 * confirmation dialogs (revoke, force-end-impersonation, admin
 * force-close-session) so the operator sees "Actor -> Subject" at the
 * exact moment they authorize the mutation. Spec 24 §7.4 requires the
 * impersonation banner to be visible; §7.5 additionally requires
 * mutation dialogs to name both principals because the top-of-page
 * banner is out of view when a modal / footer confirm is focused.
 *
 * Sources of truth:
 * - Actor identity: GET /Users/Me (RoleName in the closed set from
 *   spec/21-app/04-roles.md).
 * - Impersonated subject: LicensingPortal.ActiveImpersonation localStorage
 *   record maintained by lara-impersonation.ts. When no impersonation
 *   session is active the chip degrades to "Acting as <actor>".
 *
 * The badge never fabricates a subject: if Me has not loaded yet it
 * renders a neutral loading pill instead of guessing an id.
 */
import type { MeResource } from "../../lib/lara-me";

export function LineageBadge() {
  const meQuery = useQuery(meQueryOptions());
  const me: MeResource | undefined = meQuery.data?.[0];
  const impersonation = readActiveImpersonation();
  const actorLabel = describeActor(me);
  const subjectLabel =
    impersonation !== undefined ? `User #${impersonation.TargetUserId}` : undefined;

  return (
    <span
      role="note"
      data-ui="lineage-badge"
      data-impersonating={impersonation !== undefined ? "true" : "false"}
      className="inline-flex flex-wrap items-center gap-1.5 rounded-full border px-2.5 py-1 text-xs"
      style={{
        borderColor: "color-mix(in oklab, var(--color-accent) 55%, transparent)",
        backgroundColor: "color-mix(in oklab, var(--color-accent) 12%, var(--color-background))",
        color: "var(--color-foreground)",
        fontFamily: "var(--font-sans)",
      }}
    >
      <UserCog aria-hidden="true" className="size-3.5" />
      <span className="font-semibold">Acting as</span>
      <span>{actorLabel}</span>
      {subjectLabel !== undefined ? (
        <>
          <span aria-hidden="true" className="text-muted-foreground">
            -&gt;
          </span>
          <span className="font-semibold">{subjectLabel}</span>
        </>
      ) : null}
    </span>
  );
}

function describeActor(me: MeResource | undefined): string {
  if (me === undefined) return "loading identity...";
  const name =
    typeof me.DisplayName === "string" && me.DisplayName.length > 0 ? me.DisplayName : me.Email;

  return `${name} (${me.RoleName})`;
}
