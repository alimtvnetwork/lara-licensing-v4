import { useEffect, useState } from "react";
import { usePage } from "@inertiajs/react";
import { ArrowUpRight, X } from "lucide-react";

import { shellRoleSeesUpdateBanner, useLaraShellRole } from "@/lib/lara-shell-role";

const DISMISS_KEY_PREFIX = "lara.update-banner.dismissed.";

/**
 * Plan 06 step 71. Cross-shell update banner for the Inertia console.
 *
 * Read-only by construction: the payload is resolved server-side in
 * `HandleInertiaRequests::updateBanner()` (Root `AppUpdates` +
 * `AppUpdateAssets`, Stable channel pinned) and arrives as the `update`
 * shared prop. This component performs no fetch, no version comparison,
 * and no checksum/signature handling; when the installed client is not
 * behind, the server sends `null` and nothing renders.
 *
 * Spec: `spec/21-app/16-ui-surfaces.md` §3a and AC-UI-007 (EndUser shell
 * only, dismissal per session, dismissal keyed on `Version` so a new
 * release re-shows the banner).
 */
export interface UpdateBannerPayload {
  LatestVersion: string;
  CurrentVersion: string;
  ReleaseNotesUrl: string | null;
  ViewUpdateHref: string;
}

function useDismissal(version: string | undefined): [boolean, () => void] {
  const [dismissed, setDismissed] = useState(false);
  useEffect(() => {
    if (typeof window === "undefined" || version === undefined) return;
    setDismissed(window.sessionStorage.getItem(`${DISMISS_KEY_PREFIX}${version}`) === "1");
  }, [version]);
  const dismiss = () => {
    if (typeof window === "undefined" || version === undefined) return;
    window.sessionStorage.setItem(`${DISMISS_KEY_PREFIX}${version}`, "1");
    setDismissed(true);
  };
  return [dismissed, dismiss];
}

export function UpdateBanner() {
  const role = useLaraShellRole();
  const update = (usePage().props as { update?: UpdateBannerPayload | null }).update ?? null;
  const [dismissed, dismiss] = useDismissal(update?.LatestVersion);

  if (!shellRoleSeesUpdateBanner(role)) return null;
  if (update === null || dismissed) return null;

  return (
    <div
      role="status"
      aria-live="polite"
      data-testid="update-banner"
      className="mb-6 flex flex-wrap items-center gap-3 rounded-md border border-input bg-muted/40 px-4 py-3 text-sm"
    >
      <span className="min-w-0">
        Update available: <span className="font-semibold">{update.LatestVersion}</span>
        <span className="text-muted-foreground"> (installed {update.CurrentVersion})</span>
        {update.ReleaseNotesUrl !== null ? (
          <>
            {" "}
            <a
              href={update.ReleaseNotesUrl}
              target="_blank"
              rel="noreferrer"
              className="underline underline-offset-2"
            >
              Release notes
            </a>
          </>
        ) : null}
      </span>
      <a
        href={update.ViewUpdateHref}
        className="inline-flex h-8 items-center gap-1 rounded-md border border-input px-3 text-xs font-medium surface-hover"
      >
        View update <ArrowUpRight aria-hidden="true" className="size-3.5" />
      </a>
      <button
        type="button"
        onClick={dismiss}
        aria-label="Dismiss update banner"
        className="focus-ring ml-auto inline-flex size-7 items-center justify-center rounded-md surface-hover"
      >
        <X aria-hidden="true" className="size-4" />
      </button>
    </div>
  );
}
