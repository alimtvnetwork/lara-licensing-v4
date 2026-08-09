import { useEffect, useState } from "react";
import { ShieldOff } from "lucide-react";

import { formatLaraApiError } from "../../lib/lara-api-error";
import {
  type ImpersonationSessionEnvelope,
  endImpersonation,
  readActiveImpersonation,
} from "../../lib/lara-impersonation";
import { LineageBadge } from "./lineage-badge";

/**
 * Companion to <ImpersonateUserButton />. Renders only when the caller
 * currently holds an active impersonation session targeting the same user,
 * and calls POST /Impersonation/End with EndReason = "AdminForced" per
 * spec/21-app/46-impersonation.md §4.2. This is the operator's recovery
 * path from the parent Normal session when the banner is unreachable
 * (different tab, cleared storage, banner regression).
 *
 * Plan 09 Step 22 fanout: the confirm block mounts <LineageBadge /> so
 * the operator sees "Acting as <actor> -> User #<subject>" at the
 * exact moment they authorize the AdminForced mutation, matching the
 * Spec 24 §7.5 requirement that the sticky banner is not the only
 * lineage signal on destructive dialogs.
 */

type ForceEndImpersonationButtonProps = {
  targetUserId: number;
  /**
   * Effective role of the current caller. Per spec/21-app/46-impersonation.md
   * §4.3 clause 1 the recovery control is Admin-only, matching the start
   * control; any other role renders nothing.
   */
  callerRole: "SuperAdmin" | "Admin" | "Reseller" | "Support" | "Auditor" | null;
  onEnded: () => void;
};

export function ForceEndImpersonationButton({
  targetUserId,
  callerRole,
  onEnded,
}: ForceEndImpersonationButtonProps) {
  const active = useActiveImpersonationFor(targetUserId);
  const [confirming, setConfirming] = useState(false);
  const [pending, setPending] = useState(false);
  const [error, setError] = useState<string | null>(null);
  if (callerRole !== "Admin" && callerRole !== "SuperAdmin") return null;
  if (active === undefined) return null;
  const isFailed = !confirming;
  if (isFailed) {
    return (
      <div className="flex flex-col items-end gap-1">
        <button
          type="button"
          onClick={() => setConfirming(true)}
          className="inline-flex h-9 items-center gap-2 rounded-md border border-destructive/60 px-3 text-sm font-medium text-destructive hover:bg-destructive/10"
        >
          <ShieldOff aria-hidden="true" className="size-4" />
          Force-end impersonation
        </button>
      </div>
    );
  }

  return (
    <div
      role="group"
      aria-label="Confirm force-end impersonation"
      data-ui="force-end-impersonation-confirm"
      className="flex flex-col items-end gap-2 rounded-md border border-destructive/60 p-3"
    >
      <LineageBadge />
      <p className="text-xs text-muted-foreground">
        Ends the active impersonation session with reason AdminForced.
      </p>
      <div className="flex gap-2">
        <button
          type="button"
          disabled={pending}
          onClick={() => setConfirming(false)}
          className="inline-flex h-9 items-center rounded-md border px-3 text-sm font-medium disabled:opacity-60"
        >
          Cancel
        </button>
        <button
          type="button"
          disabled={pending}
          onClick={() => {
            void handleForceEnd(setPending, setError, () => {
              setConfirming(false);
              onEnded();
            });
          }}
          className="inline-flex h-9 items-center gap-2 rounded-md border border-destructive/60 bg-destructive/10 px-3 text-sm font-medium text-destructive hover:bg-destructive/20 disabled:opacity-60"
        >
          <ShieldOff aria-hidden="true" className="size-4" />
          {pending ? "Ending..." : "Confirm force-end"}
        </button>
      </div>
      {error !== null ? (
        <p role="alert" className="text-xs text-destructive">
          {error}
        </p>
      ) : null}
    </div>
  );
}

function useActiveImpersonationFor(targetUserId: number): ImpersonationSessionEnvelope | undefined {
  const [active, setActive] = useState<ImpersonationSessionEnvelope | undefined>(undefined);
  useEffect(() => {
    const read = () => {
      const record = readActiveImpersonation();
      setActive(record !== undefined && record.TargetUserId === targetUserId ? record : undefined);
    };
    read();
    window.addEventListener("storage", read);

    return () => window.removeEventListener("storage", read);
  }, [targetUserId]);

  return active;
}

async function handleForceEnd(
  setPending: (v: boolean) => void,
  setError: (v: string | null) => void,
  onEnded: () => void,
): Promise<void> {
  setPending(true);
  setError(null);
  try {
    await endImpersonation("AdminForced", crypto.randomUUID());
    onEnded();
  } catch (caught) {
    pushLaraApiError(new Error());
    setError(formatLaraApiError(caught));
  } finally {
    setPending(false);
  }
}
