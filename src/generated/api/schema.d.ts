/**
 * Hand-written OpenAPI baseline (Plan 16 Step 29).
 *
 * Superseded automatically once `scripts/generate-api-types.mjs` (Step 25)
 * regenerates from `backend/build/openapi.json`. Until then, this file is
 * the authoritative typed surface for `src/lib/api-client.ts` and every
 * preview handler under `src/lib/preview-fixtures/`.
 *
 * Shape rules:
 *   - PascalCase keys everywhere (project Core rule).
 *   - Every success response is wrapped by `LaraEnvelope<T>` from
 *     `src/lib/lara-envelope.ts` at the transport layer, NOT redefined here.
 *   - `Response` types below describe the `Results[0]` shape or the raw
 *     domain payload; envelope wrapping is applied by the client.
 *   - Timestamps are RFC-3339 strings.
 *   - IDs are ULIDs unless otherwise noted.
 *
 * DO NOT hand-edit after CI drift gate goes green.
 */

// ---------------------------------------------------------------------------
// Shared primitives
// ---------------------------------------------------------------------------

export type Ulid = string;
export type IsoDateTime = string;

export interface Paginated<T> {
  Items: T[];
  Cursor: string | null;
  Total: number;
}

export interface EmptyRequest {}
export interface EmptyResponse {}

export interface IfMatchRequest {
  IfMatch: string;
}

// ---------------------------------------------------------------------------
// Auth (POST /api/auth/login, /refresh, /logout, GET /api/auth/me)
// ---------------------------------------------------------------------------

export interface AuthLoginRequest {
  Email: string;
  Password: string;
  DeviceName: string;
}

export interface AuthTokenPair {
  AccessToken: string;
  AccessTokenExpiresAt: IsoDateTime;
  RefreshToken: string;
  RefreshTokenExpiresAt: IsoDateTime;
}

export interface AuthLoginResponse extends AuthTokenPair {
  User: MeUser;
}

export interface AuthCapabilitiesResponse {
  Capabilities: string[];
}

export interface AuthRefreshRequest {
  RefreshToken: string;
}

export type AuthRefreshResponse = AuthTokenPair;

export interface MeUser {
  Id: Ulid;
  Email: string;
  DisplayName: string;
  Roles: string[];
  ResellerId: Ulid | null;
  CreatedAt: IsoDateTime;
  UpdatedAt: IsoDateTime;
}

// ---------------------------------------------------------------------------
// Password reset (POST /api/password-reset/request, /confirm)
// ---------------------------------------------------------------------------

export interface PasswordResetRequestRequest {
  Email: string;
}

export interface PasswordResetConfirmRequest {
  Token: string;
  NewPassword: string;
}

// ---------------------------------------------------------------------------
// Admin licenses (GET /api/admin/licenses, /:id, POST, PATCH, DELETE)
// ---------------------------------------------------------------------------

export type LicenseStatus = "active" | "suspended" | "revoked" | "expired";

export interface License {
  Id: Ulid;
  Serial: string;
  Status: LicenseStatus;
  CustomerName: string;
  CustomerEmail: string;
  ResellerId: Ulid | null;
  IssuedAt: IsoDateTime;
  ExpiresAt: IsoDateTime | null;
  Features: string[];
  MaxActivations: number;
  ActiveActivations: number;
  Version: number;
  CreatedAt: IsoDateTime;
  UpdatedAt: IsoDateTime;
}

export interface AdminLicensesListRequest {
  Cursor?: string | null;
  Query?: string;
  Status?: LicenseStatus;
  ResellerId?: Ulid;
}

export type AdminLicensesListResponse = Paginated<License>;

export interface AdminLicenseShowRequest {
  Id: Ulid;
}

export type AdminLicenseShowResponse = License;

export interface AdminLicenseCreateRequest {
  CustomerName: string;
  CustomerEmail: string;
  ResellerId: Ulid | null;
  Features: string[];
  MaxActivations: number;
  ExpiresAt: IsoDateTime | null;
}

export type AdminLicenseCreateResponse = License;

export interface AdminLicenseUpdateRequest extends IfMatchRequest {
  Id: Ulid;
  CustomerName?: string;
  CustomerEmail?: string;
  Features?: string[];
  MaxActivations?: number;
  ExpiresAt?: IsoDateTime | null;
  Status?: LicenseStatus;
}

export type AdminLicenseUpdateResponse = License;

export interface AdminLicenseDeleteRequest extends IfMatchRequest {
  Id: Ulid;
}

export type AdminLicenseDeleteResponse = EmptyResponse;

