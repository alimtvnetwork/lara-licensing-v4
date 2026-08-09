// Plan 06 step 66. Admin users list Inertia page.

import { Head, Link } from "@inertiajs/react";
import { UserPlus } from "lucide-react";

import ConsoleLayout from "@/Layouts/ConsoleLayout";
import { PageHeader } from "@/Components/shell/PageHeader";
import { UserTable, type UserRow } from "@/Components/admin/UserTable";
import { Button } from "@/Components/ui/Button";

interface Props {
  users?: UserRow[];
}

export default function UserIndex({ users = [] }: Props) {
  return (
    <ConsoleLayout>
      <Head title="Users | Licensing Portal">
        <meta name="robots" content="noindex,nofollow" />
      </Head>
      <PageHeader
        title="Users"
        breadcrumbs={[{ label: "Admin", to: "/admin" }, { label: "Users" }]}
        description={`Manage user accounts and role assignments. ${users.length} loaded.`}
      />
      <div className="mt-6 flex justify-end">
        <Button asChild>
          <Link href="/admin/users">
            <UserPlus aria-hidden="true" />
            New user
          </Link>
        </Button>
      </div>
      <div className="mt-4">
        <UserTable users={users} />
      </div>
    </ConsoleLayout>
  );
}
