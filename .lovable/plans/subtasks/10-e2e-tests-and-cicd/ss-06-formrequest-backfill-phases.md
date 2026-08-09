# SS-06: FormRequest backfill phases (Plan 10 step 2)

Root cause: the endpoint audit (v0.322.0) shows 30 mutation routes with `missing-request`. Doing all 30 in one turn is a large blast-radius change with no test coverage on many of them yet (steps 15-24 haven't landed). Split step 2 into four phases so each phase is small, testable, and independently revertible.

## Phase A - Root DB Admin CRUD [DONE in v0.323.0]

- `POST /Resellers` -> `ResellerStoreRequest`
- `PATCH /Resellers/{ResellerSlug}` -> `ResellerUpdateRequest`
- `POST /Prefixes` -> `PrefixStoreRequest`

## Phase B - License mutations (shard-bound)

- `POST /Licenses` (Admin) -> `LicenseIssueRequest`
- `PATCH /Licenses/{LicenseKey}` -> `LicenseUpdateRequest`
- `DELETE /Licenses/{LicenseKey}` -> `LicenseDestroyRequest` (or no body, just Idempotency-Key)
- `POST /Licenses` (Reseller) -> `Reseller\LicenseIssueRequest`
- `PATCH /Licenses/{LicenseKey}/Renew` -> `Reseller\LicenseRenewRequest`
- `POST /Licenses/{LicenseKey}/Bindings/{MachineBindingId}/Release` -> `BindingReleaseRequest`
- `POST /Licenses/{LicenseKey}/Bindings/{MachineBindingId}/ClearCooldown` -> `BindingClearCooldownRequest`
- `DELETE /Prefixes/{PrefixValue}` -> `PrefixDestroyRequest` (no body but audits need it)

## Phase C - User / Impersonation / Session

- `POST /Users`, `PATCH /Users/{UserId}` -> `UserStoreRequest`, `UserUpdateRequest`
- `POST /Users/{UserId}/Roles`, `DELETE /Users/{UserId}/Roles/{RoleName}` -> `UserAssignRoleRequest`, `UserRevokeRoleRequest`
- `POST /Users/{UserId}/Impersonate`, `POST /Impersonation/End`, `POST /Impersonation/{SessionId}/ForceEnd` -> `ImpersonationStartRequest`, `ImpersonationEndRequest`, `ImpersonationForceEndRequest`
- `DELETE /Sessions/{SessionId}` -> `SessionDestroyRequest`
- `POST /Api/Auth/Logout` -> `LogoutRequest`

## Phase D - Verify + AppUpdate + QuotaRequest + Serial

- `POST /QuotaRequests`, `POST /QuotaRequests/{RequestId}/Approve|Deny|Cancel` -> four request classes
- `POST /AppUpdates/{Version}/Yank` -> `AppUpdateYankRequest`
- `PUT /App/UpdateAssetReceiver/{UploadToken}` -> `UpdateAssetReceiverRequest`
- `POST /Serials` -> `SerialIssueRequest`
- `POST /Verify/Serial`, `POST /Verify/Hash`, `POST /Verify/Final` -> `VerifySerialRequest`, `VerifyHashRequest`, `VerifyFinalRequest`

## Contract each FormRequest must honor

1. Extend `Illuminate\Foundation\Http\FormRequest`.
2. `authorize()` returns `true` (admin/reseller/portal gate lives in middleware and Plan 10 step 3 policies).
3. `rules()` mirrors the previous inline `$request->validate()` rule set exactly.
4. Override `failedValidation()` to throw `LaraException::make('ValidationFailed', <human message>, [{Field, Rule}])`, so wire behavior does not change and existing Pest tests keep passing.
5. Provide a typed `payload()` returning a shape-narrowed array (`@return array{...}`).
6. Remove the controller's private `validatePayload`/`flattenValidationErrors` when no longer referenced; drop `use Illuminate\Validation\ValidationException` if unused.
