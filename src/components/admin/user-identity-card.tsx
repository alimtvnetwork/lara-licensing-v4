// Plan 09 step 37. Identity card for admin.users.$userId.tsx.
//
// Root cause this addresses: the route rendered breadcrumbs + email in
// the PageHeader but no compact identity summary (UserId, TenantId,
// active status, created date). Operators had to hop to
// admin.users.tsx to cross-reference those facts. This card mirrors
// the fact grid used by admin.licenses.$licenseId.tsx (v0.309.0).

import { CheckCircle2, XCircle } from "lucide-react";

import type { LaraUser } from "../../lib/lara-user-role";

const dateFormatter = new Intl.DateTimeFormat(undefined, {
  dateStyle: "medium",
  timeStyle: "short",
});

export function UserIdentityCard({ user }: { user: LaraUser }) {
  return (
    <section
      aria-labelledby="user-identity-heading"
      className="mt-6 rounded-md border border-border bg-card p-6"
      data-ui="user-identity-card"
    >
      <h2 id="user-identity-heading" className="sr-only">
        User identity
      </h2>
      <dl className="grid grid-cols-1 gap-x-8 gap-y-4 sm:grid-cols-2 lg:grid-cols-4">
        <Fact label="User ID">
          <span className="font-mono text-sm">{user.UserId}</span>
        </Fact>
        <Fact label="Tenant">
          <span className="text-sm">{user.TenantId ?? "-"}</span>
        </Fact>
        <Fact label="Status">
          <StatusChip isActive={user.IsActive} />
        </Fact>
        <Fact label="Created">
          <span className="text-sm" title={user.CreatedAt}>
            {dateFormatter.format(new Date(user.CreatedAt))}
          </span>
        </Fact>
      </dl>
    </section>
  );
}

function Fact({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div className="flex flex-col gap-1">
      <dt className="text-xs uppercase tracking-wide text-muted-foreground">{label}</dt>
      <dd>{children}</dd>
    </div>
  );
}

function StatusChip({ isActive }: { isActive: boolean }) {
  const Icon = isActive ? CheckCircle2 : XCircle;

  return (
    <span className="inline-flex items-center gap-1.5 text-sm font-medium">
      <Icon aria-hidden="true" className="size-4" />
      {isActive ? "Active" : "Inactive"}
    </span>
  );
}
