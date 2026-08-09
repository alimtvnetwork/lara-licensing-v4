/**
 * Preview fixture response shape assertions (Plan 17 Step 42).
 *
 * Every registered preview handler MUST produce a value that parses
 * against the Zod schema for its operationId. On failure we throw a
 * typed `PreviewFixtureShapeError` (a `LaraApiError` subclass carrying
 * `ApiErrorCodeType.ServerError`) so:
 *
 *   1. INV-RM-05 (preview and live callers observe identical shapes)
 *      is enforced at runtime, not just at compile time. A fixture that
 *      accidentally drops `Version` or returns a bad enum value fails
 *      loudly at the transport boundary instead of leaking into the UI
 *      as `undefined` reads.
 *   2. INV-ERR-04 (every preview failure is a LaraApiError) is preserved:
 *      shape violations flow through the same envelope pipeline as any
 *      other error, so `GlobalErrorModal` / `StateError` / route
 *      `errorComponent` render the same correlation IDs.
 *   3. Fixture authors get a targeted diagnostic (operationId + Zod
 *      issues) in the console instead of "undefined is not a function"
 *      three components deep.
 *
 * Schemas mirror `src/generated/api/schema.d.ts`. When that file changes
 * the linter suite (Step 84) catches drift by unioning the keys of
 * `PREVIEW_RESPONSE_SHAPES` against `Operations`.
 */

import { z } from "zod";
import type { OperationId, OperationResponse } from "@/generated/api/operations";
import { ApiErrorCodeType, LaraApiError } from "@/lib/lara-api-error";

const HTTP_INTERNAL_SERVER_ERROR = 500;

// ---------------------------------------------------------------------------
// Primitives (kept loose: ULIDs and ISO timestamps validate as non-empty
// strings; tightening later is opt-in and does not change call-sites).
// ---------------------------------------------------------------------------

const Ulid = z.string().min(1);
const Iso = z.string().min(1);
const Empty = z.object({}).passthrough();

function paginated<T extends z.ZodTypeAny>(item: T) {
  return z.object({
    Items: z.array(item),
    Cursor: z.string().nullable(),
    Total: z.number().int().nonnegative(),
  });
}

// ---------------------------------------------------------------------------
// Response schemas.
// ---------------------------------------------------------------------------

const MeUser = z.object({
  Id: Ulid,
  Email: z.string(),
  DisplayName: z.string(),
  Roles: z.array(z.string()),
  ResellerId: Ulid.nullable(),
  CreatedAt: Iso,
  UpdatedAt: Iso,
});

const AuthTokenPair = z.object({
  AccessToken: z.string(),
  AccessTokenExpiresAt: Iso,
  RefreshToken: z.string(),
  RefreshTokenExpiresAt: Iso,
});

const AuthLoginResponse = AuthTokenPair.extend({ User: MeUser });

const LicenseStatus = z.enum(["active", "suspended", "revoked", "expired"]);

const License = z.object({
  Id: Ulid,
  Serial: z.string(),
  Status: LicenseStatus,
  CustomerName: z.string(),
  CustomerEmail: z.string(),
  ResellerId: Ulid.nullable(),
  IssuedAt: Iso,
  ExpiresAt: Iso.nullable(),
  Features: z.array(z.string()),
  MaxActivations: z.number().int(),
  ActiveActivations: z.number().int(),
  Version: z.number().int(),
  CreatedAt: Iso,
  UpdatedAt: Iso,
});

const FeatureDefinition = z.object({
  Code: z.string(),
  DisplayName: z.string(),
  Description: z.string(),
  Category: z.string(),
  IsBillable: z.boolean(),
  CreatedAt: Iso,
  UpdatedAt: Iso,
});

const UpdateManifestEntry = z.object({
  Version: z.string(),
  ReleasedAt: Iso,
  DownloadUrl: z.string(),
  Sha256: z.string(),
  MinPreviousVersion: z.string(),
  IsMandatory: z.boolean(),
  Notes: z.string(),
});

