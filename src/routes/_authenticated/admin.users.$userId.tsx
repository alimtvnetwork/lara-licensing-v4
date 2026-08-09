import { useSuspenseQuery } from "@tanstack/react-query";
import { createFileRoute, Link, useRouter } from "@tanstack/react-router";
import { RefreshCw } from "lucide-react";
import { z } from "zod";

import { ForceEndImpersonationButton } from "../../components/admin/force-end-impersonation-button";
import { ImpersonateUserButton } from "../../components/admin/impersonate-user-button";
import { UserActivity } from "../../components/admin/user-activity";
import { UserIdentityCard } from "../../components/admin/user-identity-card";
import { UserRolePicker } from "../../components/admin/user-role-picker";
import { UserSessionsPanel } from "../../components/admin/UserSessionsPanel";
import { PageHeader } from "../../components/shell/PageHeader";
import { RouteErrorState } from "../../components/shell/RouteFallbacks";
import { meQueryOptions } from "../../lib/lara-me";
import type { MeResource } from "../../lib/lara-me";
import { userRoleAssignmentsQueryOptions, userRolesQueryOptions } from "../../lib/lara-user-role";

const paramsSchema = z.object({ userId: z.coerce.number().int().positive() });

export const Route = createFileRoute("/_authenticated/admin/users/$userId")({
  ssr: false,
  params: {
    parse: (raw) => paramsSchema.parse(raw),
    stringify: (p) => ({ userId: String(p.userId) }),
  },
  loader: async ({ context, params }) => {
    await Promise.all([
      context.queryClient.ensureQueryData(userRolesQueryOptions),
      context.queryClient.ensureQueryData(userRoleAssignmentsQueryOptions(params.userId)),
      context.queryClient.ensureQueryData(meQueryOptions()),
    ]);
  },
  head: () => ({
    meta: [
      { title: "User roles | Licensing Portal" },
      { name: "robots", content: "noindex,nofollow" },
    ],
  }),
  pendingComponent: UserDetailPending,
  errorComponent: UserDetailError,
  notFoundComponent: UserDetailNotFound,
  component: UserDetailPage,
});

interface MeSummary {
  UserId: MeResource["UserId"];
  RoleName: MeResource["RoleName"];
}

function crumbsFor(email: string) {
  return [
    { label: "Admin", to: "/admin" },
    { label: "Users", to: "/admin/users" },
    { label: email },
  ];
}

function UserDetailPage() {
  const { userId } = Route.useParams();
  const router = useRouter();
  const users = useSuspenseQuery(userRolesQueryOptions);
  const assignments = useSuspenseQuery(userRoleAssignmentsQueryOptions(userId));
  const meQuery = useSuspenseQuery(meQueryOptions());
  const [me] = meQuery.data;
  const user = users.data.find((candidate) => candidate.UserId === userId);
  const entry = assignments.data[0] ?? { UserId: userId, Roles: [] };
  if (user === undefined) return <UserDetailNotFound />;

  return (
    <>
      <PageHeader
        title={user.Email}
        breadcrumbs={crumbsFor(user.Email)}
        description="Manage role assignments. Last-admin protection is enforced client-side and by the API."
      />
      <ImpersonationActions
        userId={userId}
        email={user.Email}
        me={me}
        roles={entry.Roles}
        onChange={() => {
          void router.invalidate();
        }}
      />
      <UserIdentityCard user={user} />
      <section aria-labelledby="roles-heading" className="mt-6">
        <h2 id="roles-heading" className="mb-3 text-sm font-medium">
          Roles
        </h2>
        <UserRolePicker
          userId={userId}
          currentCallerUserId={me?.UserId ?? null}
          entry={entry}
          adminActiveCount={entry.Roles.includes("Admin") ? 1 : 0}
        />
      </section>
      <UserSessionsPanel userId={userId} callerUserId={me?.UserId ?? null} />
      <UserActivity userId={userId} />
    </>
  );
}

interface ImpersonationActionsProps {
  userId: number;
  email: string;
  me: MeSummary | undefined;
  roles: readonly string[];
  onChange: () => void;
}

function ImpersonationActions({ userId, email, me, roles, onChange }: ImpersonationActionsProps) {
  const canImpersonate =
    me !== undefined && (me.RoleName === "Admin" || me.RoleName === "SuperAdmin");
  const targetIsAdmin = roles.includes("Admin");
  if (!canImpersonate || targetIsAdmin || me === undefined) return null;
  // Narrowed by canImpersonate guard above.
  const callerRole = me.RoleName as "Admin" | "SuperAdmin";

  return (
    <div className="flex flex-wrap justify-end gap-2">
      <ImpersonateUserButton
        targetUserId={userId}
        targetLabel={email}
        callerUserId={me.UserId}
        callerRole={callerRole}
        onStarted={onChange}
      />
      <ForceEndImpersonationButton
        targetUserId={userId}
        callerRole={callerRole}
        onEnded={onChange}
      />
    </div>
  );
}

function UserDetailPending() {
  return (
    <>
      <PageHeader title="User" />
      <div
        className="h-64 animate-pulse rounded-md border border-border bg-muted"
        aria-label="Loading user"
      />
    </>
  );
}

function UserDetailError({ error, reset }: { error: Error; reset: () => void }) {
  return (
    <RouteErrorState title="User" headline="User could not be loaded" error={error} reset={reset} />
  );
}

function UserDetailNotFound() {
  return (
    <>
      <PageHeader title="User not found" />
      <Link to="/admin/users" className="inline-block text-sm underline">
        Back to users
      </Link>
    </>
  );
}
