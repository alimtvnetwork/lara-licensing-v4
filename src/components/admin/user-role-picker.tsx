import { useMutation, useQueryClient } from "@tanstack/react-query";
import { useState } from "react";

import { useLaraErrorToast } from "../../lib/use-lara-error-toast";
import {
  APP_ROLE_VALUES,
  grantUserRole,
  revokeUserRole,
  userRoleAssignmentsQueryOptions,
  type AppRoleType,
  type UserRoleEntry,
} from "../../lib/lara-user-role";
import { LineageBadge } from "./lineage-badge";

export function UserRolePicker({
  userId,
  currentCallerUserId,
  entry,
  adminActiveCount,
}: {
  userId: number;
  currentCallerUserId: number | null;
  entry: UserRoleEntry;
  adminActiveCount: number;
}) {
  const queryClient = useQueryClient();
  const invalidate = () =>
    queryClient.invalidateQueries({ queryKey: userRoleAssignmentsQueryOptions(userId).queryKey });

  const grant = useMutation({
    mutationFn: (role: AppRoleType) => grantUserRole(userId, role),
    onSuccess: invalidate,
  });
  const revoke = useMutation({
    mutationFn: (role: AppRoleType) => revokeUserRole(userId, role),
    onSuccess: invalidate,
  });
  useLaraErrorToast(grant.error);
  useLaraErrorToast(revoke.error);

  return (
    <ul className="divide-y divide-border rounded-md border border-border bg-card">
      {APP_ROLE_VALUES.map((role) => {
        const isAssigned = entry.Roles.includes(role);

        return (
          <RoleRow
            key={role}
            role={role}
            isAssigned={isAssigned}
            disabled={grant.isPending || revoke.isPending}
            lastAdminLock={role === "Admin" && isAssigned && adminActiveCount <= 1}
            selfAdminRevoke={role === "Admin" && isAssigned && currentCallerUserId === userId}
            onGrant={() => grant.mutate(role)}
            onRevoke={() => revoke.mutate(role)}
          />
        );
      })}
    </ul>
  );
}

function RoleRow({
  role,
  isAssigned,
  disabled,
  lastAdminLock,
  selfAdminRevoke,
  onGrant,
  onRevoke,
}: {
  role: AppRoleType;
  isAssigned: boolean;
  disabled: boolean;
  lastAdminLock: boolean;
  selfAdminRevoke: boolean;
  onGrant: () => void;
  onRevoke: () => void;
}) {
  const [confirming, setConfirming] = useState(false);
  const revokeBlockedReason = lastAdminLock
    ? "Cannot revoke the last active Admin."
    : selfAdminRevoke
      ? "You cannot revoke your own Admin role."
      : null;
  const canRevoke = isAssigned && revokeBlockedReason === null;

  return (
    <li className="flex items-center justify-between gap-4 px-4 py-3">
      <div>
        <p className="text-sm font-medium">{role}</p>
        <p className="text-xs text-muted-foreground">{isAssigned ? "Assigned" : "Not assigned"}</p>
      </div>
      {isAssigned ? (
        <RevokeControl
          disabled={disabled || !canRevoke}
          blockedReason={revokeBlockedReason}
          confirming={confirming}
          onArm={() => setConfirming(true)}
          onCancel={() => setConfirming(false)}
          onConfirm={() => {
            setConfirming(false);
            onRevoke();
          }}
        />
      ) : (
        <button
          type="button"
          onClick={onGrant}
          disabled={disabled}
          className="inline-flex h-9 items-center rounded-md bg-primary px-3 text-sm font-medium text-primary-foreground hover:bg-primary/90 disabled:opacity-50"
        >
          Grant
        </button>
      )}
    </li>
  );
}

function RevokeControl({
  disabled,
  blockedReason,
  confirming,
  onArm,
  onCancel,
  onConfirm,
}: {
  disabled: boolean;
  blockedReason: string | null;
  confirming: boolean;
  onArm: () => void;
  onCancel: () => void;
  onConfirm: () => void;
}) {
  if (blockedReason !== null) {
    return (
      <span className="text-xs text-muted-foreground" title={blockedReason}>
        {blockedReason}
      </span>
    );
  }
  const isFailed = !confirming;
  if (isFailed) {
    return (
      <button
        type="button"
        onClick={onArm}
        disabled={disabled}
        className="inline-flex h-9 items-center rounded-md border border-input px-3 text-sm font-medium hover:bg-accent disabled:opacity-50"
      >
        Revoke
      </button>
    );
  }

  return (
    <div
      role="group"
      aria-label="Confirm role revoke"
      data-ui="user-role-revoke-confirm"
      className="flex flex-col items-end gap-2 rounded-md border border-destructive/60 p-3"
    >
      <LineageBadge />
      <div className="flex items-center gap-2">
        <button
          type="button"
          onClick={onCancel}
          className="inline-flex h-9 items-center rounded-md border border-input px-3 text-sm"
        >
          Cancel
        </button>
        <button
          type="button"
          onClick={onConfirm}
          disabled={disabled}
          className="inline-flex h-9 items-center rounded-md bg-destructive px-3 text-sm font-medium text-destructive-foreground hover:bg-destructive/90 disabled:opacity-50"
        >
          Confirm revoke
        </button>
      </div>
    </div>
  );
}
