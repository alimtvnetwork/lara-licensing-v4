# API Error Taxonomy

**Version:** 1.10.0
**Updated:** 2026-07-21
**AI Confidence:** High
**Ambiguity:** Low

Closed set of `Attributes.Error.ErrorCode` values returned by LaraLicensingV1. No contract may emit a code not listed here. Every code maps to exactly one HTTP status. Codes are PascalCase and stable; renaming is a breaking change. Per-code log level and retry class are bound in [`21-error-management-binding.md`](./21-error-management-binding.md); the mandatory log level and `X-Request-Id` surface flag for every code are also aggregated in §Log level and `RequestId` surface below (Step 9 of Plan 02 remediation).

## Vocabulary sources

`AuthzRoleDenied` cites [`04-roles.md`](./04-roles.md) §Canonical set for role strings in `Attributes.Error.Details.Role`. `AuthzPermissionDenied` cites [`40-permissions.md`](./40-permissions.md) §2 for `PermissionKey` strings in `Attributes.Error.Details.AttemptedPermissionKey`. `ValidationInvalidCategory` cites [`05-license-categories.md`](./05-license-categories.md). `LicenseUserLimit` and `LicenseMachineLimit` cite [`06-license-variations.md`](./06-license-variations.md).

## Envelope reminder

Failure responses use the universal envelope from [`11-api-contracts/00-overview.md`](./11-api-contracts/00-overview.md):

```json
{
  "Status": { "IsSuccess": false, "Code": 400, "Message": "ValidationFailed" },
  "Attributes": {
    "RequestId": "01HXYZ",
    "RequestedAt": "2026-07-15T00:00:00Z",
    "Error": {
      "ErrorCode": "ValidationFailed",
      "ErrorMessage": "Human readable summary.",
      "Details": [{ "Field": "Email", "Rule": "Format" }]
    }
  },
  "Results": []
}
```

`Details` is optional, field-safe, and never contains secrets, tokens, hashes, fingerprints, or raw credentials.

## Categories

Codes are grouped by prefix so log filters, dashboards, and alert rules can key on a single dimension.

| Prefix | Meaning |
|--------|---------|
| `Validation*`, `Invalid*` | Request shape or content rejected before business logic. |
| `Auth*` | Authentication and authorization failures on protected endpoints. |
| `OAuth*` | OAuth 2.1 protocol failures (RFC 6749 error codes, PascalCased). |
| `License*` | License lookup, limit, or lifecycle failures. |
| `Serial*` | Serial number lookup and state failures. |
| `Verify*` | Hash and final-key verification failures. |
| `Prefix*`, `Reseller*`, `User*`, `Role*`, `Authz*`, `Quota*`, `Feature*` | Admin resource conflicts and role/authz/quota/feature failures. |
| `Update*` | Self-update manifest and asset failures on `/App/*` and `/Admin/AppUpdates/*`. |
| `RequestId*` | Observability guard failures on strict-list endpoints. |
| `Idempotency*` | Idempotency-Key collisions on mutations. |
| `RateLimited`, `Abuse*` | Throttling and abuse-prevention rejections. Rate policy is defined in [`14-rate-limiting.md`](./14-rate-limiting.md); this file only fixes the code and status. |
| `Server*` | Server-side faults the client cannot correct. |

## Canonical codes