// ---------------------------------------------------------------------------
// Features catalog (GET /api/admin/features)
// ---------------------------------------------------------------------------

export interface FeatureDefinition {
  Code: string;
  DisplayName: string;
  Description: string;
  Category: string;
  IsBillable: boolean;
  CreatedAt: IsoDateTime;
  UpdatedAt: IsoDateTime;
}

export type AdminFeaturesListRequest = EmptyRequest;
export interface AdminFeaturesListResponse {
  Items: FeatureDefinition[];
}

// ---------------------------------------------------------------------------
// Portal updates (GET /api/portal/updates)
// ---------------------------------------------------------------------------

export interface UpdateManifestEntry {
  Version: string;
  ReleasedAt: IsoDateTime;
  DownloadUrl: string;
  Sha256: string;
  MinPreviousVersion: string;
  IsMandatory: boolean;
  Notes: string;
}

export interface PortalUpdatesRequest {
  CurrentVersion: string;
  Channel: "stable" | "beta";
}

export interface PortalUpdatesResponse {
  Latest: UpdateManifestEntry | null;
  Available: UpdateManifestEntry[];
}

// ---------------------------------------------------------------------------
// Portal serial lookup (GET /api/portal/serials/:serial)
// ---------------------------------------------------------------------------

export interface PortalSerialLookupRequest {
  Serial: string;
}

export interface PortalSerialLookupResponse {
  Serial: string;
  Status: LicenseStatus;
  ExpiresAt: IsoDateTime | null;
  Features: string[];
  IssuedAt: IsoDateTime;
}

// ---------------------------------------------------------------------------
// Admin quotas (GET /api/admin/quotas, PATCH /api/admin/quotas/:id)
// ---------------------------------------------------------------------------

export interface Quota {
  Id: Ulid;
  ResellerId: Ulid;
  ResellerName: string;
  FeatureCode: string;
  Allocated: number;
  Used: number;
  Restored: number;
  UpdatedAt: IsoDateTime;
  Version: number;
}

export interface AdminQuotasListRequest {
  Cursor?: string | null;
  ResellerId?: Ulid;
  Query?: string;
}

export type AdminQuotasListResponse = Paginated<Quota>;

export interface AdminQuotaUpdateRequest extends IfMatchRequest {
  Id: Ulid;
  Allocated: number;
}

export type AdminQuotaUpdateResponse = Quota;

// ---------------------------------------------------------------------------
// Impersonation (POST /api/admin/impersonation/start, /stop)
// ---------------------------------------------------------------------------

export interface ImpersonationStartRequest {
  TargetUserId: Ulid;
  Reason: string;
}

export interface ImpersonationSession {
  SessionId: Ulid;
  StartedAt: IsoDateTime;
  ExpiresAt: IsoDateTime;
  TargetUser: MeUser;
  ActorUser: MeUser;
}

export type ImpersonationStartResponse = ImpersonationSession;

export type ImpersonationStopRequest = EmptyRequest;
export type ImpersonationStopResponse = EmptyResponse;

// ---------------------------------------------------------------------------
// Backup / Restore
// ---------------------------------------------------------------------------

export interface AdminBackupExportRequest {
  Scope: {
    Schema: boolean;
    ClosedSets: boolean;
    Features: boolean;
    Licenses: boolean;
    Rbac: boolean;
    Domain: string[];
    SecretsEnvelope: boolean;
    Files: boolean;
  };
  Encryption?: {
    Epoch: number | null;
  };
  Note?: string;
}

export interface AdminBackupExportResponse {
  JobId: Ulid;
  ArchiveId: Ulid;
  State: string;
  CreatedAt: IsoDateTime;
}

export interface AdminBackupImportRequest {
  ArchiveId: Ulid;
  Scope?: {
    Schema?: boolean;
    ClosedSets?: boolean;
    Features?: boolean;
    Licenses?: boolean;
    Rbac?: boolean;
    Domain?: string[];
    SecretsEnvelope?: boolean;
    Files?: boolean;
  };
  KeyMaterial?: string; // Optional decryption key
}

export interface AdminBackupImportResponse {
  JobId: Ulid;
  State: string;
  CreatedAt: IsoDateTime;
}

export interface AdminSnapshotCreateRequest {
  Scope: {
    Schema: boolean;
    ClosedSets: boolean;
    Features: boolean;
    Licenses: boolean;
    Rbac: boolean;
    Domain: string[];
    SecretsEnvelope: boolean;
    Files: boolean;
  };
  Retention: {
    Policy: "keepDays" | "keepCount" | "keepUntilExplicitDelete";
    KeepDays?: number | null;
    KeepCount?: number | null;
  };
  Label: string;
  Note?: string;
}

