import { useState, type FormEvent } from "react";
import { Loader2, Rocket, Upload } from "lucide-react";

import { formatLaraApiError } from "../../lib/lara-api-error";
import {
  computeSha256Hex,
  publishAppUpdate,
  reserveUploadTicket,
  uploadAssetBytes,
  type PublishAssetInput,
} from "../../lib/lara-app-updates";

/**
 * Plan 09 step: Admin publish upload dialog.
 *
 * Runs the three-leg publish saga entirely in-browser: reserve an upload
 * ticket per platform, PUT the raw bytes to the returned UploadUrl, then
 * finalize via POST /Admin/AppUpdates with the ticket tokens. SHA-256 is
 * computed client-side via crypto.subtle so the server side can reject
 * mismatched uploads deterministically (AC-SU-DB-002).
 *
 * Closed sets mirror backend config lara.self_update.platforms exactly.
 */

const PLATFORMS = ["WindowsAmd64", "LinuxAmd64", "DarwinArm64"] as const;
const PRODUCT = "lara-cli";
const CHANNEL = "Stable";

type PlatformName = (typeof PLATFORMS)[number];

interface Props {
  onClose: () => void;
  onPublished: () => void;
}

interface PlatformFile {
  file: File | null;
  sha256?: string;
}

export function PublishBuildDialog({ onClose, onPublished }: Props) {
  const [version, setVersion] = useState("");
  const [minRequired, setMinRequired] = useState("");
  const [notesUrl, setNotesUrl] = useState("");
  const [files, setFiles] = useState<Record<PlatformName, PlatformFile>>({
    WindowsAmd64: { file: null },
    LinuxAmd64: { file: null },
    DarwinArm64: { file: null },
  });
  const [busy, setBusy] = useState(false);
  const [progress, setProgress] = useState<string>("");
  const [error, setError] = useState<string | null>(null);

  const canSubmit =
    version.trim() !== "" &&
    minRequired.trim() !== "" &&
    PLATFORMS.every((p) => files[p].file !== null);

  async function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!canSubmit || busy) return;
    setBusy(true);
    setError(null);
    try {
      const assets: PublishAssetInput[] = [];
      for (const platform of PLATFORMS) {
        const entry = files[platform];
        if (entry.file === null) throw new Error(`Missing file for ${platform}`);
        setProgress(`Hashing ${platform}...`);
        const bytes = await entry.file.arrayBuffer();
        const sha256 = await computeSha256Hex(bytes);
        setProgress(`Reserving ticket for ${platform}...`);
        const ticket = await reserveUploadTicket({
          Product: PRODUCT,
          Version: version.trim(),
          Platform: platform,
          SizeBytes: bytes.byteLength,
          Sha256: sha256,
          IdempotencyKey: `ticket-${PRODUCT}-${version.trim()}-${platform}-${crypto.randomUUID()}`,
        });
        setProgress(`Uploading ${platform} (${(bytes.byteLength / 1024).toFixed(0)} KiB)...`);
        await uploadAssetBytes(ticket.UploadUrl, bytes);
        assets.push({
          Platform: platform,
          Sha256: sha256,
          SizeBytes: bytes.byteLength,
          UploadToken: ticket.UploadToken,
        });
      }
      setProgress("Finalizing publish...");
      await publishAppUpdate({
        Product: PRODUCT,
        Channel: CHANNEL,
        Version: version.trim(),
        MinRequiredVersion: minRequired.trim(),
        ReleaseNotesUrl: notesUrl.trim() === "" ? null : notesUrl.trim(),
        Assets: assets,
        IdempotencyKey: `publish-${PRODUCT}-${version.trim()}-${crypto.randomUUID()}`,
      });
      setProgress("");
      onPublished();
      onClose();
    } catch (failure) {
      pushLaraApiError(new Error());
      setError(
        failure instanceof Error && !("errorCode" in failure)
          ? failure.message
          : formatLaraApiError(failure),
      );
    } finally {
      setBusy(false);
    }
  }

  return (
    <div
      role="dialog"
      aria-modal="true"
      className="fixed inset-0 z-50 grid place-items-center bg-background/80 p-4 backdrop-blur-sm"
    >
      <form
        onSubmit={handleSubmit}
        className="w-full max-w-lg space-y-4 rounded-xl border border-border bg-card p-6 shadow-xl"
      >
        <div className="flex items-center gap-2">
          <Rocket className="size-5 text-primary" aria-hidden="true" />
          <h2 className="text-base font-semibold">Publish new build</h2>
        </div>

        <div className="grid grid-cols-2 gap-3">
          <label className="text-sm">
            <span className="mb-1 block font-medium">Version</span>
            <input
              value={version}
              onChange={(e) => setVersion(e.target.value)}
              placeholder="1.2.0"
              required
              disabled={busy}
              className="w-full rounded-md border border-input bg-background px-2 py-1.5 font-mono text-xs focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
            />
          </label>
          <label className="text-sm">
            <span className="mb-1 block font-medium">Min required version</span>
            <input
              value={minRequired}
              onChange={(e) => setMinRequired(e.target.value)}
              placeholder="1.0.0"
              required
              disabled={busy}
              className="w-full rounded-md border border-input bg-background px-2 py-1.5 font-mono text-xs focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
            />
          </label>
        </div>

        <label className="block text-sm">
          <span className="mb-1 block font-medium">Release notes URL (optional)</span>
          <input
            type="url"
            value={notesUrl}
            onChange={(e) => setNotesUrl(e.target.value)}
            placeholder="https://..."
            disabled={busy}
            className="w-full rounded-md border border-input bg-background px-2 py-1.5 text-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
          />
        </label>

        <fieldset className="space-y-2">
          <legend className="text-sm font-medium">Assets (one file per platform)</legend>
          {PLATFORMS.map((platform) => (
            <label
              key={platform}
              className="flex items-center justify-between gap-3 rounded-md border border-border px-3 py-2 text-xs"
            >
              <span className="font-mono">{platform}</span>
              <input
                type="file"
                required
                disabled={busy}
                onChange={(e) =>
                  setFiles((prev) => ({
                    ...prev,
                    [platform]: { file: e.target.files?.[0] ?? null },
                  }))
                }
                className="max-w-[16rem] text-xs"
              />
            </label>
          ))}
        </fieldset>

        {progress !== "" ? (
          <p className="flex items-center gap-2 text-xs text-muted-foreground" role="status">
            <Loader2 className="size-3.5 animate-spin" aria-hidden="true" /> {progress}
          </p>
        ) : null}
        {error !== null ? (
          <p
            role="alert"
            className="rounded-md border border-destructive/40 bg-destructive/5 p-2 text-xs text-destructive"
          >
            {error}
          </p>
        ) : null}

        <div className="flex justify-end gap-2 pt-2">
          <button
            type="button"
            onClick={onClose}
            disabled={busy}
            className="focus-ring h-9 rounded-md border border-input px-3 text-sm font-medium surface-hover disabled:opacity-50"
          >
            Cancel
          </button>
          <button
            type="submit"
            disabled={!canSubmit || busy}
            className="focus-ring inline-flex h-9 items-center gap-2 rounded-md bg-primary px-3 text-sm font-semibold text-primary-foreground hover:bg-primary/90 disabled:cursor-not-allowed disabled:opacity-50"
          >
            {busy ? (
              <Loader2 className="size-3.5 animate-spin" aria-hidden="true" />
            ) : (
              <Upload className="size-3.5" aria-hidden="true" />
            )}
            Publish build
          </button>
        </div>
      </form>
    </div>
  );
}
