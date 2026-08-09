import { useQuery, useMutation } from "@tanstack/react-query";
import { createFileRoute } from "@tanstack/react-router";
import { useMemo, useState } from "react";

import { PageHeader } from "@/components/shell/PageHeader";
import { Button } from "@/components/ui/button";
import { EmptyState } from "@/components/ui/empty-state";
import { SkeletonList } from "@/components/ui/skeleton";
import { copyForErrorCode } from "@/lib/error-copy";
import { LaraApiError } from "@/lib/lara-api-error";
import {
  ChannelType,
  PlatformType,
  downloadUpdateAsset,
  selectAssetForPlatform,
  updateManifestQueryOptions,
  type DownloadedAsset,
  type UpdateManifest,
} from "@/lib/lara-self-update";

/**
 * End-user self-update download route per Plan 09 step 51.
 *
 * Fetches the update manifest for the caller's current version + platform,
 * shows a Download button that streams the asset via `downloadUpdateAsset`
 * (which enforces the four spec/21-app/17 MUST-abort rows: TLS-only URL,
 * HTTP 200, present X-Sha256, sha256+size match) and then hands the caller a
 * blob URL so they can save the binary. The bytes never touch the DOM before
 * verification: any integrity failure throws before we call URL.createObjectURL.
 *
 * Root cause this addresses: EndUser role had `AppUpdates.ReadOwn` in the nav
 * tree but no route to consume the manifest and asset endpoints, so the sole
 * privileged capability of the End-user portal (fetch a new build) was dead.
 */
export const Route = createFileRoute("/_authenticated/portal/updates")({
  ssr: false,
  head: () => ({
    meta: [
      { title: "App update | Licensing Portal" },
      { name: "description", content: "Download the latest verified build for your platform." },
      { name: "robots", content: "noindex,nofollow" },
    ],
  }),
  component: PortalUpdates,
});

function detectPlatform(): PlatformType {
  if (typeof navigator === "undefined") return PlatformType.WindowsAmd64;
  const ua = navigator.userAgent.toLowerCase();
  if (ua.includes("mac")) return PlatformType.DarwinArm64;
  if (ua.includes("linux")) return PlatformType.LinuxAmd64;

  return PlatformType.WindowsAmd64;
}

function currentAppVersion(): string {
  const raw = import.meta.env.VITE_LARA_APP_VERSION;

  return typeof raw === "string" && raw.length > 0 ? raw : "0.0.0";
}

function PortalUpdates() {
  const [platform, setPlatform] = useState<PlatformType>(() => detectPlatform());
  const currentVersion = currentAppVersion();
  const manifestQuery = useQuery(
    updateManifestQueryOptions({
      product: "LicensingPortalClient",
      channel: ChannelType.Stable,
      currentVersion,
      platform,
    }),
  );

  return (
    <main
      className="mx-auto flex w-full max-w-3xl flex-col gap-6 px-[clamp(1rem,0.75rem+1vw,1.5rem)] py-[clamp(1.25rem,1rem+1vw,2rem)]"
      data-page-region="portal-updates"
    >
      <PageHeader
        title="App update"
        description="Download the latest verified build for your platform. The file is checked against the manifest signature before it becomes available."
      />
      <PlatformSelector platform={platform} onChange={setPlatform} />
      <ManifestPanel
        state={manifestQuery.status}
        manifest={manifestQuery.data}
        error={manifestQuery.error}
        onRetry={() => manifestQuery.refetch()}
        platform={platform}
        currentVersion={currentVersion}
      />
    </main>
  );
}

function PlatformSelector({
  platform,
  onChange,
}: {
  readonly platform: PlatformType;
  readonly onChange: (next: PlatformType) => void;
}) {
  return (
    <fieldset
      className="flex flex-wrap items-center gap-2 rounded-lg border border-border bg-surface p-4"
      data-testid="portal-updates-platform"
    >
      <legend
        className="px-1 text-xs font-semibold text-muted-foreground"
        style={{ fontFamily: "var(--font-sans)" }}
      >
        Platform
      </legend>
      {[PlatformType.WindowsAmd64, PlatformType.LinuxAmd64, PlatformType.DarwinArm64].map(
        (option) => (
          <Button
            key={option}
            type="button"
            size="sm"
            variant={platform === option ? "default" : "outline"}
            onClick={() => onChange(option)}
            data-testid={`portal-updates-platform-${option}`}
          >
            {option}
          </Button>
        ),
      )}
    </fieldset>
  );
}

function ManifestPanel({
  state,
  manifest,
  error,
  onRetry,
  platform,
  currentVersion,
}: {
  readonly state: "pending" | "error" | "success";
  readonly manifest: UpdateManifest | undefined;
  readonly error: unknown;
  readonly onRetry: () => void;
  readonly platform: PlatformType;
  readonly currentVersion: string;
}) {
  if (state === "pending") return <SkeletonList rows={2} />;
  if (state === "error") {
    const message =
      error instanceof LaraApiError
        ? copyForErrorCode(error.errorCode)
        : "Could not read the update manifest.";

    return (
      <div
        role="alert"
        className="flex flex-col gap-3 rounded-lg border border-destructive/40 bg-[color-mix(in_oklab,var(--color-destructive)_8%,transparent)] p-4 text-sm text-destructive"
        data-testid="portal-updates-error"
      >
        <span>{message}</span>
        <Button type="button" variant="outline" size="sm" onClick={onRetry}>
          Retry
        </Button>
      </div>
    );
  }
  if (manifest === undefined) return null;

  return <ManifestReady manifest={manifest} platform={platform} currentVersion={currentVersion} />;
}

