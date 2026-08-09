# Endpoint parity snapshot (Plan 10 step 1)

Generated: 2026-07-19. Route count: 61.

Static-analysis snapshot from scanning `backend/routes/api.php` plus controller files. The authoritative report is produced by `php artisan lara:audit:endpoints --json` in CI (`backend/app/Console/Commands/AuditEndpoints.php`); this file is a pre-CI preview so we have a before/after signal for the DoD.

## Summary

- Total: 61
- Ok: 0
- MissingRequest: 30  (backfill in Plan 10 step 2)
- MissingPolicy: 53  (backfill in Plan 10 step 3)
- MissingResource: 61  (backfill in Plan 10 step 4)
- MissingTest: 50  (backfill in Plan 10 step 15; blocking gate)

## Rows

| Verb | Uri | Controller | Action | Findings |
|-|-|-|-|-|
| POST | `/Api/Auth/Register` | `RegisterController` | `__invoke` | missing-resource, missing-policy, missing-test |
| POST | `/Api/Auth/Login` | `LoginController` | `__invoke` | missing-resource, missing-policy |
| GET | `/Api/Auth/Captcha` | `CaptchaController` | `__invoke` | missing-resource, missing-test |
| POST | `/Api/Auth/ForgotPassword` | `ForgotPasswordController` | `__invoke` | missing-resource, missing-policy, missing-test |
| POST | `/Api/Auth/ResetPassword` | `ResetPasswordController` | `__invoke` | missing-resource, missing-policy, missing-test |
| POST | `/Api/Auth/Logout` | `LogoutController` | `__invoke` | missing-request, missing-resource, missing-test |
| GET | `/Api/Public/Health` | `HealthController` | `__invoke` | missing-resource, missing-test |
| GET | `/Resellers` | `ResellerController` | `index` | missing-resource, missing-policy, missing-test |
| POST | `/Resellers` | `ResellerController` | `store` | missing-request, missing-resource, missing-policy, missing-test |
| GET | `/Resellers/{ResellerSlug}` | `ResellerController` | `show` | missing-resource, missing-policy, missing-test |
| PATCH | `/Resellers/{ResellerSlug}` | `ResellerController` | `update` | missing-request, missing-resource, missing-policy, missing-test |
| GET | `/Prefixes` | `PrefixController` | `index` | missing-resource, missing-policy, missing-test |
| POST | `/Prefixes` | `PrefixController` | `store` | missing-request, missing-resource, missing-policy, missing-test |
| DELETE | `/Prefixes/{PrefixValue}` | `PrefixController` | `destroy` | missing-request, missing-resource, missing-policy, missing-test |
| POST | `/Licenses` | `LicenseController` | `issue` | missing-request, missing-resource, missing-policy |
| GET | `/Licenses/{LicenseKey}` | `LicenseController` | `show` | missing-resource, missing-policy |
| PATCH | `/Licenses/{LicenseKey}` | `LicenseController` | `update` | missing-request, missing-resource, missing-policy |
| DELETE | `/Licenses/{LicenseKey}` | `LicenseController` | `destroy` | missing-request, missing-resource, missing-policy |
| GET | `/Licenses/{LicenseKey}/Ledger` | `LicenseController` | `ledger` | missing-resource, missing-policy |
| GET | `/Licenses/{LicenseKey}/Bindings` | `BindingController` | `index` | missing-resource, missing-policy, missing-test |
| POST | `/Licenses/{LicenseKey}/Bindings/{MachineBindingId}/Release` | `BindingController` | `release` | missing-request, missing-resource, missing-policy, missing-test |
| POST | `/Licenses/{LicenseKey}/Bindings/{MachineBindingId}/ClearCooldown` | `BindingController` | `clearCooldown` | missing-request, missing-resource, missing-policy, missing-test |
| GET | `/Users` | `UserController` | `index` | missing-resource, missing-policy, missing-test |
| POST | `/Users` | `UserController` | `store` | missing-request, missing-resource, missing-policy, missing-test |
| GET | `/Users/{UserId}` | `UserController` | `show` | missing-resource, missing-policy, missing-test |
| PATCH | `/Users/{UserId}` | `UserController` | `update` | missing-request, missing-resource, missing-policy, missing-test |
| GET | `/Users/{UserId}/Roles` | `UserController` | `listRoles` | missing-resource, missing-policy, missing-test |
| POST | `/Users/{UserId}/Roles` | `UserController` | `assignRole` | missing-request, missing-resource, missing-policy, missing-test |
| DELETE | `/Users/{UserId}/Roles/{RoleName}` | `UserController` | `revokeRole` | missing-request, missing-resource, missing-policy, missing-test |
| POST | `/Users/{UserId}/Impersonate` | `UserController` | `impersonate` | missing-request, missing-resource, missing-policy, missing-test |
| POST | `/Impersonation/End` | `UserController` | `endImpersonation` | missing-request, missing-resource, missing-policy, missing-test |
| POST | `/Impersonation/{SessionId}/ForceEnd` | `UserController` | `forceEndImpersonation` | missing-request, missing-resource, missing-policy, missing-test |
| GET | `/QuotaRequests` | `QuotaRequestController` | `index` | missing-resource, missing-policy, missing-test |
| GET | `/QuotaRequests/All` | `QuotaRequestController` | `indexAll` | missing-resource, missing-policy, missing-test |
| POST | `/QuotaRequests/{RequestId}/Approve` | `QuotaRequestController` | `approve` | missing-request, missing-resource, missing-policy, missing-test |
| POST | `/QuotaRequests/{RequestId}/Deny` | `QuotaRequestController` | `deny` | missing-request, missing-resource, missing-policy, missing-test |
| GET | `/AppUpdates` | `AppUpdateController` | `index` | missing-resource, missing-policy, missing-test |
| POST | `/AppUpdates/UploadTicket` | `AppUpdateController` | `uploadTicket` | missing-resource, missing-policy, missing-test |
| POST | `/AppUpdates` | `AppUpdateController` | `publish` | missing-resource, missing-policy, missing-test |
| POST | `/AppUpdates/{Version}/Yank` | `AppUpdateController` | `yank` | missing-request, missing-resource, missing-policy, missing-test |
| GET | `/Metrics` | `MetricsController` | `index` | missing-resource, missing-policy, missing-test |
| GET | `/Metrics/ShardStatus` | `MetricsController` | `shardStatus` | missing-resource, missing-policy, missing-test |
| GET | `/AuditLogs` | `AuditController` | `index` | missing-resource, missing-test |
| GET | `/Users/{UserId}/Sessions` | `SessionController` | `index` | missing-resource, missing-policy, missing-test |
| DELETE | `/Sessions/{SessionId}` | `SessionController` | `destroy` | missing-request, missing-resource, missing-policy, missing-test |
| GET | `/Licenses` | `LicenseController` | `index` | missing-resource, missing-policy |
| POST | `/Licenses` | `LicenseController` | `issue` | missing-request, missing-resource, missing-policy |
| GET | `/Licenses/{LicenseKey}` | `LicenseController` | `show` | missing-resource, missing-policy |
| PATCH | `/Licenses/{LicenseKey}/Renew` | `LicenseController` | `renew` | missing-request, missing-resource, missing-policy |
| GET | `/Licenses/{LicenseKey}/Ledger` | `LicenseController` | `ledger` | missing-resource, missing-policy |
| GET | `/QuotaRequests` | `QuotaRequestController` | `index` | missing-resource, missing-policy, missing-test |
| POST | `/QuotaRequests` | `QuotaRequestController` | `store` | missing-request, missing-resource, missing-policy, missing-test |
| POST | `/QuotaRequests/{RequestId}/Cancel` | `QuotaRequestController` | `cancel` | missing-request, missing-resource, missing-policy, missing-test |
| POST | `/Serials` | `SerialController` | `issue` | missing-request, missing-resource, missing-policy, missing-test |
| POST | `/Verify/Serial` | `VerifyController` | `serial` | missing-request, missing-resource, missing-policy, missing-test |
| POST | `/Verify/Hash` | `VerifyController` | `hash` | missing-request, missing-resource, missing-policy, missing-test |
| POST | `/Verify/Final` | `VerifyController` | `final` | missing-request, missing-resource, missing-policy, missing-test |
| GET | `/App/UpdateManifest` | `UpdateManifestController` | `__invoke` | missing-resource, missing-test |
| PUT | `/App/UpdateAssetReceiver/{UploadToken}` | `UpdateAssetReceiverController` | `__invoke` | missing-request, missing-resource, missing-test |
| MATCH | `/App/UpdateAsset/{Version}/{Platform}` | `UpdateAssetController` | `__invoke` | missing-resource, missing-test |
| MATCH | `/App/UpdateAsset/{Version}/{Platform}.sig` | `UpdateAssetSignatureController` | `__invoke` | missing-resource, missing-test |
