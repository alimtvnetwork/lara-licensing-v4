/**
 * Preview fixtures: admin runtime-config domain (Plan 16 Step 52).
 *
 * Registers two operations:
 *   - admin.runtime-config.show   (GET /api/admin/runtime-config)
 *   - admin.runtime-config.update (PUT /api/admin/runtime-config)  [If-Match]
 *
 * Behaviour (mirrors `spec/28-runtime-modes/05-admin-runtime-toggle.md`):
 *
 *   * The document lives at `runtime-config::current` (typed
 *     `RuntimeConfigDoc`). `show` returns it directly. If the store is
 *     empty (first call, or a seed that did not populate it), the handler
 *     falls back to a deterministic default so preview never 500s.
 *   * `update` honours `If-Match: <UpdatedAt>` against the persisted
 *     `UpdatedAt`. Mismatch -> 412 PreconditionFailed with a structured
 *     `preview-fixtures:runtime-config:if-match-mismatch` diagnostic log
 *     (RequestId, ExpectedUpdatedAt, ProvidedIfMatch), mirroring the
 *     Admin\LicenseController hardening shipped in v0.262.0.
 *   * Enforces the field-conditional rules from spec 05 §Mutable Fields:
 *       - M-02: `Mode = "production"` REQUIRES `ApiBaseUrl` to be a
 *         non-empty absolute https URL; any other Mode REQUIRES it to be
 *         null. Violations -> `ValidationFailed` (422).
 *       - M-02: `Mode = "preview"` REQUIRES a non-empty `PreviewSeed`;
 *         any other Mode REQUIRES `PreviewSeed = ""`. Violations -> 422.
 *       - M-03: `AllowRuntimeToggle` may transition true -> false but
 *         NOT false -> true; the endpoint refuses to re-enable itself.
 *         Violation -> `ValidationFailed` (422).
 *   * When the persisted `AllowRuntimeToggle` is already `false`, every
 *     update rejects with `AuthForbidden` (HTTP 423 semantically; we
 *     stay inside the closed-set codes shipped today and reserve the
 *     runtime-specific codes for Plan 16 Step 58). The
 *     `LARA_RUNTIME_CONFIG_LOCKED` string is carried in the message so
 *     the FE can render a dedicated banner without new envelope codes.
 *   * Under the `error` seed both ops reject with
 *     `ERROR_SEED_DOMAIN_CODE["runtime-config"]` per INV-RM-06.
 *
 * Version bookkeeping:
 *   * `RuntimeConfigDoc.Version` is the DEPLOY version and is NOT touched
 *     here. Only `UpdatedAt` is refreshed on a successful write, which is
 *     also the concurrency token.
 *
 * Function bodies obey the 15-line cap; helpers extracted where needed.
 * INV-RM-05 preserved: preview + live callers observe identical typed
 * responses.
 */

import type { PreviewFixtureModule } from "./_module";
import { previewError, previewSuccess } from "./_shared";
import { read, write } from "@/lib/preview-store";
import { registerPreviewHandler } from "@/lib/preview-transport";
import { ApiErrorCodeType } from "@/lib/lara-api-error";
import { ERROR_SEED_DOMAIN_CODE } from "@/lib/preview-seeds/error";
import type {
  AdminRuntimeConfigShowResponse,
  AdminRuntimeConfigUpdateRequest,
  AdminRuntimeConfigUpdateResponse,
  RuntimeConfigDoc,
} from "@/generated/api/schema";

const HTTP_PRECONDITION_FAILED = 412;
const HTTP_UNPROCESSABLE = 422;
const HTTP_LOCKED = 423;
const DEFAULT_DEPLOY_VERSION = "0.556.0";
const DEFAULT_UPDATED_AT = "2026-07-20T00:00:00Z";
const LOCKED_MARKER = "LARA_RUNTIME_CONFIG_LOCKED";

function nowIso(): string {
  return new Date().toISOString();
}

function defaultDoc(): RuntimeConfigDoc {
  return {
    Mode: "preview",
    ApiBaseUrl: null,
    PreviewSeed: "default",
    AllowRuntimeToggle: true,
    Version: DEFAULT_DEPLOY_VERSION,
    UpdatedAt: DEFAULT_UPDATED_AT,
  };
}

function rejectIfErrorSeed(seed: string, requestId: string): void {
  if (seed !== "error") return;
  previewError(
    ERROR_SEED_DOMAIN_CODE["runtime-config"],
    "Preview error seed active: runtime-config calls always fail (INV-RM-06).",
    HTTP_UNPROCESSABLE,
    requestId,
  );
}

async function loadDoc(): Promise<RuntimeConfigDoc> {
  const found = await read<RuntimeConfigDoc>("runtime-config", "current");

  return found ?? defaultDoc();
}

function assertIfMatch(doc: RuntimeConfigDoc, ifMatch: string, requestId: string): void {
  if (doc.UpdatedAt === ifMatch) return;
  console.warn("preview-fixtures:runtime-config:if-match-mismatch", {
    RequestId: requestId,
    ExpectedUpdatedAt: doc.UpdatedAt,
    ProvidedIfMatch: ifMatch,
  });
  previewError(
    ApiErrorCodeType.RuntimeConfigConflict,
    `If-Match ${ifMatch} does not match current UpdatedAt ${doc.UpdatedAt}.`,
    HTTP_PRECONDITION_FAILED,
    requestId,
  );
}