interface DownloadState {
  readonly kind: "idle" | "downloading" | "ready" | "error";
  readonly asset?: DownloadedAsset;
  readonly blobUrl?: string;
  readonly message?: string;
}

function ManifestReady({
  manifest,
  platform,
  currentVersion,
}: {
  readonly manifest: UpdateManifest;
  readonly platform: PlatformType;
  readonly currentVersion: string;
}) {
  const [download, setDownload] = useState<DownloadState>({ kind: "idle" });
  const upToDate = manifest.LatestVersion === currentVersion;
  const asset = useMemo(() => {
    try {
      return selectAssetForPlatform(manifest, platform);
    } catch (error) {
      console.warn("ManifestReady.selectAssetForPlatform failed", error);

      return null;
    }
  }, [manifest, platform]);

  const mutation = useMutation({
    mutationFn: () => downloadUpdateAsset({ manifest, platform }),
    onMutate: () => setDownload({ kind: "downloading" }),
    onSuccess: (result) => {
      const blob = new Blob([result.bytes as unknown as BlobPart], {
        type: "application/octet-stream",
      });
      const blobUrl = URL.createObjectURL(blob);
      setDownload({ kind: "ready", asset: result, blobUrl });
    },
    onError: (error) => {
      const message =
        error instanceof LaraApiError ? copyForErrorCode(error.errorCode) : "Download failed.";
      console.error("ManifestReady.downloadUpdateAsset failed", {
        errorCode: error instanceof LaraApiError ? error.errorCode : "Unknown",
        status: error instanceof LaraApiError ? error.httpStatus : 0,
      });
      setDownload({ kind: "error", message });
    },
  });

  return (
    <section
      className="flex flex-col gap-4 rounded-lg border border-border bg-card p-5"
      data-testid="portal-updates-manifest"
    >
      <header className="flex flex-col gap-1">
        <h2 className="text-lg font-semibold" style={{ fontFamily: "var(--font-display)" }}>
          {manifest.Product} {manifest.LatestVersion}
        </h2>
        <p className="text-xs text-muted-foreground">
          Channel {manifest.Channel} - Published {new Date(manifest.PublishedAt).toLocaleString()} -
          Min required {manifest.MinRequiredVersion}
        </p>
      </header>
      {upToDate ? (
        <EmptyState
          preset="box"
          headline="You are up to date"
          body={`Version ${currentVersion} is the latest ${manifest.Channel.toLowerCase()} build for ${platform}.`}
        />
      ) : (
        <>
          <dl className="grid grid-cols-2 gap-x-4 gap-y-1 text-xs">
            <dt className="text-muted-foreground">Current</dt>
            <dd className="font-mono">{currentVersion}</dd>
            <dt className="text-muted-foreground">Latest</dt>
            <dd className="font-mono">{manifest.LatestVersion}</dd>
            {asset !== null ? (
              <>
                <dt className="text-muted-foreground">Size</dt>
                <dd>{Math.round(asset.SizeBytes / 1024).toLocaleString()} KB</dd>
                <dt className="text-muted-foreground">SHA-256</dt>
                <dd className="font-mono text-[10px] break-all">{asset.Sha256}</dd>
              </>
            ) : null}
          </dl>
          <DownloadRow
            download={download}
            filename={`${manifest.Product}-${manifest.LatestVersion}-${platform}.bin`}
            disabled={asset === null || mutation.isPending}
            onDownload={() => mutation.mutate()}
          />
        </>
      )}
    </section>
  );
}

function DownloadRow({
  download,
  filename,
  disabled,
  onDownload,
}: {
  readonly download: DownloadState;
  readonly filename: string;
  readonly disabled: boolean;
  readonly onDownload: () => void;
}) {
  if (download.kind === "ready" && typeof download.blobUrl === "string") {
    return (
      <div
        className="flex flex-col gap-2 rounded-md border border-success/40 bg-[color-mix(in_oklab,var(--color-success)_8%,transparent)] p-3 text-sm"
        data-testid="portal-updates-ready"
      >
        <span>Integrity verified. Bytes: {download.asset?.sizeBytes.toLocaleString()}</span>
        <a
          href={download.blobUrl}
          download={filename}
          className="inline-flex w-fit items-center gap-2 rounded-md border border-border bg-surface-raised px-3 py-1.5 text-sm font-medium hover:bg-muted"
          data-testid="portal-updates-save"
        >
          Save {filename}
        </a>
      </div>
    );
  }
  if (download.kind === "error") {
    return (
      <div
        role="alert"
        className="rounded-md border border-destructive/40 bg-[color-mix(in_oklab,var(--color-destructive)_8%,transparent)] p-3 text-sm text-destructive"
        data-testid="portal-updates-download-error"
      >
        {download.message ?? "Download failed."}
      </div>
    );
  }

  return (
    <Button
      type="button"
      onClick={onDownload}
      disabled={disabled}
      data-testid="portal-updates-download"
    >
      {download.kind === "downloading" ? "Downloading..." : "Download update"}
    </Button>
  );
}
