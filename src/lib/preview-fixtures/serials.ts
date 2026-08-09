/**
 * Preview fixtures: portal serials domain (Plan 16 Step 44).
 *
 * Registers `portal.serials.lookup` (GET /api/portal/serials/:Serial).
 * Reads the `serials::<Serial>` reverse index seeded by
 * preview-seeds/default.ts (and by admin.licenses.create in
 * src/lib/preview-fixtures/licenses.ts) to hydrate the License row from
 * `licenses::<LicenseId>`, mirroring the backend join in
 * backend/app/Http/Controllers/Portal/SerialController.php.
 *
 * Failure surfaces:
 *  - Unknown serial: SerialNotFound (HTTP 404).
 *  - Dangling reverse index (points to missing license): LicenseNotFound
 *    (HTTP 404). Logged with RequestId so the drift is visible.
 *  - `error` seed: ERROR_SEED_DOMAIN_CODE.serials (HTTP 422) per
 *    INV-RM-06.
 *
 * INV-RM-05: preview + live callers observe identical response shape.
 * Function bodies obey the 15-line cap.
 */

import type { PreviewFixtureModule } from "./_module";
import { previewError, previewSuccess } from "./_shared";
import { read } from "@/lib/preview-store";
import { registerPreviewHandler } from "@/lib/preview-transport";
import { ApiErrorCodeType } from "@/lib/lara-api-error";
import { ERROR_SEED_DOMAIN_CODE } from "@/lib/preview-seeds/error";
import type { License, PortalSerialLookupResponse } from "@/generated/api/schema";

const HTTP_NOT_FOUND = 404;
const HTTP_UNPROCESSABLE = 422;

interface SerialIndexEntry {
  Serial: string;
  LicenseId: string;
}

function rejectIfErrorSeed(seed: string, requestId: string): void {
  if (seed !== "error") return;
  previewError(
    ERROR_SEED_DOMAIN_CODE.serials,
    "Preview error seed active: serial lookup always fails (INV-RM-06).",
    HTTP_UNPROCESSABLE,
    requestId,
  );
}

function normaliseSerial(raw: string): string {
  return String(raw ?? "")
    .trim()
    .toUpperCase();
}

async function loadIndex(serial: string): Promise<SerialIndexEntry | null> {
  return (await read<SerialIndexEntry>("serials", serial)) ?? null;
}

async function loadLicense(id: string): Promise<License | null> {
  return (await read<License>("licenses", id)) ?? null;
}

function project(license: License): PortalSerialLookupResponse {
  return {
    Serial: license.Serial,
    Status: license.Status,
    ExpiresAt: license.ExpiresAt,
    Features: [...license.Features],
    IssuedAt: license.IssuedAt,
  };
}

const mod: PreviewFixtureModule = {
  name: "serials",
  operations: ["portal.serials.lookup"],
  register(): void {
    registerPreviewHandler(
      "portal.serials.lookup",
      async (ctx): Promise<PortalSerialLookupResponse> => {
        rejectIfErrorSeed(ctx.Seed, ctx.RequestId);
        const serial = normaliseSerial(ctx.Params.Serial);
        const index = await loadIndex(serial);
        if (!index) {
          console.info("preview-fixtures:portal.serials.lookup:not-found", {
            RequestId: ctx.RequestId,
            Serial: serial,
          });
          previewError(
            ApiErrorCodeType.SerialNotFound,
            `Serial "${serial}" not found.`,
            HTTP_NOT_FOUND,
            ctx.RequestId,
          );
        }
        const license = await loadLicense(index.LicenseId);
        if (!license) {
          console.warn("preview-fixtures:portal.serials.lookup:dangling-index", {
            RequestId: ctx.RequestId,
            Serial: serial,
            LicenseId: index.LicenseId,
          });
          previewError(
            ApiErrorCodeType.LicenseNotFound,
            `License for serial "${serial}" is missing (dangling reverse index).`,
            HTTP_NOT_FOUND,
            ctx.RequestId,
          );
        }
        console.info("preview-fixtures:portal.serials.lookup", {
          RequestId: ctx.RequestId,
          Serial: serial,
          LicenseId: license.Id,
          Status: license.Status,
        });

        return previewSuccess<"portal.serials.lookup">(project(license));
      },
    );
  },
};

export default mod;