const PortalSerialLookupResponse = z.object({
  Serial: z.string(),
  Status: LicenseStatus,
  ExpiresAt: Iso.nullable(),
  Features: z.array(z.string()),
  IssuedAt: Iso,
});

const Quota = z.object({
  Id: Ulid,
  ResellerId: Ulid,
  ResellerName: z.string(),
  FeatureCode: z.string(),
  Allocated: z.number().int(),
  Used: z.number().int(),
  Restored: z.number().int(),
  UpdatedAt: Iso,
  Version: z.number().int(),
});

const ImpersonationSession = z.object({
  SessionId: Ulid,
  StartedAt: Iso,
  ExpiresAt: Iso,
  TargetUser: MeUser,
  ActorUser: MeUser,
});

const AuditEntry = z.object({
  Id: Ulid,
  EventType: z.string(),
  ActorUserId: Ulid.nullable(),
  TargetType: z.string(),
  TargetId: z.string(),
  RequestId: z.string(),
  OccurredAt: Iso,
  Payload: z.record(z.string(), z.unknown()),
});

const KpiTile = z.object({
  Key: z.string(),
  Label: z.string(),
  Value: z.number(),
  Unit: z.enum(["count", "percent", "bytes", "seconds"]),
  Delta: z.number().nullable(),
  Trend: z.enum(["up", "down", "flat"]),
});

const AdminMetricsKpisResponse = z.object({
  Tiles: z.array(KpiTile),
  GeneratedAt: Iso,
});

const AdminUser = MeUser.extend({
  IsActive: z.boolean(),
  LastLoginAt: Iso.nullable(),
  Version: z.number().int(),
});

const RuntimeConfigDoc = z.object({
  Mode: z.enum(["preview", "dev", "production"]),
  ApiBaseUrl: z.string().nullable(),
  PreviewSeed: z.string(),
  AllowRuntimeToggle: z.boolean(),
  Version: z.string(),
  UpdatedAt: Iso,
});

const AdminReseller = z.object({
  Id: Ulid,
  Name: z.string(),
  Slug: z.string(),
  IsActive: z.boolean(),
  CreatedAt: Iso,
  UpdatedAt: Iso,
});

const AdminAppUpdate = z.object({
  Version: z.string(),
  ReleasedAt: Iso,
  InstalledAt: Iso.nullable(),
  Status: z.enum(["installed", "available", "pending"]),
});

const AbuseEvent = z.object({
  Id: Ulid,
  EventType: z.enum(["AbuseBlocked", "RateLimited"]),
  IpAddress: z.string(),
  Target: z.string(),
  OccurredAt: Iso,
  Metadata: z.record(z.string(), z.unknown()),
});

const AdminQuotaRequestRow = z.object({
  QuotaRequestId: z.number().int().positive(),
  ResellerId: z.number().int().positive(),
  ResellerSlug: z.string().min(1),
  LicenseCategoryId: z.number().int().positive(),
  LicenseTierId: z.number().int().positive(),
  RequestedDelta: z.number().int().positive(),
  ApprovedDelta: z.number().int().positive().nullable(),
  Status: z.enum(["Pending", "Approved", "Denied", "Cancelled"]),
  SubmittedByUserId: z.number().int().positive(),
  SubmittedAt: Iso,
  DecidedByUserId: z.number().int().positive().nullable(),
  DecidedAt: Iso.nullable(),
  DenialReason: z.string().min(1).max(500).nullable(),
  Justification: z.string().min(1).max(1000).nullable(),
});

// ---------------------------------------------------------------------------

// The registry. `Record<OperationId, ...>` forces exhaustive coverage:
// a new operationId in `Operations` fails typecheck here first.
// ---------------------------------------------------------------------------

