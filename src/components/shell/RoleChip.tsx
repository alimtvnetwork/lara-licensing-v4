/**
 * RoleChip: Compact identity chip for the topbar per
 * spec/24-app-ui-design-system §7.2. Reads `Me.RoleName` and renders it as
 * a semantic pill using design tokens (no hardcoded colors).
 *
 * Root cause this addresses: the topbar had no persistent visual signal of
 * the caller's role, so a Support-role user inside the Admin shell (viable
 * per RBAC) looked identical to a SuperAdmin. The chip closes that gap
 * without shipping a full identity page.
 */
import { useQuery } from "@tanstack/react-query";

import { meQueryOptions, type MeResource } from "@/lib/lara-me";

type RoleTone = "admin" | "reseller" | "support" | "auditor" | "enduser";

const RoleToneByRole: Record<MeResource["RoleName"], RoleTone> = {
  SuperAdmin: "admin",
  Admin: "admin",
  Reseller: "reseller",
  Support: "support",
  Auditor: "auditor",
  EndUser: "enduser",
};

const ToneClass: Record<RoleTone, string> = {
  admin:
    "border-primary/40 bg-[color-mix(in_oklab,var(--color-primary)_12%,transparent)] text-primary",
  reseller:
    "border-accent/50 bg-[color-mix(in_oklab,var(--color-accent)_14%,transparent)] text-accent-foreground",
  support: "border-border bg-muted text-muted-foreground",
  auditor: "border-border bg-muted text-muted-foreground",
  enduser: "border-border bg-muted text-muted-foreground",
};

export function RoleChip() {
  const meQuery = useQuery(meQueryOptions());
  const me = meQuery.data?.[0];
  if (me === undefined) return null;
  const tone = RoleToneByRole[me.RoleName];

  return (
    <span
      className={`inline-flex h-6 items-center rounded-full border px-2 text-[0.6875rem] font-semibold uppercase tracking-[0.06em] ${ToneClass[tone]}`}
      style={{ fontFamily: "var(--font-sans)" }}
      aria-label={`Role: ${me.RoleName}`}
    >
      {me.RoleName}
    </span>
  );
}