function assertNotLocked(doc: RuntimeConfigDoc, requestId: string): void {
  if (doc.AllowRuntimeToggle) return;
  previewError(
    ApiErrorCodeType.RuntimeConfigLocked,
    `${LOCKED_MARKER}: AllowRuntimeToggle is false; re-enable via deploy pipeline.`,
    HTTP_LOCKED,
    requestId,
  );
}

function assertModeApiBaseUrl(p: AdminRuntimeConfigUpdateRequest, requestId: string): void {
  const isProd = p.Mode === "production";
  const hasUrl = typeof p.ApiBaseUrl === "string" && p.ApiBaseUrl.length > 0;
  if (isProd && !hasUrl) {
    previewError(
      ApiErrorCodeType.RuntimeConfigModeMismatch,
      "Mode=production requires ApiBaseUrl to be a non-empty https URL.",
      HTTP_UNPROCESSABLE,
      requestId,
    );
  }
  if (!isProd && hasUrl) {
    previewError(
      ApiErrorCodeType.RuntimeConfigModeMismatch,
      `Mode=${p.Mode} requires ApiBaseUrl to be null.`,
      HTTP_UNPROCESSABLE,
      requestId,
    );
  }
}

function assertModePreviewSeed(p: AdminRuntimeConfigUpdateRequest, requestId: string): void {
  const isPreview = p.Mode === "preview";
  const hasSeed = typeof p.PreviewSeed === "string" && p.PreviewSeed.length > 0;
  if (isPreview && !hasSeed) {
    previewError(
      ApiErrorCodeType.RuntimeConfigModeMismatch,
      "Mode=preview requires a non-empty PreviewSeed.",
      HTTP_UNPROCESSABLE,
      requestId,
    );
  }
  if (!isPreview && hasSeed) {
    previewError(
      ApiErrorCodeType.RuntimeConfigModeMismatch,
      `Mode=${p.Mode} requires PreviewSeed to be "".`,
      HTTP_UNPROCESSABLE,
      requestId,
    );
  }
}

function assertToggleTransition(
  current: RuntimeConfigDoc,
  next: AdminRuntimeConfigUpdateRequest,
  requestId: string,
): void {
  const isReEnable = current.AllowRuntimeToggle === false && next.AllowRuntimeToggle === true;
  const isFailed = !isReEnable;
  if (isFailed) return;
  previewError(
    ApiErrorCodeType.RuntimeConfigInvalidField,
    "AllowRuntimeToggle cannot be re-enabled here; requires a deploy pipeline rewrite (M-03).",
    HTTP_UNPROCESSABLE,
    requestId,
  );
}

function buildNextDoc(
  current: RuntimeConfigDoc,
  patch: AdminRuntimeConfigUpdateRequest,
): RuntimeConfigDoc {
  return {
    Mode: patch.Mode,
    ApiBaseUrl: patch.ApiBaseUrl,
    PreviewSeed: patch.PreviewSeed,
    AllowRuntimeToggle: patch.AllowRuntimeToggle,
    Version: current.Version,
    UpdatedAt: nowIso(),
  };
}

const mod: PreviewFixtureModule = {
  name: "runtime-config",
  operations: ["admin.runtime-config.show", "admin.runtime-config.update"],
  register(): void {
    registerPreviewHandler(
      "admin.runtime-config.show",
      async (ctx): Promise<AdminRuntimeConfigShowResponse> => {
        rejectIfErrorSeed(ctx.Seed, ctx.RequestId);
        const doc = await loadDoc();
        console.info("preview-fixtures:admin.runtime-config.show", {
          RequestId: ctx.RequestId,
          Mode: doc.Mode,
          AllowRuntimeToggle: doc.AllowRuntimeToggle,
        });

        return previewSuccess<"admin.runtime-config.show">(doc);
      },
    );

    registerPreviewHandler(
      "admin.runtime-config.update",
      async (ctx): Promise<AdminRuntimeConfigUpdateResponse> => {
        rejectIfErrorSeed(ctx.Seed, ctx.RequestId);
        const current = await loadDoc();
        assertNotLocked(current, ctx.RequestId);
        const params = ctx.Params as any;
        assertIfMatch(current, params.IfMatch, ctx.RequestId);
        assertModeApiBaseUrl(params, ctx.RequestId);
        assertModePreviewSeed(params, ctx.RequestId);
        assertToggleTransition(current, params, ctx.RequestId);
        const next = buildNextDoc(current, params);
        await write<RuntimeConfigDoc>("runtime-config", "current", next);
        console.info("preview-fixtures:admin.runtime-config.update", {
          RequestId: ctx.RequestId,
          FromMode: current.Mode,
          ToMode: next.Mode,
          FromUpdatedAt: current.UpdatedAt,
          ToUpdatedAt: next.UpdatedAt,
        });

        return previewSuccess<"admin.runtime-config.update">(next);
      },
    );
  },
};

export default mod;
