# Error Code Registry Backfill Report

Plan 11 step 22. Grep-derived diff between codes actually thrown in `backend/app/` (via `LaraException::make('<Code>', ...)`) and the closed sets in `backend/config/lara.php` (`error_codes`, `error_http_status`) and `src/lib/lara-api-error.ts` (`ApiErrorCodeType`).

## Method

```
grep -oE "LaraException::make\(['\"][A-Za-z]+['\"]" -r backend/app backend/bootstrap \
  | sed -E "s/.*make\(['\"]([A-Za-z]+)['\"].*/\1/" | sort -u
```

30 distinct codes are thrown at least once in the codebase.

## Findings

### Missing from closed set (FIXED in this step)

| Code | Status | Thrown at |
|---|---|---|
| `VerifyKeyConsumed` | 409 | `backend/app/Http/Controllers/Portal/VerifyController.php:594` |

Runtime symptom before fix: `LaraException::resolveStatus()` at `backend/app/Exceptions/LaraException.php:63-67` would throw `InvalidArgumentException("Unknown ErrorCode 'VerifyKeyConsumed'")` on any duplicate verify-key hit, converting a well-defined 409 into an unhandled 500 with a leaked stack.

Fix: added `VerifyKeyConsumed` to `backend/config/lara.php` `error_codes` list (line 256) and `error_http_status` map (line 357, status 409), plus the FE mirror at `src/lib/lara-api-error.ts` line 60 and `src/lib/error-copy.ts` line 46.

### In config but never thrown (54 codes)

Kept in the closed set intentionally: registered contracts referenced by FormRequests, middleware, framework exception re-shaping, and update/refresh flows not yet exercised in the code paths grep can see. Full list:

`AbuseBlocked, AuthRefreshRaceLost, AuthRefreshReused, AuthRegistrationClosed, AuthSaltRotationFailed, AuthTokenExpired, AuthzRoleDenied, AuthzRowScopeDenied, EnvironmentMismatch, FeatureCatalogUnseeded, FeatureNotAvailable, FeatureUnknown, FeatureValueInvalid, IdempotencyConflict, IdempotencyKeyRequired, InvalidJson, LicenseConflict, LicenseExpired, LicenseMachineLimit, LicenseUserLimit, MachineRebindCooldownActive, MethodNotAllowed, OAuthInvalidClient, OAuthInvalidGrant, OAuthInvalidRequest, OAuthUnsupportedGrantType, PreconditionRequired, PrefixConflict, PrefixInUse, QuotaCategoryUnauthorized, QuotaExhausted, QuotaLedgerConflict, RateLimited, RequestIdMissing, ResellerConflict, ResellerInUse, RoleAssignmentNotFound, SerialNotFound, ServiceUnavailable, UnsupportedMediaType, UpdateAssetNotFound, UpdateAssetUploadFailed, UpdateAssetVerificationFailed, UpdateChannelForbidden, UpdateChannelUnknown, UpdateChecksumMismatch, UpdateDownloadFailed, UpdateManifestUnavailable, UpdateSignatureUnavailable, UpdateVersionDowngradeBlocked, ValidationConflict, ValidationInputInvalid, ValidationInvalidVersion, VerifyHashInvalid, VerifyKeyExpired`

Follow-up owners: Step 43 (Pest tests for every controller throwing a domain error) will progressively convert this "registered but silent" set into "registered and covered". These are not spec drift; they are contract stubs for the follow-up backfill.

## Thrown codes (30, all now in closed set)

`AuthForbidden, AuthInvalidCredentials, AuthSessionAlreadyClosed, AuthSessionNotFound, AuthUnauthorized, AuthzLastAdminProtected, AuthzPermissionDenied, ImpersonationAlreadyActive, ImpersonationParentSessionInvalid, LicenseNotFound, LicenseRevoked, LoginCaptchaInvalid, LoginCaptchaRequired, PasswordResetTokenInvalid, PreconditionFailed, PrefixForbidden, PrefixNotFound, ResellerNotFound, ResourceRoleAlreadyAssigned, ResourceRoleNotAssigned, SerialInvalid, SerialRevoked, ServerError, UnknownServerError, UserConflict, UserNotFound, ValidationFailed, ValidationInvalidRole, VerifyKeyConsumed, VerifyKeyMismatch`

Parity check (`scripts/check-error-code-parity.mjs`) now green after this backfill.
