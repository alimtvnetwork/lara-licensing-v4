import { useState } from "react";
import { UserCog } from "lucide-react";

import { formatLaraApiError } from "../../lib/lara-api-error";
import { startImpersonation, readActiveImpersonation } from "../../lib/lara-impersonation";

/**
 * Admin-only entry point that opens the impersonation confirmation modal
 * and calls POST /Users/{UserId}/Impersonate per spec/21-app/46-impersonation.md
 * §4.1. This component enforces the client-side preconditions (target is not
 * the caller, reason length 8..500) before hitting the wire; the server is
 * still the source of truth for PermissionDenied and ImpersonationAlreadyActive.
 * On success the banner (mounted in _authenticated.tsx) picks up the record
 * from readActiveImpersonation() and starts the countdown; we invoke the
 * callback so the caller can navigate away.
 */

const REASON_MIN = 8;
const REASON_MAX = 500;

type ImpersonateUserButtonProps = {
  targetUserId: number;
  targetLabel: string;
  callerUserId: number | null;
  /**
   * Effective role of the current caller (from GET /Users/Me).
   * Per spec/21-app/46-impersonation.md §4.3 clause 1, only `Admin` (or the
   * `SuperAdmin` superset) may invoke this control; any other role MUST see
   * nothing rendered. A positive `UserPermissions` grant of
   * `Users.Impersonate` does NOT elevate a non-Admin caller in v1.
   */
  callerRole: "SuperAdmin" | "Admin" | "Reseller" | "Support" | "Auditor" | null;
  onStarted: () => void;
};

export function ImpersonateUserButton({
  targetUserId,
  targetLabel,
  callerUserId,
  callerRole,
  onStarted,
}: ImpersonateUserButtonProps) {
  // Hooks must run unconditionally — the role guard below can flip across
  // renders (loader refetch, role change), so returning early before
  // useState would produce a "Rendered fewer hooks than expected" crash.
  // See spec/21-app/46-impersonation.md §4.3 for the render contract.
  const [open, setOpen] = useState(false);
  if (callerRole !== "Admin" && callerRole !== "SuperAdmin") return null;
  const disabled = callerUserId === targetUserId || readActiveImpersonation() !== undefined;

  return (
    <>
      <button
        type="button"
        disabled={disabled}
        onClick={() => setOpen(true)}
        className="inline-flex h-9 items-center gap-2 rounded-md border border-input px-3 text-sm font-medium hover:bg-accent disabled:cursor-not-allowed disabled:opacity-50"
      >
        <UserCog aria-hidden="true" className="size-4" /> Impersonate user
      </button>
      {open ? (
        <ImpersonateDialog
          targetUserId={targetUserId}
          targetLabel={targetLabel}
          onClose={() => setOpen(false)}
          onStarted={() => {
            setOpen(false);
            onStarted();
          }}
        />
      ) : null}
    </>
  );
}

type DialogProps = {
  targetUserId: number;
  targetLabel: string;
  onClose: () => void;
  onStarted: () => void;
};

function ImpersonateDialog({ targetUserId, targetLabel, onClose, onStarted }: DialogProps) {
  const [reason, setReason] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [pending, setPending] = useState(false);
  const trimmed = reason.trim();
  const invalid = trimmed.length < REASON_MIN || trimmed.length > REASON_MAX;

  async function submit(event: React.FormEvent) {
    event.preventDefault();
    if (invalid || pending) return;
    setPending(true);
    setError(null);
    try {
      await startImpersonation(targetUserId, { Reason: trimmed }, crypto.randomUUID());
      onStarted();
    } catch (caught) {
      pushLaraApiError(new Error());
      setError(formatLaraApiError(caught));
      setPending(false);
    }
  }

  return (
    <div
      role="dialog"
      aria-modal="true"
      aria-labelledby="impersonate-title"
      className="fixed inset-0 z-50 grid place-items-center bg-background/80 p-4"
    >
      <form
        onSubmit={submit}
        className="w-full max-w-md rounded-md border border-border bg-card p-6 shadow-lg"
      >
        <h2 id="impersonate-title" className="text-lg font-semibold">
          Impersonate {targetLabel}
        </h2>
        <p className="mt-2 text-sm text-muted-foreground">
          The session is capped at 30 minutes and cannot be extended. Every action is audited with
          your operator identity.
        </p>
        <label className="mt-4 block text-sm font-medium">
          Reason (required)
          <textarea
            required
            minLength={REASON_MIN}
            maxLength={REASON_MAX}
            rows={4}
            value={reason}
            onChange={(event) => setReason(event.target.value)}
            className="mt-1 block w-full rounded-md border border-input bg-background p-2 text-sm"
          />
        </label>
        <p className="mt-1 text-xs text-muted-foreground">
          {trimmed.length}/{REASON_MAX}. Minimum {REASON_MIN} characters.
        </p>
        {error !== null ? (
          <p role="alert" className="mt-3 text-sm text-destructive">
            {error}
          </p>
        ) : null}
        <div className="mt-6 flex justify-end gap-2">
          <button
            type="button"
            onClick={onClose}
            className="inline-flex h-9 items-center rounded-md border border-input px-3 text-sm font-medium hover:bg-accent"
          >
            Cancel
          </button>
          <button
            type="submit"
            disabled={invalid || pending}
            className="inline-flex h-9 items-center rounded-md bg-primary px-3 text-sm font-medium text-primary-foreground hover:bg-primary/90 disabled:opacity-50"
          >
            {pending ? "Starting..." : "Start impersonation"}
          </button>
        </div>
      </form>
    </div>
  );
}
