/**
 * Preview fixtures: admin licenses domain (Plan 16 Step 41).
 *
 * Registers preview handlers for the five admin license operations:
 *   - admin.licenses.list   (GET    /api/admin/licenses)
 *   - admin.licenses.show   (GET    /api/admin/licenses/:Id)
 *   - admin.licenses.create (POST   /api/admin/licenses)
 *   - admin.licenses.update (PATCH  /api/admin/licenses/:Id)  [If-Match]
 *   - admin.licenses.delete (DELETE /api/admin/licenses/:Id)  [If-Match]
 *
 * Behaviour:
 *   * Seed data lives at `licenses::<Id>` (keyed by ULID). List enumerates
 *     the store and applies Query / Status / ResellerId filters exactly
 *     like `Admin\LicenseController@index` in backend/.
 *   * Update/delete honour `If-Match: <Version>` against the persisted
 *     `Version` field. Mismatches throw `PreconditionFailed` (HTTP 412)
 *     mirroring the backend hardening shipped in v0.262.0. Success paths
 *     bump `Version` and `UpdatedAt` so the next optimistic write must
 *     read the new value first (INV-BR-* Merkle discipline maps here for
 *     resource versioning).
 *   * Under the `error` seed every operation rejects with
 *     `ERROR_SEED_DOMAIN_CODE.licenses` per INV-RM-06.
 *
 * Function bodies obey the 15-line cap; helpers extracted where needed.
 */

import type { PreviewFixtureModule } from "./_module";
import { previewError, previewSuccess } from "./_shared";
import { list, read, remove, write } from "@/lib/preview-store";
import { registerPreviewHandler } from "@/lib/preview-transport";
import { ApiErrorCodeType } from "@/lib/lara-api-error";
import { ERROR_SEED_DOMAIN_CODE } from "@/lib/preview-seeds/error";
import type {
  AdminLicenseCreateRequest,
  AdminLicenseCreateResponse,
  AdminLicenseDeleteRequest,
  AdminLicenseDeleteResponse,
  AdminLicenseShowRequest,
  AdminLicenseShowResponse,
  AdminLicenseUpdateRequest,
  AdminLicenseUpdateResponse,
  AdminLicensesListRequest,
  AdminLicensesListResponse,
  License,
  LicenseStatus,
} from "@/generated/api/schema";

const HTTP_NOT_FOUND = 404;
const HTTP_PRECONDITION_FAILED = 412;
const HTTP_UNPROCESSABLE = 422;
const DEFAULT_PAGE_SIZE = 25;

function nowIso(): string {
  return new Date().toISOString();
}

function newLicenseId(): string {
  const suffix = Math.random().toString(16).slice(2, 10).toUpperCase().padEnd(8, "0");

  return `01H00000000000000LICNEW${suffix}`.slice(0, 26);
}

function newSerial(): string {
  const chunk = Math.random().toString(36).slice(2, 6).toUpperCase().padEnd(4, "X");

  return `LARA-${chunk}-0001`;
}

function rejectIfErrorSeed(seed: string, requestId: string): void {
  if (seed !== "error") return;
  previewError(
    ERROR_SEED_DOMAIN_CODE.licenses,
    "Preview error seed active: licenses calls always fail (INV-RM-06).",
    HTTP_UNPROCESSABLE,
    requestId,
  );
}

async function readLicense(id: string, requestId: string): Promise<License> {
  const found = await read<License>("licenses", id);
  const isFailed = !found;
  if (isFailed) {
    previewError(
      ApiErrorCodeType.LicenseNotFound,
      `License ${id} not found in preview store.`,
      HTTP_NOT_FOUND,
      requestId,
    );
  }

  return found;
}

function assertVersionMatch(license: License, ifMatch: string, requestId: string): void {
  if (String(license.Version) === String(ifMatch)) return;
  console.warn("preview-fixtures:licenses:if-match-mismatch", {
    RequestId: requestId,
    LicenseId: license.Id,
    ExpectedVersion: license.Version,
    ProvidedIfMatch: ifMatch,
  });
  previewError(
    ApiErrorCodeType.PreconditionFailed,
    `If-Match ${ifMatch} does not match current Version ${license.Version}.`,
    HTTP_PRECONDITION_FAILED,
    requestId,
  );
}

async function loadAllLicenses(): Promise<License[]> {
  const rows = await list<License>("licenses");

  return rows.map(([, v]) => v);
}

function matchesFilters(l: License, p: AdminLicensesListRequest): boolean {
  if (p.Status && l.Status !== p.Status) return false;
  if (p.ResellerId && l.ResellerId !== p.ResellerId) return false;
  if (!p.Query) return true;
  const q = p.Query.toLowerCase();

  return (
    l.Serial.toLowerCase().includes(q) ||
    l.CustomerName.toLowerCase().includes(q) ||
    l.CustomerEmail.toLowerCase().includes(q)
  );
}

