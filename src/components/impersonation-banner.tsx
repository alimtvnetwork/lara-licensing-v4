import { useEffect, useState } from "react";
import { LogOut } from "lucide-react";

import {
  type ImpersonationSessionEnvelope,
  clearActiveImpersonation,
  endImpersonation,
  readActiveImpersonation,
} from "../lib/lara-impersonation";
import { Banner, BannerTitle } from "@/components/ui/banner";

/**
 * Persistent, non-dismissible impersonation banner required by
 * spec/21-app/46-impersonation.md §6, AC-IMP-008, and Spec 24 §7.4.
 *
 * v0.509.0 (Plan 15 step 22): refit onto the shared <Banner> primitive
 * (info intent, role="status", aria-live="polite") and adopt the chip
 * utility for the TTL affordance. The sticky top wrapper stays so the
 * banner remains pinned across every _authenticated route.
 */
export function ImpersonationBanner() {
  const active = useActiveImpersonation();
  const remainingSeconds = useTtlCountdown(active?.ExpiresAt);
  const [ending, setEnding] = useState(false);
  const [error, setError] = useState<string | undefined>(undefined);
  if (active === undefined) return null;
  const expired = remainingSeconds <= 0;

  return (
    <div
      className="sticky top-0 px-4 py-2"
      style={{ zIndex: "var(--z-topbar)" }}
      data-shell-region="impersonation-banner"
    >
      <Banner intent="info" className="flex-wrap items-center">
        <div className="flex flex-1 flex-wrap items-center gap-3">
          <BannerTitle className="font-semibold">
            Impersonating user #{active.TargetUserId}
          </BannerTitle>
          <span className="chip" data-tone={expired ? "destructive" : "accent"}>
            {expired ? "Session expired" : `Ends in ${formatDuration(remainingSeconds)}`}
          </span>
          <button
            type="button"
            onClick={() => handleEnd(setEnding, setError)}
            disabled={ending}
            className="focus-ring ml-auto inline-flex h-8 items-center gap-1.5 rounded-[var(--radius-md)] border border-[var(--border)] bg-[var(--background)] px-2.5 text-xs font-semibold surface-hover disabled:opacity-60"
          >
            <LogOut aria-hidden="true" className="size-3.5" />
            {ending ? "Ending..." : "Return to Admin"}
          </button>
        </div>
        {typeof error === "string" ? (
          <p className="mt-1 w-full text-xs text-[var(--destructive)]">{error}</p>
        ) : null}
      </Banner>
    </div>
  );
}

function useActiveImpersonation(): ImpersonationSessionEnvelope | undefined {
  const [active, setActive] = useState<ImpersonationSessionEnvelope | undefined>(undefined);
  useEffect(() => {
    setActive(readActiveImpersonation());
    const onStorage = () => setActive(readActiveImpersonation());
    window.addEventListener("storage", onStorage);

    return () => window.removeEventListener("storage", onStorage);
  }, []);

  return active;
}

function useTtlCountdown(expiresAt: string | undefined): number {
  const [now, setNow] = useState(() => Date.now());
  useEffect(() => {
    const id = window.setInterval(() => setNow(Date.now()), 1000);

    return () => window.clearInterval(id);
  }, []);
  if (typeof expiresAt !== "string") return 0;

  return Math.max(0, Math.floor((Date.parse(expiresAt) - now) / 1000));
}

function formatDuration(totalSeconds: number): string {
  const minutes = Math.floor(totalSeconds / 60);
  const seconds = totalSeconds % 60;

  return `${minutes}m ${seconds.toString().padStart(2, "0")}s`;
}

async function handleEnd(
  setEnding: (v: boolean) => void,
  setError: (v: string | undefined) => void,
): Promise<void> {
  setEnding(true);
  setError(undefined);
  try {
    await endImpersonation("OperatorEnded", crypto.randomUUID());
  } catch (error) {
    pushLaraApiError(new Error());
    clearActiveImpersonation();
    setError(error instanceof Error ? error.message : "End impersonation failed.");
  } finally {
    setEnding(false);
  }
}
