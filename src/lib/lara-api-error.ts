export enum ApiErrorCodeType {
  ValidationFailed = "ValidationFailed",
  InvalidJson = "InvalidJson",
  UnsupportedMediaType = "UnsupportedMediaType",
  MethodNotAllowed = "MethodNotAllowed",
  AuthUnauthorized = "AuthUnauthorized",
  AuthSessionNotFound = "AuthSessionNotFound",
  AuthSessionAlreadyClosed = "AuthSessionAlreadyClosed",
  AuthTokenExpired = "AuthTokenExpired",
  AuthInvalidCredentials = "AuthInvalidCredentials",
  AuthRefreshReused = "AuthRefreshReused",
  PolicyVersionMismatch = "Policy.VersionMismatch",
  SnapshotPinned = "Snapshot.Pinned",
  /**
   * Plan 09 login modernization. 428, retryable after the client fetches a
   * fresh challenge from GET /Api/Auth/Captcha and re-submits with
   * CaptchaChallengeId + CaptchaAnswer. Emitted when consecutive login
   * failures for the (Email + IP) pair reach the configured threshold.
   */
  LoginCaptchaRequired = "LoginCaptchaRequired",
  /**
   * Plan 09 login modernization. 401, retryable. HMAC signature, expiry, or
   * answer check failed. Client should request a fresh challenge.
   */
  LoginCaptchaInvalid = "LoginCaptchaInvalid",
  /**
   * 409, retryable. Two concurrent refreshes on the same session lost the
   * transactional rotation lock. Caller MUST re-read the newly rotated token
   * from local storage before retry. This is NOT a family-reuse event and
   * MUST NOT invalidate the session. See spec/21-app/12-error-taxonomy.md
   * line 67 and spec/21-app/31-auth-session-family.md §Rotation.
   */
  AuthRefreshRaceLost = "AuthRefreshRaceLost",
  /**
   * 500, internal (salt-rotation job). Surfaced only in health checks and
   * audit; never in a caller-facing response. Reserved here so the code
   * cannot be reused. See spec/21-app/12-error-taxonomy.md line 68 and
   * spec/21-app/32-auth-session-retention.md.
   */
  AuthSaltRotationFailed = "AuthSaltRotationFailed",
  AuthForbidden = "AuthForbidden",
  /**
   * v0.300.0. 403, terminal. Emitted by POST /Api/Auth/Register once at
   * least one Root Users row exists: the SuperAdmin bootstrap window is
   * closed and subsequent user provisioning MUST flow through the
   * authenticated Admin surface.
   */
  AuthRegistrationClosed = "AuthRegistrationClosed",
  OAuthInvalidRequest = "OAuthInvalidRequest",
  OAuthInvalidClient = "OAuthInvalidClient",
  OAuthInvalidGrant = "OAuthInvalidGrant",
  OAuthUnsupportedGrantType = "OAuthUnsupportedGrantType",
  LicenseNotFound = "LicenseNotFound",
  LicenseExpired = "LicenseExpired",
  LicenseConflict = "LicenseConflict",
  LicenseMachineLimit = "LicenseMachineLimit",
  LicenseUserLimit = "LicenseUserLimit",
  SerialNotFound = "SerialNotFound",
  SerialInvalid = "SerialInvalid",
  SerialRevoked = "SerialRevoked",
  VerifyHashInvalid = "VerifyHashInvalid",
  VerifyKeyConsumed = "VerifyKeyConsumed",
  VerifyKeyExpired = "VerifyKeyExpired",
  VerifyKeyMismatch = "VerifyKeyMismatch",
  /**
   * 409, non-retryable at the same environment. Caller's `EnvironmentId`
   * differs from the license row's `EnvironmentId`. `Details[0].Value` is
   * the literal token pair `"<Requested>/<Licensed>"`, never the real
   * environment names, so it cannot be used to enumerate a license's
   * environment. See spec/21-app/12-error-taxonomy.md line 123 and
   * spec/21-app/44-environments.md §3 (AC-LENV-004).
   */
  EnvironmentMismatch = "EnvironmentMismatch",
  /**
   * 409, non-retryable. Reseller path only. The tier-scoped
   * `ResellerQuotas` row is depleted for `(ResellerId, LicenseCategoryId,
   * LicenseTierId, Period)`. Response carries `RetryAfterSeconds = 0`
   * because the caller must escalate via `POST /QuotaRequests`
   * (spec/21-app/42-quota-requests.md), never poll. Never leaks
   * `LicensesGranted` or `LicensesConsumed`. See
   * spec/21-app/12-error-taxonomy.md line 120 (AC-ERR-006).
   */
  QuotaExhausted = "QuotaExhausted",
  /**
   * 403, non-retryable. Reseller path only. Distinct from
   * `QuotaExhausted`: the `(LicenseCategoryId, LicenseTierId)` pair has no
   * `ResellerQuotas` row provisioned for this `ResellerId`. See
   * spec/21-app/12-error-taxonomy.md line 121 (AC-ERR-007).
   */
  QuotaCategoryUnauthorized = "QuotaCategoryUnauthorized",
  /**
   * 400. Admin feature write only. `FeatureKey` absent from the closed
   * catalog in spec/21-app/45-license-features.md §2. Forbidden synonyms
   * from §2 hit this code, not `ValidationFailed`. See
   * spec/21-app/12-error-taxonomy.md line 124 (AC-ERR-010).
   */
  FeatureUnknown = "FeatureUnknown",
  /**
   * 400. Admin feature write only. `Value` shape does not match the
   * declared `Features.ValueType` (or a closed-enum member set). The
   * `Details[0].Value` slot names the `FeatureKey`, never the offending
   * payload. See spec/21-app/12-error-taxonomy.md line 125 (AC-ERR-011).
   */
  FeatureValueInvalid = "FeatureValueInvalid",
  PrefixNotFound = "PrefixNotFound",
  PrefixConflict = "PrefixConflict",
  PrefixInUse = "PrefixInUse",
  PrefixForbidden = "PrefixForbidden",
  ResellerNotFound = "ResellerNotFound",
  ResellerConflict = "ResellerConflict",
  ResellerInUse = "ResellerInUse",
  UserNotFound = "UserNotFound",
  UserConflict = "UserConflict",
  RoleAssignmentNotFound = "RoleAssignmentNotFound",
  AuthzRoleDenied = "AuthzRoleDenied",
  AuthzRowScopeDenied = "AuthzRowScopeDenied",
  AuthzLastAdminProtected = "AuthzLastAdminProtected",
  ResourceRoleNotAssigned = "ResourceRoleNotAssigned",
  ResourceRoleAlreadyAssigned = "ResourceRoleAlreadyAssigned",
  ValidationInvalidRole = "ValidationInvalidRole",
  UpdateVersionDowngradeBlocked = "UpdateVersionDowngradeBlocked",
  UpdateChannelForbidden = "UpdateChannelForbidden",
  UpdateChannelUnknown = "UpdateChannelUnknown",
  UpdateManifestUnavailable = "UpdateManifestUnavailable",
  UpdateAssetNotFound = "UpdateAssetNotFound",
  UpdateAssetUploadFailed = "UpdateAssetUploadFailed",
  UpdateAssetVerificationFailed = "UpdateAssetVerificationFailed",
  /**
   * spec/21-app/17-self-update-endpoint.md MUST-abort row A4: asset HTTP
   * status != 200 after redirects. Carries {HttpStatus, ErrorCode} details.
   */
  UpdateDownloadFailed = "UpdateDownloadFailed",
  ValidationInvalidVersion = "ValidationInvalidVersion",
  RequestIdMissing = "RequestIdMissing",
  IdempotencyConflict = "IdempotencyConflict",
  IdempotencyKeyRequired = "IdempotencyKeyRequired",
  RateLimited = "RateLimited",
  AbuseBlocked = "AbuseBlocked",
  ServerError = "ServerError",
  ServiceUnavailable = "ServiceUnavailable",
  UnknownServerError = "UnknownServerError",
  /**
   * 428, non-retryable without a fresh `GET`. In-scope route received a
   * mutating request without an `If-Match` header. See
   * spec/21-app/11-api-contracts/09-concurrency-control.md §Scope and
   * spec/21-app/12-error-taxonomy.md line 126 (AC-CONCUR-002).
   */
  PreconditionRequired = "PreconditionRequired",
  /**
   * 412, non-retryable with the stale ETag. Caller's `If-Match` did not
   * match the server ETag; `Details[0].Value` carries the current ETag
   * verbatim so callers can refresh without a follow-up `GET`. See
   * spec/21-app/12-error-taxonomy.md line 127 (AC-CONCUR-003).
   */
  PreconditionFailed = "PreconditionFailed",
  // Plan 09 step 92 backfill. Backend codes present in
  // `backend/config/lara.php` but previously missing from this enum,
  // caught by `linter-scripts/check-copy-coverage.py` before any user
  // ever saw a raw enum name leak into a toast.
  AuthzPermissionDenied = "AuthzPermissionDenied",
  FeatureCatalogUnseeded = "FeatureCatalogUnseeded",
  FeatureNotAvailable = "FeatureNotAvailable",
  ImpersonationAlreadyActive = "ImpersonationAlreadyActive",
  ImpersonationParentSessionInvalid = "ImpersonationParentSessionInvalid",
  LicenseRevoked = "LicenseRevoked",
  MachineRebindCooldownActive = "MachineRebindCooldownActive",
  PasswordResetTokenInvalid = "PasswordResetTokenInvalid",
  QuotaLedgerConflict = "QuotaLedgerConflict",
  UpdateChecksumMismatch = "UpdateChecksumMismatch",
  UpdateSignatureUnavailable = "UpdateSignatureUnavailable",
  ValidationConflict = "ValidationConflict",
  ValidationInputInvalid = "ValidationInputInvalid",
  // Plan 16 Step 59b. Runtime-config admin surface (spec/28-runtime-modes/
  // 05-admin-runtime-toggle.md). Registered in backend/config/lara.php lines
  // 240-245, 349-354. Preview handler at src/lib/preview-fixtures/
  // runtime-config.ts must emit these exact codes so INV-RM-06 (preview
  // mirrors BE closed-set) holds.
  RuntimeConfigConflict = "RuntimeConfigConflict",
  RuntimeConfigForbidden = "RuntimeConfigForbidden",
  RuntimeConfigInvalidField = "RuntimeConfigInvalidField",
  RuntimeConfigLocked = "RuntimeConfigLocked",
  RuntimeConfigModeMismatch = "RuntimeConfigModeMismatch",
  RuntimeConfigWriteFailed = "RuntimeConfigWriteFailed",
  // Plan 14 Backup/Restore codes. Registered in backend/config/lara.php
  // under 'error_codes' (BackupCorrupt..BrOpsQueryFailed). Mirrored here
  // so the closed-set parity gate in scripts/check-error-code-parity.mjs
  // stays green. See spec/26-backup-restore/ and spec/21-app/12-error-taxonomy.md.
  BackupCorrupt = "BackupCorrupt",
  BackupExportProductionPending = "BackupExportProductionPending",
  BackupKeyEpochRetired = "BackupKeyEpochRetired",
  BackupStorageFailure = "BackupStorageFailure",
  BackupVersionMismatch = "BackupVersionMismatch",
  BackupWorkerFailure = "BackupWorkerFailure",
  BackupWorkerTransitionFailed = "BackupWorkerTransitionFailed",
  BackupZstdUnavailable = "BackupZstdUnavailable",
  BrBackfillFailed = "BrBackfillFailed",
  BrOpsNotYetImplemented = "BrOpsNotYetImplemented",
  BrOpsQueryFailed = "BrOpsQueryFailed",
}