| ErrorCode | HTTP | Category | Emitted by | Meaning |
|-----------|------|----------|------------|---------|
| `ValidationFailed` | 400 | Validation | all | Request body, query, or path failed schema validation. `Details[]` lists field and rule. |
| `InvalidJson` | 400 | Validation | all | Body is not parseable JSON. |
| `UnsupportedMediaType` | 415 | Validation | all | `Content-Type` is not `application/json`. |
| `MethodNotAllowed` | 405 | Validation | all | HTTP method not supported on this route. |
| `AuthUnauthorized` | 401 | Auth | protected | Missing, malformed, or unknown bearer token. |
| `AuthTokenExpired` | 401 | Auth | protected | Access token past `ExpiresAt`. |
| `AuthInvalidCredentials` | 401 | Auth | `POST /auth/login` | Email or password rejected. Never disclose which. |
| `AuthRefreshReused` | 401 | Auth | `POST /auth/refresh` | Refresh token already exchanged or revoked. Family is invalidated. |
| `AuthRefreshRaceLost` | 409 | Auth | `POST /Auth/Refresh` | Two concurrent refreshes on the same session lost the transactional rotation lock. Caller MUST re-read the newly rotated token from local storage before retry; not a family-reuse event. See [`31-auth-session-family.md`](./31-auth-session-family.md) §Rotation. |
| `LoginCaptchaRequired` | 428 | Auth | `POST /Api/Auth/Login` | Consecutive login failures for the (Email + IP) pair reached `lara.login_captcha.required_after_failed_attempts`. Client MUST fetch a fresh challenge from `GET /Api/Auth/Captcha` and re-submit with `CaptchaChallengeId` + `CaptchaAnswer`. |
| `LoginCaptchaInvalid` | 401 | Auth | `POST /Api/Auth/Login` | HMAC signature, expiry, or answer check on the submitted captcha failed. Client should request a fresh challenge and retry. |
| `AuthSaltRotationFailed` | 500 | Auth | internal (salt job) | Retention job failed to publish a new active `PiiHashSalt` row. Surfaced only in health checks and audit; never in a caller-facing response. Referenced here so the code is reserved and cannot be reused. See [`32-auth-session-retention.md`](./32-auth-session-retention.md). |
| `AuthForbidden` | 403 | Auth | protected | Authenticated actor lacks the required role or scope. |
| `OAuthInvalidRequest` | 400 | OAuth | `/oauth/*` | Missing or malformed OAuth parameter. |
| `OAuthInvalidClient` | 401 | OAuth | `/oauth/*` | `ClientId` or `ClientSecret` rejected. |
| `OAuthInvalidGrant` | 400 | OAuth | `/oauth/token` | `AuthorizationCode`, `RefreshToken`, or PKCE verifier rejected. |
| `OAuthUnsupportedGrantType` | 400 | OAuth | `/oauth/token` | `GrantType` not enabled for this client. |
| `LicenseNotFound` | 404 | License | license and verify routes | License row does not exist or is not visible to the actor. |
| `LicenseExpired` | 409 | License | license and verify routes | License past `ExpiresAt`. |
| `LicenseConflict` | 409 | License | license mutations | State transition rejected (e.g. already revoked). |
| `LicenseMachineLimit` | 409 | License | verify routes | Binding would exceed `MachineCount`. |
| `LicenseUserLimit` | 409 | License | verify routes | Binding would exceed per-user cap. |
| `SerialNotFound` | 404 | Serial | serial and verify routes | Serial does not exist. |
| `SerialInvalid` | 400 | Serial | verify routes | Serial format or checksum rejected. |
| `SerialRevoked` | 409 | Serial | verify routes | Serial marked revoked. |
| `VerifyHashInvalid` | 400 | Verify | `POST /verify/hash` | Hash does not match issued `HashKey`. |
| `VerifyKeyExpired` | 409 | Verify | `POST /verify/final` | Final verify key past its window. |
| `VerifyKeyMismatch` | 400 | Verify | `POST /verify/final` | Final key does not match issued value. |
| `VerifyKeyConsumed` | 409 | Verify | `POST /verify/final` | Final verify key was already consumed (replay attempt). |
| `PrefixNotFound` | 404 | Admin | prefix routes | Prefix row does not exist. |
| `PrefixConflict` | 409 | Admin | prefix mutations | `PrefixValue` already exists. |
| `PrefixInUse` | 409 | Admin | prefix delete | Prefix is referenced by at least one license. |
| `PrefixForbidden` | 403 | Admin | prefix routes | Reseller attempted to touch a prefix outside their scope. |
| `ResellerNotFound` | 404 | Admin | reseller routes | Reseller row does not exist. |
| `ResellerConflict` | 409 | Admin | reseller mutations | Reseller identifier or contact already exists. |
| `ResellerInUse` | 409 | Admin | reseller delete | Reseller owns at least one prefix or license. |
| `UserNotFound` | 404 | Admin | user routes | User row does not exist. |
| `UserConflict` | 409 | Admin | user mutations | `Email` already registered. |
| `RoleAssignmentNotFound` | 404 | Admin | role routes | Assignment row does not exist. |
| `AuthzRoleDenied` | 403 | Authz | role-gated routes | Actor lacks the required `app_role` per `has_role` check. See [`19-user-management.md`](./19-user-management.md). |
| `AuthzPermissionDenied` | 403 | Authz | permission-gated routes | Role gate passed but `has_permission(caller, DeclaredPermissionKey)` returned false. `Attributes.Error.Details` MUST include `{ "Field": "PermissionKey", "Rule": "Missing", "Value": <PermissionKey> }` where `<PermissionKey>` is the endpoint's declared key from [`40-permissions.md`](./40-permissions.md) §2 verbatim. Distinct from `AuthzRoleDenied` so operators can distinguish a mis-provisioned role from a mis-provisioned permission override. See [`19-user-management.md`](./19-user-management.md) §`has_permission` and [`40-permissions.md`](./40-permissions.md) §4. |
| `AuthzRowScopeDenied` | 403 | Authz | reseller-scoped routes | Actor authenticated but requested row is outside their tenant scope. |
| `AuthzLastAdminProtected` | 409 | Authz | `DELETE /Admin/Users/{UserId}/Roles/{Role}` | Refuses to revoke the last active `Admin` grant. Enforced at both authz layer and DB trigger. |
| `ResourceRoleNotAssigned` | 404 | Admin | role revoke | Target user does not currently hold that role. |
| `ResourceRoleAlreadyAssigned` | 409 | Admin | role grant | Target user already holds that role (active grant exists). |
| `ValidationInvalidRole` | 400 | Validation | role routes | `Role` value is not a member of the `app_role` enum. |
| `UpdateManifestUnavailable` | 404 | Update | `GET /App/UpdateManifest` | No published manifest for the requested `(Product, Platform, Channel)`. |
| `UpdateAssetNotFound` | 404 | Update | `HEAD|GET /App/UpdateAsset/*` | Manifest references an asset that no longer exists in storage. |
| `UpdateChecksumMismatch` | 409 | Update | asset upload verify | `X-Sha256` header does not match manifest checksum. Publish is rolled back. |
| `UpdateVersionDowngradeBlocked` | 409 | Update | `POST /Admin/AppUpdates` | New manifest `Version` is not strictly greater than the current published `Version` for the same channel. |
| `UpdateChannelForbidden` | 403 | Update | manifest/asset | Actor requested `Beta` channel without `has_role(AppBuilder|Admin)`. |
| `UpdateChannelUnknown` | 400 | Update | `GET /App/UpdateManifest` | `Channel` query parameter is not a member of the `ChannelType` enum (`Stable` \| `Beta`). |
| `UpdateAssetUploadFailed` | 502 | Update | `POST /Admin/AppUpdates/UploadTicket` finalize | Storage backend rejected the finalize call; ticket is discarded, no manifest row is written. |
| `UpdateAssetVerificationFailed` | 409 | Update | `POST /Admin/AppUpdates` | Post-upload SHA-256 recomputation disagrees with the manifest `Sha256`. Distinct from `UpdateChecksumMismatch`, which fires during upload; this fires during publish. Client-side, this code is ALSO raised by `src/lib/lara-self-update.ts` when the desktop shell aborts per MUST-abort rows A1, A2, A3, or A5 (see [`17-self-update-endpoint.md`](./17-self-update-endpoint.md)). |
| `UpdateDownloadFailed` | * | Update | `HEAD\|GET /App/UpdateAsset/*` | MUST-abort row A4 (see [`17-self-update-endpoint.md`](./17-self-update-endpoint.md) line 143). Client-side synthetic raised by `src/lib/lara-self-update.ts` when the asset response status is not 200 after redirects. `Attributes.Error.Details` carries `{HttpStatus, ErrorCode}` and preserves `X-Request-Id`. Never emitted directly by the server: the server returns its own status code and the client transforms it into this code. |
| `ValidationInvalidVersion` | 400 | Validation | manifest/publish | `Version`, `MinRequiredVersion`, or `CurrentVersion` failed the semver pattern `^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$`. |
| `RequestIdMissing` | 400 | RequestId | strict-list: `/Admin/*`, `/Verify/*`, `/App/UpdateAsset/*` | `X-Request-Id` header absent or empty on an endpoint that requires client-minted correlation. See [`20-observability.md`](./20-observability.md). |
| `IdempotencyConflict` | 409 | Idempotency | mutations | Same `Idempotency-Key` reused with a different body. |
| `IdempotencyKeyRequired` | 400 | Idempotency | mutation strict-list | `Idempotency-Key` header absent on a route that requires it (see [`29-idempotency-lifecycle.md`](./29-idempotency-lifecycle.md)). |
| `RateLimited` | 429 | RateLimit | all | Actor exceeded the rate window. `Attributes.Error.Details` includes `RetryAfterSeconds`. Policy in rate-limiting spec. |
| `AbuseBlocked` | 403 | RateLimit | verify routes | Fingerprint or IP flagged by abuse rules. |
| `ServerError` | 500 | Server | all | Unhandled fault. `RequestId` is the correlation key; no stack in response. |
| `ServiceUnavailable` | 503 | Server | all | Dependency (DB, cache) unavailable. |
| `UnknownServerError` | * | Server | client-only synthetic | Client-side code raised by `src/lib/lara-api-response.ts` when the server returns a well-formed failure envelope with an `ErrorCode` this client version has not learned. Never emitted by the server. Warn-level log line `Lara API unknown error code` MUST fire with `path`, `status`, `requestId`, `unknownCode`. Preserves the original `RequestId` so support can correlate. See F4 in `.lovable/pending-issues/issue-002-lib-runtime-spec-drift.md`. |
| `QuotaExhausted` | 409 | Quota | `POST /Licenses`, `POST /Serials` (reseller path) | Transactional decrement in [`41-reseller-quotas.md`](./41-reseller-quotas.md) §3 found `LicensesConsumed >= LicensesGranted` for the caller's `(ResellerId, LicenseCategoryId, LicenseTierId, current Period)` row. `Attributes.Error.Details` MUST include `{ "Field": "Quota", "Rule": "Exhausted", "Value": <Category>/<Tier> }` where values are the canonical names from [`05-license-categories.md`](./05-license-categories.md) and [`43-license-tiers.md`](./43-license-tiers.md). Never leaks absolute `LicensesGranted` or `LicensesConsumed` counts to the caller. Response MUST also carry `RetryAfterSeconds = 0` (no automatic retry; caller must request more quota via `42-quota-requests.md`). |
| `QuotaCategoryUnauthorized` | 403 | Quota | reseller license mutations | Caller passed role and permission gates but the requested `(LicenseCategoryId, LicenseTierId)` combination has no matching `ResellerQuotas` row for the caller's `ResellerId` (never provisioned). Distinct from `QuotaExhausted`: exhaustion means "you had a quota row and it's empty"; unauthorized means "you never had a quota row for this combination". `Attributes.Error.Details` MUST include `{ "Field": "Quota", "Rule": "NotProvisioned", "Value": <Category>/<Tier> }`. Enforces the Reseller-scope invariant that an Admin has not silently granted a Reseller access to a category/tier without explicit provisioning. |
| `QuotaLedgerConflict` | 500 | Quota | `POST /Licenses`, `POST /Serials` (reseller path) | The transactional decrement contract in [`41-reseller-quotas.md`](./41-reseller-quotas.md) §3 completed the `UPDATE ResellerQuotas` but the paired `INSERT ResellerQuotaLedger` violated a CHECK constraint (`CkQuotaLedgerConsumeLicense`, `CkQuotaLedgerDeltaSign`) or the transaction failed to commit atomically, leaving the operation rolled back. This is a server bug, not a caller error; surfaced as 500 with distinct code so an on-call operator does not confuse it with `ServerError` when triaging quota discrepancies. Log line MUST include the attempted `LedgerAction`, `Delta`, and constraint name from the DB error. Never emitted to the caller with any quota counts or reseller identifiers beyond the caller's own `RequestId`. |
| `EnvironmentMismatch` | 409 | Verify | verify routes (`POST /Verify/Serial`, `POST /Verify/Hash`, `POST /Verify/Final`) | The verify-time gate in [`44-environments.md`](./44-environments.md) §3 detected that the caller's `EnvironmentId` (derived from the AppBuilder OAuth client) differs from the license row's `EnvironmentId`. `Attributes.Error.Details` MUST equal `[{ "Field": "Environment", "Rule": "Mismatch", "Value": "<Requested>/<Licensed>" }]` where `Requested` and `Licensed` are the LITERAL tokens (not the actual environment names) so the response cannot be used to enumerate a license's environment. Never emitted at issue time; issue-time environment failures are `ValidationFailed` per AC-LENV-002. |
| `FeatureUnknown` | 400 | Feature | admin feature writes (`POST/PUT/DELETE /Admin/Tiers/{TierId}/Features`, `POST/PUT/DELETE /Admin/Licenses/{LicenseId}/Features`) | The caller supplied a `FeatureKey` that is not present in the `Features` catalog defined by [`45-license-features.md`](./45-license-features.md) §2. `Attributes.Error.Details` MUST equal `[{ "Field": "FeatureKey", "Rule": "Unknown", "Value": "<offending-key>" }]`. Forbidden synonyms from §2 (e.g. `feature.reports`, `max_users`) hit this code, not `ValidationFailed`, so operators can distinguish schema drift from generic input errors. Never emitted by verify endpoints (data drift on the read path returns `500 UnknownServerError` per AC-API-VER-013). |
| `FeatureValueInvalid` | 400 | Feature | admin feature writes (same routes as `FeatureUnknown`) | The caller supplied a `Value` whose JSON shape does not match the declared `Features.ValueType` per [`45-license-features.md`](./45-license-features.md) §3, OR (for closed-enum `String` keys such as `Support.Tier`) a value outside the closed member set. `Attributes.Error.Details` MUST equal `[{ "Field": "Value", "Rule": "TypeMismatch" \| "MembershipRequired", "Value": "<FeatureKey>" }]`. The `Value` slot names the `FeatureKey`, never the offending payload (which may contain caller-controlled strings). |
| `PreconditionRequired` | 428 | Concurrency | in-scope routes in [`11-api-contracts/09-concurrency-control.md`](./11-api-contracts/09-concurrency-control.md) §Scope | `If-Match` header absent on a route that requires it. `Attributes.Error.Details` MUST equal `[{ "Field": "If-Match", "Rule": "Missing" }]`. Never emitted by routes outside the scope table. See AC-CONCUR-002. |
| `PreconditionFailed` | 412 | Concurrency | in-scope routes in [`11-api-contracts/09-concurrency-control.md`](./11-api-contracts/09-concurrency-control.md) §Scope | `If-Match` value does not match the current row ETag. `Attributes.Error.Details` MUST equal `[{ "Field": "If-Match", "Rule": "Stale", "Value": "<currentEtag>" }]` where `<currentEtag>` is the current server ETag verbatim (safe to disclose per §Request rules). Row is unchanged. See AC-CONCUR-003. |
| `ValidationInputInvalid` | 400 | Validation | all | Field-level input rejected by domain rules that run after schema validation. `Details[]` names the offending field and rule. Distinct from `ValidationFailed` so operators can separate schema failures from business-rule rejections. |
| `ValidationConflict` | 409 | Validation | all mutations | Domain invariant would be violated by the write (unique constraint, closed-set transition, incompatible flag combination). `Details[]` names the invariant. Row is unchanged. |
| `PasswordResetTokenInvalid` | 400 | Auth | `POST /auth/reset-password` | Reset token missing, malformed, expired, or already consumed. Neutral copy on the SPA to prevent enumeration. |
| `LicenseRevoked` | 409 | License | mutation and verify paths | Target license row has `RevokedAt` set. Row is unchanged. Distinct from `LicenseExpired` so client can surface the correct remediation. |
| `MachineRebindCooldownActive` | 409 | License | `POST /Portal/MachineBinding` | Machine was rebound within `RebindCooldownMinutes` (see [`06-license-variations.md`](./06-license-variations.md)). `Details[0].Value` carries the remaining cooldown in whole seconds so the client can render a countdown. |
| `FeatureCatalogUnseeded` | 500 | Feature | admin issue and verify paths | The Root `Features` catalog has zero rows so `FeatureService` cannot resolve `FeatureKey`. Operator-facing signal; never expected in production once bootstrap seeder has run. |
| `FeatureNotAvailable` | 501 | Feature | verify paths | Requested `FeatureKey` is a member of the catalog but this license's tier does not grant it. `Details[0].Value` is the requested key verbatim. |
| `ImpersonationAlreadyActive` | 409 | Auth | `POST /Api/Auth/Impersonation` | The caller's session already carries an active impersonation. End the current impersonation before starting a new one. |
| `ImpersonationParentSessionInvalid` | 409 | Auth | `POST /Api/Auth/Impersonation/End` | Parent admin session referenced by an impersonation token has been closed or does not exist. Client MUST return to the sign-in page. |
| `UpdateSignatureUnavailable` | 404 | Update | `GET /App/Update/Manifest`, asset endpoints | Manifest asset exists but the Ed25519 signature side-file has not been minted yet. Retryable after the publisher completes the signing job. |
| `BackupCorrupt` | 422 | Backup/Restore | import, restore, snapshot, and archive verification | Archive structure, hashes, signatures, encryption metadata, or audit-chain integrity failed verification. Safe `Details` identify the failed rule without exposing key material or archive contents. |
| `BackupExportProductionPending` | 501 | Backup/Restore | production export enqueue | Production export execution is not available in the current rollout stage. No export job is started. |
| `BackupKeyEpochRetired` | 409 | Backup/Restore | export and key selection | The requested key epoch is retired and cannot encrypt a new archive. Existing archives remain readable according to the retention policy. |
| `BackupStorageFailure` | 500 | Backup/Restore | archive and snapshot storage operations | The configured archive storage backend failed an operation. The response never exposes provider credentials, object paths, or internal exception text. |
| `BackupVersionMismatch` | 409 | Backup/Restore | import and restore preflight | Archive format or application compatibility requirements do not match the running release. Restore does not begin. |
| `BackupWorkerFailure` | 500 | Backup/Restore | asynchronous export, import, snapshot, or restore worker | A worker failed while executing a backup/restore job. `ErrorId` and `RequestId` correlate the response or job state to diagnostic logs. |
| `BackupWorkerTransitionFailed` | 500 | Backup/Restore | backup/restore job state transition | The requested worker transition violated the job state machine or could not be persisted atomically. |
| `BackupZstdUnavailable` | 500 | Backup/Restore | archive compression and decompression | The required zstd implementation is unavailable. The job fails before publishing or applying an archive. |
| `BrBackfillFailed` | 500 | Backup/Restore | backup/restore rollout backfill | A rollout backfill failed and requires operator intervention before backup/restore can be enabled safely. |
| `BrOpsNotYetImplemented` | 501 | Backup/Restore | backup/restore operations surface | The requested operational action is reserved by the contract but is not implemented in this release. |
| `BrOpsQueryFailed` | 500 | Backup/Restore | backup/restore operations query | An operational status or diagnostics query failed. Internal query details remain in server logs only. |
| `Policy.VersionMismatch` | 409 | Policy | admin policies | Policy schema version rejected. |
| `Snapshot.Pinned` | 409 | Snapshot | admin snapshots | Pinned snapshot cannot be deleted or pruned. |