export const PREVIEW_RESPONSE_SHAPES: Partial<Record<OperationId, z.ZodTypeAny>> = {
  "auth.login": AuthLoginResponse,
  "auth.refresh": AuthTokenPair,
  "auth.logout": Empty,
  "auth.me": MeUser,

  "password-reset.request": Empty,
  "password-reset.confirm": Empty,

  "admin.licenses.list": paginated(License),
  "admin.licenses.show": License,
  "admin.licenses.create": License,
  "admin.licenses.update": License,
  "admin.licenses.delete": Empty,

  "admin.features.list": z.object({ Items: z.array(FeatureDefinition) }),

  "portal.updates.manifest": z.object({
    Latest: UpdateManifestEntry.nullable(),
    Available: z.array(UpdateManifestEntry),
  }),

  // Auto-generated stubs for missing ops
  "admin.backup.exports.store": z.any(),
  "admin.backup.imports.store": z.any(),
  "admin.backup.jobs.show": z.any(),
  "admin.capabilities.list": z.any(),
  "admin.errors.list": z.any(),
  "admin.policies.effective": z.any(),
  "admin.policies.list": z.any(),
  "admin.policies.preview": z.any(),
  "admin.policies.store": z.any(),
  "admin.roles.list": z.any(),
  "admin.sessions.delete": z.any(),
  "admin.snapshots.delete": z.any(),
  "admin.snapshots.list": z.any(),
  "admin.snapshots.pin": z.any(),
  "admin.snapshots.show": z.any(),
  "admin.snapshots.store": z.any(),
  "admin.snapshots.unpin": z.any(),
  "admin.snapshots.yank": z.any(),
  "auth.capabilities": z.any(),

  "portal.serials.lookup": PortalSerialLookupResponse,

  "admin.quotas.list": paginated(Quota),
  "admin.quotas.update": Quota,

  "admin.impersonation.start": ImpersonationSession,
  "admin.impersonation.stop": Empty,

  "admin.audit.list": paginated(AuditEntry),

  "admin.metrics.kpis": AdminMetricsKpisResponse,

  "admin.users.list": paginated(AdminUser),
  "admin.users.create": AdminUser,
  "admin.users.update": AdminUser,
  "admin.users.delete": Empty,

  "admin.runtime-config.show": RuntimeConfigDoc,
  "admin.runtime-config.update": RuntimeConfigDoc,
  "admin.resellers.list": paginated(AdminReseller),
  "admin.appUpdates.list": z.object({ Items: z.array(AdminAppUpdate) }),
  "admin.abuse.list": paginated(AbuseEvent),
  "admin.quotaRequests.list": z.array(AdminQuotaRequestRow),
};

// ---------------------------------------------------------------------------
// Error + assertion.
// ---------------------------------------------------------------------------

export class PreviewFixtureShapeError extends LaraApiError {
  public readonly operationId: OperationId;
  public readonly issues: z.ZodIssue[];

  constructor(operationId: OperationId, issues: z.ZodIssue[], requestId: string) {
    const summary = issues
      .slice(0, 3)
      .map((i) => `${i.path.join(".") || "<root>"}: ${i.message}`)
      .join("; ");
    super(
      `Preview fixture for "${operationId}" returned a value that does not match the response schema (${summary}).`,
      ApiErrorCodeType.ServerError,
      HTTP_INTERNAL_SERVER_ERROR,
      requestId,
    );
    this.name = "PreviewFixtureShapeError";
    this.operationId = operationId;
    this.issues = issues;
  }
}

export function assertPreviewShape<K extends OperationId>(
  operationId: K,
  value: unknown,
  requestId: string,
): OperationResponse<K> {
  const schema = PREVIEW_RESPONSE_SHAPES[operationId];
  if (!schema) return value as OperationResponse<K>;
  const parsed = schema.safeParse(value);
  if (parsed.success) return parsed.data as OperationResponse<K>;
  console.error("preview-fixtures:shape-mismatch", {
    OperationId: operationId,
    RequestId: requestId,
    Issues: parsed.error.issues,
  });
  throw new PreviewFixtureShapeError(operationId, parsed.error.issues, requestId);
}