import { copyForErrorCode } from "./error-copy";

export interface RateLimitMetadata {
  retryAfterSeconds?: number;
  bucket?: string;
  limit?: number;
  windowSeconds?: number;
  resetAt?: number;
}

export type LaraErrorCategory =
  | "Auth"
  | "Validation"
  | "RateLimit"
  | "DomainConflict"
  | "NotFound"
  | "Internal";

/**
 * `errorId` (RFC 4122 v4) is populated only for 5xx envelopes per
 * `backend/bootstrap/app.php` line 90 (AC-ERR-003); it correlates the
 * caller-facing envelope with the `lara-diag` server log line.
 * `details` mirrors `Attributes.Error.Details` verbatim (typically
 * field-level validation entries `{Field, Value?}`) and is preserved
 * end-to-end so callers can render per-field messages without a second
 * parse pass. See spec/03-error-manage/ and Plan 11 step 24.
 */
export class LaraApiError extends Error {
  /**
   * Failing operation id (e.g. `admin.licenses.show`). Populated by
   * `useApi` / `useApiMutation` at the call site so `RouteErrorState`
   * and support tooling can render "which call broke". Plan 17 Step 40.
   * Optional and mutable-after-construct: transport throw sites do not
   * know the caller's operation, so hooks tag it on catch.
   */
  operationId?: string;
  constructor(
    message: string,
    readonly errorCode: ApiErrorCodeType,
    readonly httpStatus: number,
    readonly requestId?: string,
    readonly rateLimit?: RateLimitMetadata,
    readonly errorId?: string,
    readonly details?: ReadonlyArray<unknown>,
    readonly category?: LaraErrorCategory,
  ) {
    super(message);
    this.name = "LaraApiError";
  }
}