## Log level and `RequestId` surface

Every code below has a mandatory minimum log level and a "Surface" flag indicating whether the client MUST render `X-Request-Id` to the operator when this code is received. `Surface = yes` means the UI or CLI MUST include the request id in the error message per [`20-observability.md`](./20-observability.md); `Surface = no` means the code is server-internal (never sent in a caller-facing response) and the id lives only in server logs. Log levels are derived from [`21-error-management-binding.md`](./21-error-management-binding.md) §"Log level ladder".

| ErrorCode | Log Level | Surface `X-Request-Id` |
|-----------|-----------|------------------------|
| `ValidationFailed` | `Debug` | yes |
| `InvalidJson` | `Debug` | yes |
| `UnsupportedMediaType` | `Debug` | yes |
| `MethodNotAllowed` | `Debug` | yes |
| `AuthUnauthorized` | `Warn` | yes |
| `AuthTokenExpired` | `Warn` | yes |
| `AuthInvalidCredentials` | `Warn` | yes |
| `AuthRefreshReused` | `Fatal` | yes |
| `AuthRefreshRaceLost` | `Warn` | yes |
| `AuthSessionNotFound` | `Debug` | yes |
| `AuthSessionAlreadyClosed` | `Warn` | yes |
| `LoginCaptchaRequired` | `Info` | yes |
| `LoginCaptchaInvalid` | `Warn` | yes |
| `AuthSaltRotationFailed` | `Error` | no |
| `AuthForbidden` | `Warn` | yes |
| `AuthRegistrationClosed` | `Warn` | yes |
| `OAuthInvalidRequest` | `Warn` | yes |
| `OAuthInvalidClient` | `Warn` | yes |
| `OAuthInvalidGrant` | `Warn` | yes |
| `OAuthUnsupportedGrantType` | `Warn` | yes |
| `LicenseNotFound` | `Debug` | yes |
| `LicenseExpired` | `Warn` | yes |
| `LicenseConflict` | `Warn` | yes |
| `LicenseMachineLimit` | `Warn` | yes |
| `LicenseUserLimit` | `Warn` | yes |
| `SerialNotFound` | `Debug` | yes |
| `SerialInvalid` | `Debug` | yes |
| `SerialRevoked` | `Warn` | yes |
| `VerifyHashInvalid` | `Warn` | yes |
| `VerifyKeyExpired` | `Warn` | yes |
| `VerifyKeyMismatch` | `Warn` | yes |
| `VerifyKeyConsumed` | `Warn` | yes |
| `PrefixNotFound` | `Debug` | yes |
| `PrefixConflict` | `Warn` | yes |
| `PrefixInUse` | `Warn` | yes |
| `PrefixForbidden` | `Warn` | yes |
| `ResellerNotFound` | `Debug` | yes |
| `ResellerConflict` | `Warn` | yes |
| `ResellerInUse` | `Warn` | yes |
| `UserNotFound` | `Debug` | yes |
| `UserConflict` | `Warn` | yes |
| `RoleAssignmentNotFound` | `Debug` | yes |
| `AuthzRoleDenied` | `Warn` | yes |
| `AuthzPermissionDenied` | `Warn` | yes |
| `AuthzRowScopeDenied` | `Warn` | yes |
| `AuthzLastAdminProtected` | `Fatal` | yes |
| `ResourceRoleNotAssigned` | `Debug` | yes |
| `ResourceRoleAlreadyAssigned` | `Warn` | yes |
| `ValidationInvalidRole` | `Debug` | yes |
| `UpdateManifestUnavailable` | `Info` | yes |
| `UpdateAssetNotFound` | `Error` | yes |
| `UpdateChecksumMismatch` | `Error` | yes |
| `UpdateVersionDowngradeBlocked` | `Warn` | yes |
| `UpdateChannelForbidden` | `Warn` | yes |
| `UpdateChannelUnknown` | `Debug` | yes |
| `UpdateAssetUploadFailed` | `Error` | yes |
| `UpdateAssetVerificationFailed` | `Error` | yes |
| `UpdateDownloadFailed` | `Error` | yes |
| `ValidationInvalidVersion` | `Debug` | yes |
| `RequestIdMissing` | `Warn` | yes |
| `IdempotencyConflict` | `Warn` | yes |
| `IdempotencyKeyRequired` | `Debug` | yes |
| `RateLimited` | `Warn` | yes |
| `AbuseBlocked` | `Warn` | yes |
| `ServerError` | `Error` | yes |
| `ServiceUnavailable` | `Error` | yes |
| `UnknownServerError` | `Warn` | yes |
| `QuotaExhausted` | `Warn` | yes |
| `QuotaCategoryUnauthorized` | `Warn` | yes |
| `QuotaLedgerConflict` | `Error` | yes |
| `EnvironmentMismatch` | `Warn` | yes |
| `FeatureUnknown` | `Warn` | yes |
| `FeatureValueInvalid` | `Debug` | yes |
| `PreconditionRequired` | `Debug` | yes |
| `PreconditionFailed` | `Warn` | yes |
| `ValidationInputInvalid` | `Debug` | yes |
| `ValidationConflict` | `Warn` | yes |
| `PasswordResetTokenInvalid` | `Warn` | no |
| `LicenseRevoked` | `Warn` | yes |
| `MachineRebindCooldownActive` | `Warn` | yes |
| `FeatureCatalogUnseeded` | `Error` | yes |
| `FeatureNotAvailable` | `Warn` | yes |
| `ImpersonationAlreadyActive` | `Warn` | yes |
| `ImpersonationParentSessionInvalid` | `Warn` | yes |
| `UpdateSignatureUnavailable` | `Warn` | yes |
| `RuntimeConfigConflict` | `Warn` | yes |
| `RuntimeConfigForbidden` | `Warn` | yes |
| `RuntimeConfigInvalidField` | `Debug` | yes |
| `RuntimeConfigLocked` | `Warn` | yes |
| `RuntimeConfigModeMismatch` | `Warn` | yes |
| `RuntimeConfigWriteFailed` | `Error` | yes |
| `BackupCorrupt` | `Error` | yes |
| `BackupExportProductionPending` | `Warn` | yes |
| `BackupKeyEpochRetired` | `Warn` | yes |
| `BackupStorageFailure` | `Error` | yes |
| `BackupVersionMismatch` | `Warn` | yes |
| `BackupWorkerFailure` | `Error` | yes |
| `BackupWorkerTransitionFailed` | `Error` | yes |
| `BackupZstdUnavailable` | `Error` | yes |
| `BrBackfillFailed` | `Error` | yes |
| `BrOpsNotYetImplemented` | `Warn` | yes |
| `BrOpsQueryFailed` | `Error` | yes |
| `Policy.VersionMismatch` | `Warn` | yes |
| `Snapshot.Pinned` | `Warn` | yes |



