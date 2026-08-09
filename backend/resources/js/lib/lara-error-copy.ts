/**
 * Plan 06 step 77. Spec 12 error-code copy dictionary for the Laravel Inertia
 * console. The SPA equivalent lives in `src/lib/error-copy.ts`; that module
 * cannot be imported here because the Inertia bundle has a different Vite root
 * (`backend/`) and `@/` resolves to `backend/resources/js`. Key parity between
 * the two tables is enforced by `tests/laravel-error-copy.test.ts`.
 */
export const laraErrorCopy: Readonly<Record<string, string>> = {
  ValidationFailed: "Some fields need attention.",
  InvalidJson: "The request body was not valid JSON.",
  UnsupportedMediaType: "This request format is not supported.",
  MethodNotAllowed: "That action is not available on this resource.",
  AuthUnauthorized: "Your session expired. Sign in again to continue.",
  AuthSessionNotFound: "Your session is no longer active. Sign in again.",
  AuthSessionAlreadyClosed: "This session has already ended.",
  AuthTokenExpired: "Your session token expired. Sign in again.",
  AuthInvalidCredentials: "Sign in failed. Check your email and password.",
  AuthRefreshReused: "Sign in again for security. Your prior session was invalidated.",
  LoginCaptchaRequired: "Please solve the challenge to continue signing in.",
  LoginCaptchaInvalid: "Challenge answer was incorrect. A new one has been loaded.",
  AuthRefreshRaceLost: "Two tabs refreshed at once. Try again.",
  AuthSaltRotationFailed: "A background security job failed. An operator has been notified.",
  AuthForbidden: "You do not have access to this action.",
  AuthRegistrationClosed: "Registration is closed for this workspace. Ask a SuperAdmin to create your account.",
  OAuthInvalidRequest: "The sign-in request was malformed. Start over from the sign-in page.",
  OAuthInvalidClient: "This OAuth client is not recognised.",
  OAuthInvalidGrant: "This authorization has expired or already been used.",
  OAuthUnsupportedGrantType: "This sign-in method is not supported.",
  LicenseNotFound: "License not found.",
  LicenseExpired: "This license has expired.",
  LicenseConflict: "This license was modified elsewhere. Refresh and try again.",
  LicenseMachineLimit: "Machine limit reached for this license.",
  LicenseUserLimit: "User limit reached for this license.",
  SerialNotFound: "Serial not found.",
  SerialInvalid: "This serial is not valid for this workspace.",
  SerialRevoked: "This serial has been revoked.",
  VerifyHashInvalid: "Verification failed. Contact support.",
  VerifyKeyConsumed: "This verification key has already been used.",
  VerifyKeyExpired: "The verification key has expired.",
  VerifyKeyMismatch: "Verification key does not match.",
  EnvironmentMismatch: "This action is not allowed in the current environment.",
  QuotaExhausted: "Quota exhausted. Request more quota before issuing another license.",
  QuotaCategoryUnauthorized: "Your reseller has no quota allocated for this category and tier.",
  FeatureUnknown: "That feature key is not in the catalog.",
  FeatureValueInvalid: "Feature value does not match the declared type.",
  PrefixNotFound: "Prefix not found.",
  PrefixConflict: "A prefix with that value already exists.",
  PrefixInUse: "This prefix is in use and cannot be removed.",
  PrefixForbidden: "You are not allowed to use this prefix.",
  ResellerNotFound: "Reseller not found.",
  ResellerConflict: "A reseller with that slug already exists.",
  ResellerInUse: "This reseller has live licenses and cannot be removed.",
  UserNotFound: "User not found.",
  UserConflict: "A user with that email already exists.",
  RoleAssignmentNotFound: "That role assignment no longer exists.",
  AuthzRoleDenied: "Your role does not permit this action.",
  AuthzRowScopeDenied: "You cannot act on records outside your scope.",
  AuthzLastAdminProtected: "You cannot remove the last SuperAdmin.",
  ResourceRoleNotAssigned: "That role is not assigned to this user.",
  ResourceRoleAlreadyAssigned: "That role is already assigned to this user.",
  ValidationInvalidRole: "That role value is not recognised.",
  UpdateVersionDowngradeBlocked: "Cannot publish a version lower than the current one.",
  UpdateChannelForbidden: "You cannot publish to that release channel.",
  UpdateChannelUnknown: "That release channel is not recognised.",
  UpdateManifestUnavailable: "The update manifest is temporarily unavailable.",
  UpdateAssetNotFound: "That update asset was not found.",
  UpdateAssetUploadFailed: "Uploading the update asset failed. Try again.",
  UpdateAssetVerificationFailed: "Update asset failed signature verification.",
  UpdateDownloadFailed: "Downloading the update failed. Check the network and retry.",
  ValidationInvalidVersion: "That version string is not valid.",
  RequestIdMissing: "The request is missing its correlation id.",
  IdempotencyConflict: "This action was already processed with a different payload.",
  IdempotencyKeyRequired: "This action needs an idempotency key. Refresh and try again.",
  RateLimited: "Too many requests. Wait {RetryAfterSec} seconds and try again.",
  AbuseBlocked: "Blocked by abuse protection. Contact support if this is unexpected.",
  ServerError: "Something failed on our side. Try again in a moment.",
  ServiceUnavailable: "The service is temporarily unavailable. Try again shortly.",
  UnknownServerError: "An unexpected error occurred. Try again in a moment.",
  PreconditionRequired: "This action needs a fresh read. Refresh and try again.",
  PreconditionFailed: "Someone edited this record while you had it open. Refresh and try again.",
  "Policy.VersionMismatch": "The policy version provided does not match the server state.",
  "Snapshot.Pinned": "This snapshot is pinned and cannot be deleted or pruned.",
  // Plan 09 step 92 backfill: user copy for backend codes previously missing.
  AuthzPermissionDenied: "You do not have permission for this action.",
  FeatureCatalogUnseeded: "This workspace is not fully configured yet. An operator has been notified.",
  FeatureNotAvailable: "This feature is not available on your license tier.",
  ImpersonationAlreadyActive: "An impersonation session is already active. End it before starting a new one.",
  ImpersonationParentSessionInvalid: "Your admin session ended. Sign in again to resume impersonation.",
  LicenseRevoked: "This license has been revoked and cannot be used.",
  MachineRebindCooldownActive: "This machine was rebound recently. Try again after the cooldown.",
  PasswordResetTokenInvalid: "This password reset link is invalid or has expired. Request a new one.",
  QuotaLedgerConflict: "The quota ledger changed while your request was in flight. Refresh and try again.",
  UpdateChecksumMismatch: "The update package failed integrity verification. The publisher has been notified.",
  UpdateSignatureUnavailable: "The update signature is not yet available. Try again in a moment.",
  ValidationConflict: "This change conflicts with the current record state.",
  ValidationInputInvalid: "One or more fields have invalid values.",
  // Plan 16 Step 59b. Runtime-config admin surface copy.
  RuntimeConfigConflict: "Runtime settings changed since you loaded them. Refresh and try again.",
  RuntimeConfigForbidden: "You do not have permission to change runtime settings.",
  RuntimeConfigInvalidField: "One or more runtime settings are invalid.",
  RuntimeConfigLocked: "Runtime settings are locked. Re-enable via the deploy pipeline.",
  RuntimeConfigModeMismatch: "Runtime mode and its required fields do not match. Check ApiBaseUrl and PreviewSeed.",
  RuntimeConfigWriteFailed: "Could not save runtime settings. Try again in a moment.",
  // Plan 14 Backup/Restore. See spec/26-backup-restore/ for the operator
  // narrative behind each state; copy is intentionally terse for toasts.
  BackupCorrupt: "This backup archive is corrupt and cannot be restored.",
  BackupExportProductionPending: "A production backup export is already in progress. Try again shortly.",
  BackupKeyEpochRetired: "The encryption key for this backup has been retired. Restore is no longer available.",
  BackupStorageFailure: "Backup storage is unavailable. Try again in a moment.",
  BackupVersionMismatch: "This backup was created by an incompatible version.",
  BackupWorkerFailure: "The backup worker failed. Check the job log for details.",
  BackupWorkerTransitionFailed: "The backup job could not advance to its next state.",
  BackupZstdUnavailable: "Backup compression is unavailable on this host.",
  BrBackfillFailed: "Backfilling this backup/restore record failed.",
  BrOpsNotYetImplemented: "This backup/restore operation is not yet available.",
  BrOpsQueryFailed: "Could not read backup/restore state. Try again in a moment.",
};

/** Rendered when the wire code is absent or not in the table (see spec 12). */
export const LARA_UNKNOWN_ERROR_COPY =
  "Something went wrong and we could not classify it. Quote the correlation ids below to support.";

export function laraCopyForErrorCode(
  code: string | null | undefined,
  context?: { retryAfterSeconds?: number },
): string {
  if (!code) return LARA_UNKNOWN_ERROR_COPY;
  const template = laraErrorCopy[code];
  if (template === undefined) return LARA_UNKNOWN_ERROR_COPY;
  if (code === "RateLimited") {
    if (context?.retryAfterSeconds === undefined) {
      return "Too many requests. Wait a moment and try again.";
    }
    return template.replace("{RetryAfterSec}", String(context.retryAfterSeconds));
  }
  return template;
}
