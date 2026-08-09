import { useSuspenseQuery } from "@tanstack/react-query";
import { Link, createFileRoute } from "@tanstack/react-router";
import { UserPlus } from "lucide-react";

import { UserDataTable } from "../../components/admin/user-data-table";
import { PageHeader } from "../../components/shell/PageHeader";
import { RoutePending, RouteErrorState } from "../../components/shell/RouteFallbacks";
import { userRolesQueryOptions } from "../../lib/lara-user-role";

export const Route = createFileRoute("/_authenticated/admin/users")({
  ssr: false,
  loader: ({ context }) => context.queryClient.ensureQueryData(userRolesQueryOptions),
  head: () => ({
    meta: [{ title: "Users | Licensing Portal" }, { name: "robots", content: "noindex,nofollow" }],
  }),
  pendingComponent: () => (
    <RoutePending title="Users" description="Manage user accounts and role assignments." />
  ),
  errorComponent: ({ error, reset }) => (
    <RouteErrorState title="Users" error={error} reset={reset} />
  ),
  notFoundComponent: () => <PageHeader title="Users not found" />,
  component: UsersPage,
});

function UsersPage() {
  const { data } = useSuspenseQuery(userRolesQueryOptions);

  return (
    <>
      <PageHeader title="Users" description="Manage user accounts and role assignments." />
      <div className="mt-4 flex justify-end">
        <Link
          to="/admin/users/new"
          className="focus-ring inline-flex h-9 items-center gap-2 rounded-md bg-primary px-4 text-sm font-medium text-primary-foreground shadow-sm transition-colors hover:bg-primary/90"
        >
          <UserPlus aria-hidden="true" className="size-4" />
          New user
        </Link>
      </div>
      <UserDataTable users={data} />
    </>
  );
}
