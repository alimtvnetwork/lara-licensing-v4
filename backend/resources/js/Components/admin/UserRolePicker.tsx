// Plan 06 step 66. Role assignment ported from
// src/components/admin/user-role-picker.tsx onto Inertia.
//
// Grants call POST /Api/Admin/Users/{UserId}/Roles and revocations call
// DELETE /Api/Admin/Users/{UserId}/Roles/{RoleName}. Last-admin
// protection is advisory here; UserController::assertNotLastAdmin() is
// the authority and returns the taxonomy error we surface.

import * as React from "react";
import { router } from "@inertiajs/react";
import { toast } from "sonner";

import { Button } from "@/Components/ui/Button";
import { LaraApiError, laraRequest } from "@/lib/lara-api";

const ROLE_CATALOG = ["SuperAdmin", "Admin", "Reseller", "User"] as const;
export type RoleName = (typeof ROLE_CATALOG)[number];

interface Props {
  userId: number;
  roles: readonly string[];
  callerUserId: number | null;
}

export function UserRolePicker({ userId, roles, callerUserId }: Props) {
  const [busy, setBusy] = React.useState<string | null>(null);
  const assigned = new Set(roles);
  const selfEdit = callerUserId !== null && callerUserId === userId;

  const run = async (role: RoleName, grant: boolean) => {
    setBusy(role);
    try {
      if (grant) {
        await laraRequest(`/Api/Admin/Users/${userId}/Roles`, {
          method: "POST",
          body: { RoleName: role },
        });
        toast.success(`Granted ${role}.`);
      } else {
        await laraRequest(`/Api/Admin/Users/${userId}/Roles/${role}`, { method: "DELETE" });
        toast.success(`Revoked ${role}.`);
      }
      router.reload({ only: ["roles"] });
    } catch (error) {
      const code = error instanceof LaraApiError ? error.code : "Unknown";
      const message = error instanceof Error ? error.message : "Role change failed.";
      toast.error(message, { description: `Code: ${code}` });
    } finally {
      setBusy(null);
    }
  };

  return (
    <ul className="divide-y divide-border rounded-lg border border-border">
      {ROLE_CATALOG.map((role) => {
        const has = assigned.has(role);
        const selfRevokeAdmin = selfEdit && has && (role === "Admin" || role === "SuperAdmin");
        return (
          <li key={role} className="flex items-center justify-between gap-4 px-4 py-3">
            <span className="flex flex-col">
              <span className="text-sm font-medium">{role}</span>
              <span className="text-xs text-muted-foreground">
                {has ? "Assigned" : "Not assigned"}
                {selfRevokeAdmin ? " - cannot revoke your own admin role" : ""}
              </span>
            </span>
            <Button
              type="button"
              size="sm"
              variant={has ? "outline" : "default"}
              disabled={busy !== null || selfRevokeAdmin}
              onClick={() => void run(role, !has)}
            >
              {busy === role ? "Working..." : has ? "Revoke" : "Grant"}
            </Button>
          </li>
        );
      })}
    </ul>
  );
}
