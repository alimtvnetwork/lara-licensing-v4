// Plan 06 step 66. Impersonation controls ported from
// src/components/admin/impersonate-user-button.tsx and
// force-end-impersonation-button.tsx onto Inertia.
//
// Begin: POST /Api/Admin/Users/{UserId}/Impersonate  (spec 46)
// Force end: POST /Api/Admin/Impersonation/{SessionId}/ForceEnd (spec 47)
// Admin/SuperAdmin gating is enforced server-side; the buttons hide for
// other callers and for admin targets, matching the SPA behaviour.

import * as React from "react";
import { router } from "@inertiajs/react";
import { toast } from "sonner";
import { UserCheck, ShieldOff } from "lucide-react";

import { Button } from "@/Components/ui/Button";
import { LaraApiError, laraRequest } from "@/lib/lara-api";

interface BeginProps {
  targetUserId: number;
  targetLabel: string;
  disabled?: boolean;
}

function reportError(error: unknown, fallback: string): void {
  const code = error instanceof LaraApiError ? error.code : "Unknown";
  const message = error instanceof Error ? error.message : fallback;
  toast.error(message, { description: `Code: ${code}` });
}

export function ImpersonateUserButton({ targetUserId, targetLabel, disabled }: BeginProps) {
  const [busy, setBusy] = React.useState(false);
  const [reason, setReason] = React.useState("");

  const begin = async () => {
    const trimmed = reason.trim();
    if (trimmed.length < 8) {
      toast.error("Reason must be at least 8 characters.");
      return;
    }
    setBusy(true);
    try {
      await laraRequest(`/Api/Admin/Users/${targetUserId}/Impersonate`, {
        method: "POST",
        body: { Reason: trimmed },
      });
      toast.success(`Impersonating ${targetLabel}.`);
      setReason("");
      router.reload();
    } catch (error) {
      reportError(error, "Impersonation could not be started.");
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="flex flex-wrap items-end gap-2">
      <label className="flex flex-col gap-1.5 text-xs font-medium text-muted-foreground" htmlFor="impersonate-reason">
        Reason (audited)
        <input
          id="impersonate-reason"
          value={reason}
          onChange={(e) => setReason(e.target.value)}
          placeholder="Support ticket reference"
          className="h-9 w-72 rounded-md border border-input bg-background px-3 text-sm text-foreground"
        />
      </label>
      <Button type="button" disabled={busy || disabled} onClick={() => void begin()}>
        <UserCheck aria-hidden="true" />
        {busy ? "Starting..." : "Impersonate"}
      </Button>
    </div>
  );
}

interface ForceEndProps {
  sessionId: string | null;
}

export function ForceEndImpersonationButton({ sessionId }: ForceEndProps) {
  const [busy, setBusy] = React.useState(false);
  if (sessionId === null || sessionId === "") return null;

  const forceEnd = async () => {
    setBusy(true);
    try {
      await laraRequest(`/Api/Admin/Impersonation/${sessionId}/ForceEnd`, {
        method: "POST",
        body: { SessionId: sessionId },
      });
      toast.success("Impersonation session terminated.");
      router.reload();
    } catch (error) {
      reportError(error, "Session could not be terminated.");
    } finally {
      setBusy(false);
    }
  };

  return (
    <Button type="button" variant="destructive" disabled={busy} onClick={() => void forceEnd()}>
      <ShieldOff aria-hidden="true" />
      {busy ? "Ending..." : "Force end impersonation"}
    </Button>
  );
}
