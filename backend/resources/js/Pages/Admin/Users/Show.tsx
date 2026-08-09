// Plan 06 step 66. Admin user detail Inertia page: identity facts, role
// assignment, and impersonation controls (Admin/SuperAdmin gated).

import { Head, Link } from "@inertiajs/react";

import ConsoleLayout from "@/Layouts/ConsoleLayout";
import { PageHeader } from "@/Components/shell/PageHeader";
import { UserRolePicker } from "@/Components/admin/UserRolePicker";
import { ImpersonateUserButton, ForceEndImpersonationButton } from "@/Components/admin/ImpersonationActions";
import { AuditTable, type AuditRow } from "@/Components/admin/AuditTable";
import type { UserRow } from "@/Components/admin/UserTable";

interface Props {
  user: UserRow | null;
  roles?: string[];
  callerUserId?: number | null;
  callerRole?: string | null;
  activeImpersonationSessionId?: string | null;
  auditRows?: AuditRow[];
}

const dateFormatter = new Intl.DateTimeFormat(undefined, { dateStyle: "medium", timeStyle: "short" });

function formatStamp(value: string | undefined): string {
  if (!value) return "unknown";
  const parsed = Date.parse(value);
  return Number.isNaN(parsed) ? "unknown" : dateFormatter.format(new Date(parsed));
}

export default function UserShow({
  user,
  roles = [],
  callerUserId = null,
  callerRole = null,
  activeImpersonationSessionId = null,
  auditRows = [],
}: Props) {
  if (user === null) {
    return (
      <ConsoleLayout>
        <Head title="User not found | Licensing Portal" />
        <PageHeader title="User not found" breadcrumbs={[{ label: "Admin", to: "/admin" }, { label: "Users", to: "/admin/users" }]} />
        <Link href="/admin/users" className="mt-4 inline-block text-sm underline">
          Back to users
        </Link>
      </ConsoleLayout>
    );
  }

  const callerIsAdmin = callerRole === "Admin" || callerRole === "SuperAdmin";
  const targetIsAdmin = roles.includes("Admin") || roles.includes("SuperAdmin");
  const canImpersonate = callerIsAdmin && !targetIsAdmin && callerUserId !== user.UserId;

  return (
    <ConsoleLayout>
      <Head title={`${user.Email} | Licensing Portal`}>
        <meta name="robots" content="noindex,nofollow" />
      </Head>
      <PageHeader
        title={user.Email}
        breadcrumbs={[{ label: "Admin", to: "/admin" }, { label: "Users", to: "/admin/users" }, { label: user.Email }]}
        description="Manage role assignments. Last-admin protection is enforced by the API."
      />

      <dl className="mt-8 grid gap-4 rounded-lg border border-border bg-card p-4 sm:grid-cols-4">
        <div>
          <dt className="text-xs font-medium uppercase tracking-wide text-muted-foreground">User ID</dt>
          <dd className="mt-1 font-mono text-sm">{user.UserId}</dd>
        </div>
        <div>
          <dt className="text-xs font-medium uppercase tracking-wide text-muted-foreground">Tenant</dt>
          <dd className="mt-1 font-mono text-sm">{user.TenantId ?? "unknown"}</dd>
        </div>
        <div>
          <dt className="text-xs font-medium uppercase tracking-wide text-muted-foreground">Status</dt>
          <dd className="mt-1 text-sm">{user.IsActive ? "Active" : "Inactive"}</dd>
        </div>
        <div>
          <dt className="text-xs font-medium uppercase tracking-wide text-muted-foreground">Created</dt>
          <dd className="mt-1 text-sm">{formatStamp(user.CreatedAt)}</dd>
        </div>
      </dl>

      {callerIsAdmin && (
        <section aria-labelledby="impersonation-heading" className="mt-8">
          <h2 id="impersonation-heading" className="mb-3 text-sm font-medium">
            Impersonation
          </h2>
          <div className="flex flex-wrap items-end gap-3 rounded-lg border border-border bg-card p-4">
            {canImpersonate ? (
              <ImpersonateUserButton targetUserId={user.UserId} targetLabel={user.Email} />
            ) : (
              <p className="text-sm text-muted-foreground">
                {targetIsAdmin ? "Admin targets cannot be impersonated." : "Impersonation is unavailable for this target."}
              </p>
            )}
            <ForceEndImpersonationButton sessionId={activeImpersonationSessionId} />
          </div>
        </section>
      )}

      <section aria-labelledby="roles-heading" className="mt-8">
        <h2 id="roles-heading" className="mb-3 text-sm font-medium">
          Roles
        </h2>
        <UserRolePicker userId={user.UserId} roles={roles} callerUserId={callerUserId} />
      </section>

      {callerIsAdmin && (
        <section aria-labelledby="audit-heading" className="mt-8">
          <h2 id="audit-heading" className="mb-3 text-sm font-medium">
            Audit trail
          </h2>
          <AuditTable
            rows={auditRows}
            emptyHeadline="No recorded activity for this user"
            emptyBody="Role grants, impersonation starts, and force-ends targeting this user appear here."
          />
        </section>
      )}
    </ConsoleLayout>
  );
}