export interface AdminSnapshotCreateResponse {
  JobId: Ulid;
  SnapshotId: Ulid;
  State: string;
  Label: string;
  Retention: { Policy: string; KeepDays?: number | null; KeepCount?: number | null };
  CreatedAt: IsoDateTime;
}

export interface AdminSnapshot {
  Id: Ulid;
  State: string;
  Label: string;
  Retention: { Policy: string; KeepDays?: number | null; KeepCount?: number | null };
  CreatedAt: IsoDateTime;
  DeletedAt?: IsoDateTime | null;
}

export interface AdminSnapshotsListRequest {}

export interface AdminSnapshotsListResponse extends Paginated<AdminSnapshot> {}

export interface AdminSnapshotShowRequest {
  SnapshotId: Ulid;
}

export interface AdminSnapshotShowResponse extends AdminSnapshot {}

export interface AdminSnapshotPinRequest {
  SnapshotId: Ulid;
}
export interface AdminSnapshotUnpinRequest {
  SnapshotId: Ulid;
}
export interface AdminSnapshotYankRequest {
  SnapshotId: Ulid;
}
export interface AdminSnapshotDeleteRequest {
  SnapshotId: Ulid;
}

export interface AdminRolesListRequest {}
export interface AdminRolesListResponse {
  Roles: string[];
}

export interface AdminCapabilitiesListRequest {}
export interface AdminCapabilitiesListResponse {
  Capabilities: string[];
}

export interface AdminPolicyRow {
  Role: string;
  Capability: string;
  Effect: "allow" | "deny" | "unset";
  CitedRule?: string;
}

export interface AdminPoliciesListRequest {
  Version?: string;
}
export interface AdminPoliciesListResponse {
  PolicyVersion: number;
  Rows: AdminPolicyRow[];
}

export interface AdminPoliciesEffectiveRequest {
  UserId: Ulid;
}
export interface AdminPoliciesEffectiveResponse {
  UserId: Ulid;
  ResolvedAt: IsoDateTime;
  PolicyVersion: number;
  Decisions: {
    Capability: string;
    Effect: string;
    Reason: string;
    CitedRule: string;
    MatchedRoles: string[];
  }[];
}

export interface AdminPoliciesPreviewRequest {
  BasedOn: number;
  Edits: AdminPolicyRow[];
}
export interface AdminPoliciesPreviewResponse {
  Findings: {
    Code: string;
    Severity: "block" | "warn" | "info";
    Message?: string;
  }[];
}

export interface AdminPoliciesStoreRequest {
  BasedOn: number;
  Edits: AdminPolicyRow[];
}
export interface AdminPoliciesStoreResponse {
  PolicyVersion: number;
}

export interface AdminBackupJobShowRequest {
  JobId: Ulid;
}

export interface AdminBackupJobShowResponse {
  Id: Ulid;
  Kind: string;
  State: string;
  ArchiveId?: Ulid | null;
  Sequence: number;
  ErrorCode?: string | null;
  ErrorMessage?: string | null;
  Result?: {
    DownloadUrl?: string;
    ExpiresAt?: IsoDateTime;
    SizeBytes?: number;
    Sha256?: string;
  };
  CreatedAt: IsoDateTime;
}

// ---------------------------------------------------------------------------
// Audit log (GET /api/admin/audit)
// ---------------------------------------------------------------------------

export interface AuditEntry {
  Id: Ulid;
  EventType: string;
  ActorUserId: Ulid | null;
  TargetType: string;
  TargetId: string;
  RequestId: string;
  OccurredAt: IsoDateTime;
  Payload: Record<string, unknown>;
}

export interface AdminAuditListRequest {
  Cursor?: string | null;
  EventType?: string;
  ActorUserId?: Ulid;
  Since?: IsoDateTime;
  Until?: IsoDateTime;
}

export type AdminAuditListResponse = Paginated<AuditEntry>;

// ---------------------------------------------------------------------------
// Admin metrics (GET /api/admin/metrics/kpis)
// ---------------------------------------------------------------------------

export interface KpiTile {
  Key: string;
  Label: string;
  Value: number;
  Unit: "count" | "percent" | "bytes" | "seconds";
  Delta: number | null;
  Trend: "up" | "down" | "flat";
}

export interface AdminMetricsKpisRequest {
  Since?: IsoDateTime;
  Until?: IsoDateTime;
}
export interface AdminMetricsKpisResponse {
  Tiles: KpiTile[];
  GeneratedAt: IsoDateTime;
}

// ---------------------------------------------------------------------------
// Admin users (CRUD)
// ---------------------------------------------------------------------------