## Rules

- One code per failure. Never emit two error codes in one response.
- HTTP status is derived from the code, never chosen independently. A code mismatch is a contract bug.
- `ErrorMessage` is human-readable and localizable. Machines key on `ErrorCode`.
- `Details` entries use `{ "Field": PascalCase, "Rule": PascalCase, "Value"?: safe-scalar }`. No secrets, no fingerprints, no hashes.
- Adding a new code requires bumping this file's minor version and adding it to every affected contract file in the same change.
- Removing or renaming a code is a breaking change and requires a major version bump.

## Acceptance

- AC-ERR-001: Every `ErrorCode` referenced in [`11-api-contracts/`](./11-api-contracts/) appears in the table above with matching HTTP status.
- AC-ERR-002: No contract emits an ad-hoc code not listed here.
- AC-ERR-003: Every failing response includes `RequestId` and omits secret-bearing attributes.
- AC-ERR-004: Every server log line for a failing request records `RequestId`, `ErrorCode`, HTTP status, route template, actor type, actor id, and duration.
- AC-ERR-005: A caller passing the role gate but failing `has_permission()` receives `AuthzPermissionDenied` (not `AuthzRoleDenied`), and the response `Attributes.Error.Details` names the missing `PermissionKey` from [`40-permissions.md`](./40-permissions.md) §2 verbatim. Enforced runtime-side by the parity test that asserts `ApiErrorCodeType.AuthzPermissionDenied` exists (Step 41 of Plan 05) and spec-side by this row's presence in both the canonical-codes and log-level tables above.
- AC-ERR-006: A `Reseller`-scoped `POST /Licenses` or `POST /Serials` that exhausts its `ResellerQuotas` row returns `QuotaExhausted` (409) with `Attributes.Error.Details = [{ "Field": "Quota", "Rule": "Exhausted", "Value": "<Category>/<Tier>" }]` and MUST NOT leak `LicensesGranted` or `LicensesConsumed` counts. Enforced by [`41-reseller-quotas.md`](./41-reseller-quotas.md) AC-QUOTA-002 at the runtime layer and by this taxonomy row at the contract layer.
- AC-ERR-007: A `Reseller`-scoped mutation for a `(LicenseCategoryId, LicenseTierId)` with no matching `ResellerQuotas` row returns `QuotaCategoryUnauthorized` (403), NEVER `QuotaExhausted`. Logs MUST distinguish the two so operators can triage misprovisioning versus depletion. Enforced by AC-QUOTA-006.
- AC-ERR-008: A `ResellerQuotaLedger` INSERT that fails a CHECK constraint (`CkQuotaLedgerConsumeLicense`, `CkQuotaLedgerAdjustRequest`, or `CkQuotaLedgerDeltaSign`) rolls back the entire quota decrement transaction and surfaces as `QuotaLedgerConflict` (500). The response MUST NOT include quota counts or reseller identifiers; the server log line MUST include the constraint name and the attempted `(LedgerAction, Delta)` tuple.
- AC-ERR-009: A verify request with an `EnvironmentId` differing from the license row's `EnvironmentId` returns `EnvironmentMismatch` (409) with `Attributes.Error.Details = [{ "Field": "Environment", "Rule": "Mismatch", "Value": "<Requested>/<Licensed>" }]`. The `Value` slot contains the LITERAL tokens `Requested` and `Licensed`, NEVER the real environment names, so the response cannot be used to enumerate a license's environment. Enforced spec-side by [`44-environments.md`](./44-environments.md) AC-LENV-004 and taxonomy-side by this row.
- AC-ERR-010: An admin feature write (`TierFeatures` or `LicenseFeatures` mutation) with a `FeatureKey` not present in the `Features` catalog returns `FeatureUnknown` (400) with `Attributes.Error.Details = [{ "Field": "FeatureKey", "Rule": "Unknown", "Value": "<offending-key>" }]`. Enforced spec-side by [`45-license-features.md`](./45-license-features.md) AC-FEAT-001 and taxonomy-side by this row. Forbidden synonyms from §2 MUST hit this code, not `ValidationFailed`.
- AC-ERR-011: An admin feature write whose `Value` fails the declared `ValueType` shape (or, for closed-enum `String` keys, the closed member set) returns `FeatureValueInvalid` (400) with `Attributes.Error.Details = [{ "Field": "Value", "Rule": "TypeMismatch" \| "MembershipRequired", "Value": "<FeatureKey>" }]`. The `Value` slot MUST name the `FeatureKey`, NEVER the offending payload. Enforced spec-side by AC-FEAT-002 and taxonomy-side by this row.

## References

- [`10-endpoints.md`](./10-endpoints.md)
- [`11-api-contracts/00-overview.md`](./11-api-contracts/00-overview.md)
- [`../03-error-manage/`](../03-error-manage/)