/**
 * Canonical Lara error -> display string.
 * Contract: every LaraApiError surface MUST render `errorCode: message (Request <id>)`
 * so that `X-Request-Id` propagates to the UI per spec/21-app/20-observability.md.
 * For RateLimited errors we append a `Retry in Ns` hint from the `Retry-After`
 * header captured in RateLimitMetadata, so operators can see when the bucket
 * releases without inspecting DevTools per spec/21-app/14-rate-limiting.md.
 * Never drop `requestId`; support tickets depend on it.
 */
export function formatLaraApiError(error: unknown): string {
  if (error instanceof LaraApiError) {
    const retryAfter = getRetryAfterSeconds(error);
    const friendly = copyForErrorCode(error.errorCode, { retryAfterSeconds: retryAfter });
    const body = friendly ?? error.message;
    // For RateLimited, the friendly copy already interpolates the seconds,
    // so don't append the standard `Retry in Ns` hint twice.
    const retry = formatRetryAfter(retryAfter);
    const skipRetrySuffix =
      retry === null ||
      (friendly !== undefined && error.errorCode === ApiErrorCodeType.RateLimited);
    const retrySuffix = skipRetrySuffix ? "" : `. ${retry}`;
    const suffix = typeof error.requestId === "string" ? ` (Request ${error.requestId})` : "";

    return `${body}${retrySuffix}${suffix}`;
  }
  if (error instanceof Error) return error.message;

  return "Unknown error";
}

