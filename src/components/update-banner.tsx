import { useQuery } from "@tanstack/react-query";
import { ArrowUpRight, X } from "lucide-react";
import { useEffect, useState } from "react";

import { Banner, BannerTitle } from "@/components/ui/banner";

import { formatLaraApiError, LaraApiError } from "../lib/lara-api-error";
import {
  ChannelType,
  type PlatformType,
  updateManifestQueryOptions,
  type UpdateManifest,
} from "../lib/lara-self-update";
import { shellRoleSeesUpdateBanner, useLaraShellRole } from "../lib/lara-shell-role";

const DISMISS_KEY_PREFIX = "lara.update-banner.dismissed.";

/**
 * Cross-shell update banner per spec/21-app/16-ui-surfaces.md §3a.
 * Renders only for `AppBuilder` / `EndUser` shells. Dismissal is per-session
 * and keyed on `Version` so a new release re-shows the banner (AC-UI-007).
 *
 * v1.0 rollout policy (spec/21-app/17-self-update-endpoint.md §"v1.0 rollout
 * policy"): the update channel is hard-pinned to `Stable`. No `channel` prop is
 * accepted; reintroducing one requires the settings-screen work called out in
 * that section.
 */
export interface UpdateBannerProps {
  product: string;
  currentVersion: string;
  platform: PlatformType;
  viewUpdateHref: string;
}

function readInjectedAppVersion(): string | null {
  if (typeof window === "undefined") return null;
  const raw = (window as unknown as { __LARA_APP_VERSION__?: unknown }).__LARA_APP_VERSION__;

  return typeof raw === "string" && raw.length > 0 ? raw : null;
}

function useDismissal(version: string | undefined): [boolean, () => void] {
  const [dismissed, setDismissed] = useState(false);
  useEffect(() => {
    if (typeof window === "undefined" || version === undefined) return;
    const stored = window.sessionStorage.getItem(`${DISMISS_KEY_PREFIX}${version}`);
    setDismissed(stored === "1");
  }, [version]);
  const dismiss = () => {
    if (typeof window === "undefined" || version === undefined) return;
    window.sessionStorage.setItem(`${DISMISS_KEY_PREFIX}${version}`, "1");
    setDismissed(true);
  };

  return [dismissed, dismiss];
}

function isBehind(manifest: UpdateManifest, currentVersion: string): boolean {
  return manifest.LatestVersion !== currentVersion;
}

export function UpdateBanner(props: UpdateBannerProps) {
  const role = useLaraShellRole();
  const injected = readInjectedAppVersion();
  const currentVersion = injected ?? props.currentVersion;
  const enabled = shellRoleSeesUpdateBanner(role);
  const query = useQuery({
    ...updateManifestQueryOptions({
      product: props.product,
      channel: ChannelType.Stable,
      currentVersion,
      platform: props.platform,
    }),
    enabled,
  });
  useEffect(() => {
    if (query.error instanceof LaraApiError) {
      console.error("[update-banner] manifest fetch failed", {
        requestId: query.error.requestId,
        errorCode: query.error.errorCode,
        message: formatLaraApiError(query.error),
      });
    }
  }, [query.error]);
  const manifest = query.data;
  const [dismissed, dismiss] = useDismissal(manifest?.LatestVersion);
  if (!enabled || manifest === undefined || dismissed) return null;
  if (isBehind(manifest, currentVersion) === false) return null;

  return (
    <Banner intent="info" data-testid="update-banner" className="flex-wrap items-center gap-3">
      <div className="flex flex-1 flex-wrap items-center gap-3">
        <BannerTitle className="min-w-0 font-normal">
          Update available: <span className="font-semibold">{manifest.LatestVersion}</span>
          {typeof manifest.ReleaseNotesUrl === "string" ? (
            <>
              {" "}
              <a
                href={manifest.ReleaseNotesUrl}
                target="_blank"
                rel="noreferrer"
                className="underline underline-offset-2"
              >
                Release notes
              </a>
            </>
          ) : null}
        </BannerTitle>
        <a
          href={props.viewUpdateHref}
          className="inline-flex h-8 items-center gap-1 rounded-[var(--radius-md)] border border-[var(--border)] px-3 text-xs font-medium surface-hover"
        >
          View update <ArrowUpRight aria-hidden="true" className="size-3.5" />
        </a>
        <button
          type="button"
          onClick={dismiss}
          aria-label="Dismiss update banner"
          className="focus-ring ml-auto inline-flex h-7 w-7 items-center justify-center rounded-[var(--radius-md)] surface-hover"
        >
          <X aria-hidden="true" className="size-4" />
        </button>
      </div>
    </Banner>
  );
}