export interface AdminUser extends MeUser {
  IsActive: boolean;
  LastLoginAt: IsoDateTime | null;
  Version: number;
}

export interface AdminUsersListRequest {
  Cursor?: string | null;
  Query?: string;
  Role?: string;
}

export type AdminUsersListResponse = Paginated<AdminUser>;

export interface AdminUserCreateRequest {
  Email: string;
  DisplayName: string;
  Roles: string[];
  ResellerId: Ulid | null;
  InitialPassword: string;
}

export type AdminUserCreateResponse = AdminUser;

export interface AdminUserUpdateRequest extends IfMatchRequest {
  Id: Ulid;
  DisplayName?: string;
  Roles?: string[];
  IsActive?: boolean;
  ResellerId?: Ulid | null;
}

export type AdminUserUpdateResponse = AdminUser;

export interface AdminUserDeleteRequest extends IfMatchRequest {
  Id: Ulid;
}

export type AdminUserDeleteResponse = EmptyResponse;

// ---------------------------------------------------------------------------
// Runtime config (Plan 16 Step 58: PUT /api/admin/runtime-config)
// ---------------------------------------------------------------------------

export interface RuntimeConfigDoc {
  Mode: "preview" | "dev" | "production";
  ApiBaseUrl: string | null;
  PreviewSeed: string;
  AllowRuntimeToggle: boolean;
  Version: string;
  UpdatedAt: IsoDateTime;
}

export type AdminRuntimeConfigShowRequest = EmptyRequest;
export type AdminRuntimeConfigShowResponse = RuntimeConfigDoc;

export interface AdminRuntimeConfigUpdateRequest extends IfMatchRequest {
  Mode: "preview" | "dev" | "production";
  ApiBaseUrl: string | null;
  PreviewSeed: string;
  AllowRuntimeToggle: boolean;
}
export type AdminRuntimeConfigUpdateResponse = RuntimeConfigDoc;

// ---------------------------------------------------------------------------
// Admin resellers (Plan 18 Phase D: GET /api/admin/resellers)
// ---------------------------------------------------------------------------

export interface AdminReseller {
  Id: Ulid;
  Name: string;
  Slug: string;
  IsActive: boolean;
  CreatedAt: IsoDateTime;
  UpdatedAt: IsoDateTime;
}

export interface AdminResellersListRequest {
  Cursor?: string | null;
  Query?: string;
}

export type AdminResellersListResponse = Paginated<AdminReseller>;

// ---------------------------------------------------------------------------
// Admin app updates (Plan 18 Phase D: GET /api/admin/updates)
// ---------------------------------------------------------------------------

export interface AdminAppUpdate {
  Version: string;
  ReleasedAt: IsoDateTime;
  InstalledAt: IsoDateTime | null;
  Status: "installed" | "available" | "pending";
}

export interface AdminAppUpdatesListRequest {
  Query?: string;
}
export interface AdminAppUpdatesListResponse {
  Items: AdminAppUpdate[];
}

// ---------------------------------------------------------------------------
// Admin abuse (Plan 18 Phase D: GET /api/admin/abuse)
// ---------------------------------------------------------------------------

export type AbuseEventType = "AbuseBlocked" | "RateLimited";

export interface AbuseEvent {
  Id: Ulid;
  EventType: AbuseEventType;
  IpAddress: string;
  Target: string;
  OccurredAt: IsoDateTime;
  Metadata: Record<string, unknown>;
}

export interface AdminAbuseListRequest {
  Cursor?: string | null;
  Query?: string;
}

export type AdminAbuseListResponse = Paginated<AbuseEvent>;

// ---------------------------------------------------------------------------
// Admin quota-requests (Plan 18 Phase D: GET /api/admin/quota-requests/all)
// ---------------------------------------------------------------------------

export interface AdminQuotaRequestRow {
  QuotaRequestId: number;
  ResellerId: number;
  ResellerSlug: string;
  LicenseCategoryId: number;
  LicenseTierId: number;
  RequestedDelta: number;
  ApprovedDelta: number | null;
  Status: "Pending" | "Approved" | "Denied" | "Cancelled";
  SubmittedByUserId: number;
  SubmittedAt: IsoDateTime;
  DecidedByUserId: number | null;
  DecidedAt: IsoDateTime | null;
  DenialReason: string | null;
  Justification: string | null;
}

export interface AdminErrorRow {
  RequestedAt: string;
  HttpStatus: number | string;
  Category: string;
  ErrorCode: string;
  RequestId: string;
  ErrorId: string;
}

export interface AdminSessionDeleteRequest {
  SessionId?: string;
}