export function formatLaraApiErrorOptional(error: unknown): string | undefined {
  if (error === null || error === undefined) return undefined;

  return formatLaraApiError(error);
}

/**
 * Returns Retry-After seconds when the error is a RateLimited LaraApiError and
 * the header parsed as a finite non-negative number, else undefined. The 429
 * envelope from spec/21-app/14-rate-limiting.md guarantees the header, but we
 * do not fabricate a value when the server omitted it.
 */
export function getRetryAfterSeconds(error: unknown): number | undefined {
  if (!(error instanceof LaraApiError)) return undefined;
  // AC-RL-008 (spec/21-app/14-rate-limiting.md): Retry-After is authoritative
  // ONLY for RateLimited. AbuseBlocked (403) and MachineRebindCooldownActive
  // (409) render a banner but MUST NOT drive a countdown, which is why the
  // submit-lock hook keeps those codes unlocked even when the banner is up.
  if (error.errorCode !== ApiErrorCodeType.RateLimited) return undefined;
  const seconds = error.rateLimit?.retryAfterSeconds;
  if (typeof seconds !== "number" || !Number.isFinite(seconds) || seconds < 0) return undefined;

  return Math.ceil(seconds);
}

export function formatRetryAfter(seconds: number | undefined): string | null {
  if (seconds === undefined) return null;
  if (seconds <= 0) return "Retry now.";
  if (seconds < 60) return `Retry in ${seconds}s.`;
  const minutes = Math.ceil(seconds / 60);

  return `Retry in ${minutes}m.`;
}
