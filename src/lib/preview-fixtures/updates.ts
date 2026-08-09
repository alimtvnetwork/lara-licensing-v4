/**
 * Preview fixtures: portal + admin updates domain (Plan 18 Phase D).
 *
 * Registers:
 *   - portal.updates.manifest (GET /api/portal/updates)
 *   - admin.appUpdates.list   (GET /api/admin/updates)

 * backend contract returns:
 *   - `Latest`: highest-version UpdateManifestEntry, or null if the
 *     store has no rows.
 *   - `Available`: entries strictly newer than `CurrentVersion`, sorted
 *     descending. The seeded catalog only carries the `stable` channel
 *     today; when the request asks for `beta` we still enumerate the
 *     same rows because backend `UpdatesController@index` falls back to
 *     `stable` for `beta` when no beta entries exist (see
 *     backend/app/Http/Controllers/Portal/UpdatesController.php).
 *
 * Under the `error` seed the handler rejects with
 * `ERROR_SEED_DOMAIN_CODE.updates` (ValidationFailed, HTTP 422) per
 * INV-RM-06.
 *
 * INV-RM-05: preview + live callers observe the same
 * `PortalUpdatesResponse` shape.
 * Function bodies obey the 15-line cap.
 */

import type { PreviewFixtureModule } from "./_module";
import { previewError, previewSuccess } from "./_shared";
import { list } from "@/lib/preview-store";
import { registerPreviewHandler } from "@/lib/preview-transport";
import { ERROR_SEED_DOMAIN_CODE } from "@/lib/preview-seeds/error";
import type {
  AdminAppUpdate,
  AdminAppUpdatesListResponse,
  PortalUpdatesResponse,
  UpdateManifestEntry,
} from "@/generated/api/schema";

const HTTP_UNPROCESSABLE = 422;

function rejectIfErrorSeed(seed: string, requestId: string): void {
  if (seed !== "error") return;
  previewError(
    ERROR_SEED_DOMAIN_CODE.updates,
    "Preview error seed active: updates calls always fail (INV-RM-06).",
    HTTP_UNPROCESSABLE,
    requestId,
  );
}

async function loadAllUpdates(): Promise<UpdateManifestEntry[]> {
  const rows = await list<UpdateManifestEntry>("updates");

  return rows.map(([, v]) => v);
}

// Numeric-tuple compare of dotted semver segments so "1.10.0" > "1.9.0".
// Missing segments default to 0. Non-numeric labels are compared lexically.
function compareVersions(a: string, b: string): number {
  const aa = a.split(".");
  const bb = b.split(".");
  const len = Math.max(aa.length, bb.length);
  for (let i = 0; i < len; i += 1) {
    const na = Number.parseInt(aa[i] ?? "0", 10);
    const nb = Number.parseInt(bb[i] ?? "0", 10);
    if (Number.isNaN(na) || Number.isNaN(nb)) {
      const cmp = (aa[i] ?? "").localeCompare(bb[i] ?? "");
      if (cmp !== 0) return cmp;
      continue;
    }
    if (na !== nb) return na - nb;
  }

  return 0;
}

function sortDescending(items: UpdateManifestEntry[]): UpdateManifestEntry[] {
  return [...items].sort((a, b) => compareVersions(b.Version, a.Version));
}

function filterAvailable(
  all: UpdateManifestEntry[],
  currentVersion: string,
): UpdateManifestEntry[] {
  return all.filter((u) => compareVersions(u.Version, currentVersion) > 0);
}

const mod: PreviewFixtureModule = {
  name: "updates",
  operations: ["portal.updates.manifest", "admin.appUpdates.list"],
  register(): void {
    registerPreviewHandler(
      "portal.updates.manifest",

      async (ctx): Promise<PortalUpdatesResponse> => {
        rejectIfErrorSeed(ctx.Seed, ctx.RequestId);
        const all = sortDescending(await loadAllUpdates());
        const latest = all[0] ?? null;
        const available = filterAvailable(all, ctx.Params.CurrentVersion);
        console.info("preview-fixtures:portal.updates.manifest", {
          RequestId: ctx.RequestId,
          CurrentVersion: ctx.Params.CurrentVersion,
          Channel: ctx.Params.Channel,
          LatestVersion: latest?.Version ?? null,
          AvailableCount: available.length,
        });

        return previewSuccess<"portal.updates.manifest">({ Latest: latest, Available: available });
      },
    );

    registerPreviewHandler(
      "admin.appUpdates.list",
      async (ctx): Promise<AdminAppUpdatesListResponse> => {
        rejectIfErrorSeed(ctx.Seed, ctx.RequestId);
        const all = await loadAllUpdates();
        const query = ctx.Params.Query?.toLowerCase();

        const items: AdminAppUpdate[] = all
          .filter((u) => !query || u.Version.toLowerCase().includes(query))
          .map((u, i) => ({
            Version: u.Version,
            ReleasedAt: u.ReleasedAt,
            InstalledAt: i === all.length - 1 ? u.ReleasedAt : null,
            Status: i === all.length - 1 ? "installed" : i === 0 ? "available" : "pending",
          }));

        console.info("preview-fixtures:admin.appUpdates.list", {
          RequestId: ctx.RequestId,
          Count: items.length,
          Query: query ?? null,
        });

        return previewSuccess<"admin.appUpdates.list">({ Items: items });
      },
    );
  },
};

export default mod;