function paginate(items: License[], cursor: string | null | undefined): AdminLicensesListResponse {
  const start = cursor ? Number.parseInt(cursor, 10) || 0 : 0;
  const end = start + DEFAULT_PAGE_SIZE;
  const slice = items.slice(start, end);
  const next = end < items.length ? String(end) : null;

  return { Items: slice, Cursor: next, Total: items.length };
}

function buildNewLicense(p: AdminLicenseCreateRequest): License {
  const now = nowIso();

  return {
    Id: newLicenseId(),
    Serial: newSerial(),
    Status: "active" as LicenseStatus,
    CustomerName: p.CustomerName,
    CustomerEmail: p.CustomerEmail,
    ResellerId: p.ResellerId,
    IssuedAt: now,
    ExpiresAt: p.ExpiresAt,
    Features: p.Features,
    MaxActivations: p.MaxActivations,
    ActiveActivations: 0,
    Version: 1,
    CreatedAt: now,
    UpdatedAt: now,
  };
}

function applyPatch(current: License, patch: AdminLicenseUpdateRequest): License {
  return {
    ...current,
    CustomerName: patch.CustomerName ?? current.CustomerName,
    CustomerEmail: patch.CustomerEmail ?? current.CustomerEmail,
    Features: patch.Features ?? current.Features,
    MaxActivations: patch.MaxActivations ?? current.MaxActivations,
    ExpiresAt: patch.ExpiresAt !== undefined ? patch.ExpiresAt : current.ExpiresAt,
    Status: patch.Status ?? current.Status,
    Version: current.Version + 1,
    UpdatedAt: nowIso(),
  };
}

const mod: PreviewFixtureModule = {
  name: "licenses",
  operations: [
    "admin.licenses.list",
    "admin.licenses.show",
    "admin.licenses.create",
    "admin.licenses.update",
    "admin.licenses.delete",
  ],
  register(): void {
    registerPreviewHandler(
      "admin.licenses.list",
      async (ctx): Promise<AdminLicensesListResponse> => {
        rejectIfErrorSeed(ctx.Seed, ctx.RequestId);
        const all = await loadAllLicenses();
        const params = ctx.Params as AdminLicensesListRequest;
        const filtered = all.filter((l) => matchesFilters(l, params));
        console.info("preview-fixtures:admin.licenses.list", {
          RequestId: ctx.RequestId,
          Total: filtered.length,
        });

        return previewSuccess<"admin.licenses.list">(paginate(filtered, params.Cursor));
      },
    );

    registerPreviewHandler(
      "admin.licenses.show",
      async (ctx): Promise<AdminLicenseShowResponse> => {
        rejectIfErrorSeed(ctx.Seed, ctx.RequestId);
        const params = ctx.Params as AdminLicenseShowRequest;
        const license = await readLicense(params.Id, ctx.RequestId);

        return previewSuccess<"admin.licenses.show">(license);
      },
    );

    registerPreviewHandler(
      "admin.licenses.create",
      async (ctx): Promise<AdminLicenseCreateResponse> => {
        rejectIfErrorSeed(ctx.Seed, ctx.RequestId);
        const license = buildNewLicense(ctx.Params);
        await write<License>("licenses", license.Id, license);
        await write<{ Serial: string; LicenseId: string }>("serials", license.Serial, {
          Serial: license.Serial,
          LicenseId: license.Id,
        });
        console.info("preview-fixtures:admin.licenses.create", {
          RequestId: ctx.RequestId,
          LicenseId: license.Id,
          Serial: license.Serial,
        });

        return previewSuccess<"admin.licenses.create">(license);
      },
    );

    registerPreviewHandler(
      "admin.licenses.update",
      async (ctx): Promise<AdminLicenseUpdateResponse> => {
        rejectIfErrorSeed(ctx.Seed, ctx.RequestId);
        const current = await readLicense(ctx.Params.Id, ctx.RequestId);
        assertVersionMatch(current, ctx.Params.IfMatch, ctx.RequestId);
        const next = applyPatch(current, ctx.Params);
        await write<License>("licenses", next.Id, next);
        console.info("preview-fixtures:admin.licenses.update", {
          RequestId: ctx.RequestId,
          LicenseId: next.Id,
          FromVersion: current.Version,
          ToVersion: next.Version,
        });

        return previewSuccess<"admin.licenses.update">(next);
      },
    );

    registerPreviewHandler(
      "admin.licenses.delete",
      async (ctx): Promise<AdminLicenseDeleteResponse> => {
        rejectIfErrorSeed(ctx.Seed, ctx.RequestId);
        const current = await readLicense(ctx.Params.Id, ctx.RequestId);
        assertVersionMatch(current, ctx.Params.IfMatch, ctx.RequestId);
        await remove("licenses", current.Id);
        await remove("serials", current.Serial);
        console.info("preview-fixtures:admin.licenses.delete", {
          RequestId: ctx.RequestId,
          LicenseId: current.Id,
        });

        return previewSuccess<"admin.licenses.delete">({} as AdminLicenseDeleteResponse);
      },
    );
  },
};

export default mod;
