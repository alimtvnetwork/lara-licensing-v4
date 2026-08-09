/**
 * Typed operations map (Plan 16 Step 30).
 *
 * Derived from the hand-written baseline in `./schema.d.ts` (Step 29).
 * Consumers MUST import types from this module, not from `schema.d.ts`
 * directly, so a future regenerated schema swap is a one-file change.
 *
 * Every operationId here maps to exactly one HTTP method + path pair on
 * the backend. `Method` and `Path` are string literals so the typed
 * `api-client.ts` (Step 31) can select the transport (preview vs live)
 * and the URL builder without a runtime table lookup.
 */

import type * as S from "./schema";

export type HttpMethod = "GET" | "POST" | "PATCH" | "PUT" | "DELETE";

export interface OperationDefinition<Req, Res> {
  Method: HttpMethod;
  Path: string;
  Request: Req;
  Response: Res;
}

// Helper: build an OperationDefinition value + type in one place.
function op<Req, Res>(Method: HttpMethod, Path: string): OperationDefinition<Req, Res> {
  return {
    Method,
    Path,
    Request: undefined as unknown as Req,
    Response: undefined as unknown as Res,
  };
}

// ---------------------------------------------------------------------------
// The registry.
// ---------------------------------------------------------------------------

export const Operations = {
  "auth.login": op<S.AuthLoginRequest, S.AuthLoginResponse>("POST", "/api/auth/login"),
  "auth.refresh": op<S.AuthRefreshRequest, S.AuthRefreshResponse>("POST", "/api/auth/refresh"),
  "auth.logout": op<S.EmptyRequest, S.EmptyResponse>("POST", "/api/auth/logout"),
  "auth.me": op<S.EmptyRequest, S.MeUser>("GET", "/api/auth/me"),
  "auth.capabilities": op<S.EmptyRequest, S.AuthCapabilitiesResponse>(
    "GET",
    "/api/Me/Capabilities",
  ),

  "password-reset.request": op<S.PasswordResetRequestRequest, S.EmptyResponse>(
    "POST",
    "/api/password-reset/request",
  ),
  "password-reset.confirm": op<S.PasswordResetConfirmRequest, S.EmptyResponse>(
    "POST",
    "/api/password-reset/confirm",
  ),

  "admin.licenses.list": op<S.AdminLicensesListRequest, S.AdminLicensesListResponse>(
    "GET",
    "/api/admin/licenses",
  ),
  "admin.licenses.show": op<S.AdminLicenseShowRequest, S.AdminLicenseShowResponse>(
    "GET",
    "/api/admin/licenses/:Id",
  ),
  "admin.licenses.create": op<S.AdminLicenseCreateRequest, S.AdminLicenseCreateResponse>(
    "POST",
    "/api/admin/licenses",
  ),
  "admin.licenses.update": op<S.AdminLicenseUpdateRequest, S.AdminLicenseUpdateResponse>(
    "PATCH",
    "/api/admin/licenses/:Id",
  ),
  "admin.licenses.delete": op<S.AdminLicenseDeleteRequest, S.AdminLicenseDeleteResponse>(
    "DELETE",
    "/api/admin/licenses/:Id",
  ),

  "admin.features.list": op<S.AdminFeaturesListRequest, S.AdminFeaturesListResponse>(
    "GET",
    "/api/admin/features",
  ),

  "portal.updates.manifest": op<S.PortalUpdatesRequest, S.PortalUpdatesResponse>(
    "GET",
    "/api/portal/updates",
  ),
  "portal.serials.lookup": op<S.PortalSerialLookupRequest, S.PortalSerialLookupResponse>(
    "GET",
    "/api/portal/serials/:Serial",
  ),

  "admin.quotas.list": op<S.AdminQuotasListRequest, S.AdminQuotasListResponse>(
    "GET",
    "/api/admin/quotas",
  ),
  "admin.quotas.update": op<S.AdminQuotaUpdateRequest, S.AdminQuotaUpdateResponse>(
    "PATCH",
    "/api/admin/quotas/:Id",
  ),

  "admin.impersonation.start": op<S.ImpersonationStartRequest, S.ImpersonationStartResponse>(
    "POST",
    "/api/admin/impersonation/start",
  ),
  "admin.impersonation.stop": op<S.ImpersonationStopRequest, S.ImpersonationStopResponse>(
    "POST",
    "/api/admin/impersonation/stop",
  ),

  "admin.audit.list": op<S.AdminAuditListRequest, S.AdminAuditListResponse>(
    "GET",
    "/api/admin/audit",
  ),

  "admin.metrics.kpis": op<S.AdminMetricsKpisRequest, S.AdminMetricsKpisResponse>(
    "GET",
    "/api/admin/metrics/kpis",
  ),

  "admin.users.list": op<S.AdminUsersListRequest, S.AdminUsersListResponse>(
    "GET",
    "/api/admin/users",
  ),
  "admin.users.create": op<S.AdminUserCreateRequest, S.AdminUserCreateResponse>(
    "POST",
    "/api/admin/users",
  ),
  "admin.users.update": op<S.AdminUserUpdateRequest, S.AdminUserUpdateResponse>(
    "PATCH",
    "/api/admin/users/:Id",
  ),
  "admin.sessions.delete": op<S.AdminSessionDeleteRequest, S.EmptyResponse>(
    "DELETE",
    "/api/admin/sessions/:SessionId",
  ),

  "admin.backup.exports.store": op<S.AdminBackupExportRequest, S.AdminBackupExportResponse>(
    "POST",
    "/api/admin/backup/exports",
  ),
  "admin.backup.imports.store": op<S.AdminBackupImportRequest, S.AdminBackupImportResponse>(
    "POST",
    "/api/admin/backup/imports",
  ),
  "admin.snapshots.list": op<S.AdminSnapshotsListRequest, S.AdminSnapshotsListResponse>(
    "GET",
    "/api/admin/snapshots",
  ),
  "admin.snapshots.store": op<S.AdminSnapshotCreateRequest, S.AdminSnapshotCreateResponse>(
    "POST",
    "/api/admin/snapshots",
  ),
  "admin.snapshots.show": op<S.AdminSnapshotShowRequest, S.AdminSnapshotShowResponse>(
    "GET",
    "/api/admin/snapshots/:SnapshotId",
  ),
  "admin.snapshots.pin": op<S.AdminSnapshotPinRequest, S.EmptyResponse>(
    "POST",
    "/api/admin/snapshots/:SnapshotId/pin",
  ),
  "admin.snapshots.unpin": op<S.AdminSnapshotUnpinRequest, S.EmptyResponse>(
    "POST",
    "/api/admin/snapshots/:SnapshotId/unpin",
  ),
  "admin.snapshots.yank": op<S.AdminSnapshotYankRequest, S.EmptyResponse>(
    "POST",
    "/api/admin/snapshots/:SnapshotId/yank",
  ),
  "admin.snapshots.delete": op<S.AdminSnapshotDeleteRequest, S.EmptyResponse>(
    "DELETE",
    "/api/admin/snapshots/:SnapshotId",
  ),

  "admin.roles.list": op<S.AdminRolesListRequest, S.AdminRolesListResponse>(
    "GET",
    "/api/admin/roles",
  ),
  "admin.capabilities.list": op<S.AdminCapabilitiesListRequest, S.AdminCapabilitiesListResponse>(
    "GET",
    "/api/admin/capabilities",
  ),
  "admin.policies.list": op<S.AdminPoliciesListRequest, S.AdminPoliciesListResponse>(
    "GET",
    "/api/admin/policies",
  ),
  "admin.policies.effective": op<S.AdminPoliciesEffectiveRequest, S.AdminPoliciesEffectiveResponse>(
    "GET",
    "/api/admin/policies/effective",
  ),
  "admin.policies.preview": op<S.AdminPoliciesPreviewRequest, S.AdminPoliciesPreviewResponse>(
    "POST",
    "/api/admin/policies/preview",
  ),
  "admin.policies.store": op<S.AdminPoliciesStoreRequest, S.AdminPoliciesStoreResponse>(
    "POST",
    "/api/admin/policies",
  ),

  "admin.backup.jobs.show": op<S.AdminBackupJobShowRequest, S.AdminBackupJobShowResponse>(
    "GET",
    "/api/admin/backup/jobs/:JobId",
  ),

  "admin.users.delete": op<S.AdminUserDeleteRequest, S.AdminUserDeleteResponse>(
    "DELETE",
    "/api/admin/users/:Id",
  ),

  "admin.runtime-config.show": op<
    S.AdminRuntimeConfigShowRequest,
    S.AdminRuntimeConfigShowResponse
  >("GET", "/api/admin/runtime-config"),
  "admin.runtime-config.update": op<
    S.AdminRuntimeConfigUpdateRequest,
    S.AdminRuntimeConfigUpdateResponse
  >("PUT", "/api/admin/runtime-config"),
  "admin.resellers.list": op<S.AdminResellersListRequest, S.AdminResellersListResponse>(
    "GET",
    "/api/admin/resellers",
  ),
  "admin.appUpdates.list": op<S.AdminAppUpdatesListRequest, S.AdminAppUpdatesListResponse>(
    "GET",
    "/api/admin/updates",
  ),
  "admin.abuse.list": op<S.AdminAbuseListRequest, S.AdminAbuseListResponse>(
    "GET",
    "/api/admin/abuse",
  ),
  "admin.quotaRequests.list": op<S.EmptyRequest, S.AdminQuotaRequestRow[]>(
    "GET",
    "/Api/Admin/QuotaRequests/All",
  ),
  "admin.errors.list": op<S.EmptyRequest, S.AdminErrorRow[]>("GET", "/Api/Admin/Errors"),
} as const;

export type OperationId = keyof typeof Operations;
export type OperationRequest<K extends OperationId> = (typeof Operations)[K]["Request"];
export type OperationResponse<K extends OperationId> = (typeof Operations)[K]["Response"];

export function getOperation<K extends OperationId>(id: K): (typeof Operations)[K] {
  return Operations[id];
}
