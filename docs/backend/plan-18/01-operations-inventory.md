# Frontend OperationId inventory

Source: `src/generated/api/operations.ts` @ v0.679.0 (Plan 18 Step 1 baseline).
Backend routes for reference: `backend/routes/api.php`.

Total operations: 26.

## Method + Path table

| # | OperationId | Method | Path (FE literal) |
|---|-------------|--------|-------------------|
| 1 | auth.login | POST | /api/auth/login |
| 2 | auth.refresh | POST | /api/auth/refresh |
| 3 | auth.logout | POST | /api/auth/logout |
| 4 | auth.me | GET | /api/auth/me |
| 5 | password-reset.request | POST | /api/password-reset/request |
| 6 | password-reset.confirm | POST | /api/password-reset/confirm |
| 7 | admin.licenses.list | GET | /api/admin/licenses |
| 8 | admin.licenses.show | GET | /api/admin/licenses/:Id |
| 9 | admin.licenses.create | POST | /api/admin/licenses |
| 10 | admin.licenses.update | PATCH | /api/admin/licenses/:Id |
| 11 | admin.licenses.delete | DELETE | /api/admin/licenses/:Id |
| 12 | admin.features.list | GET | /api/admin/features |
| 13 | portal.updates.manifest | GET | /api/portal/updates |
| 14 | portal.serials.lookup | GET | /api/portal/serials/:Serial |
| 15 | admin.quotas.list | GET | /api/admin/quotas |
| 16 | admin.quotas.update | PATCH | /api/admin/quotas/:Id |
| 17 | admin.impersonation.start | POST | /api/admin/impersonation/start |
| 18 | admin.impersonation.stop | POST | /api/admin/impersonation/stop |
| 19 | admin.audit.list | GET | /api/admin/audit |
| 20 | admin.metrics.kpis | GET | /api/admin/metrics/kpis |
| 21 | admin.users.list | GET | /api/admin/users |
| 22 | admin.users.create | POST | /api/admin/users |
| 23 | admin.users.update | PATCH | /api/admin/users/:Id |
| 24 | admin.users.delete | DELETE | /api/admin/users/:Id |
| 25 | admin.runtime-config.show | GET | /api/admin/runtime-config |
| 26 | admin.runtime-config.update | PUT | /api/admin/runtime-config |

## Domain grouping (for Plan 18 Step 5)

- Auth: 1, 2, 3, 4
- Password reset: 5, 6
- Admin.Licenses: 7-11
- Admin.Features: 12
- Portal: 13, 14
- Admin.Quotas: 15, 16
- Admin.Impersonation: 17, 18
- Admin.Audit: 19
- Admin.Metrics: 20
- Admin.Users: 21-24
- Admin.RuntimeConfig: 25, 26

## Observations (raw, no fixes here - deferred to Steps 2-10)

1. **Path-case skew.** FE literals are lowercase (`/api/admin/licenses`); backend routes are PascalCase (`/Api/Admin/Licenses`). Steps 2-4 must record whether the transport rewrites the path or whether this is a live parity break. This is the highest-risk discovery from the inventory pass.
2. **Missing FE operations vs BE surface.** Backend already exposes routes not declared in `operations.ts`, including:
   - `/Api/Admin/Resellers` (list/store/show/update)
   - `/Api/Admin/Prefixes` (list/store/destroy)
   - `/Api/Admin/Licenses/{LicenseKey}/Bindings` (index/release/clearCooldown)
   - `/Api/Admin/Licenses/{LicenseKey}/Ledger`
   - `/Api/Admin/Users/{UserId}/Roles` (list/assign/revoke) and `/Impersonate`, `/Impersonation/End`, `/Impersonation/{SessionId}/ForceEnd`
   - `/Api/Admin/QuotaRequests` and `/All`, `/Approve`, `/Deny`
   - `/Api/Admin/AppUpdates` (index/uploadTicket/publish/yank)
   - `/Api/Auth/Register`, `/Api/Auth/Captcha`, `/Api/Auth/ForgotPassword`, `/Api/Auth/ResetPassword`
   - `/Api/Public/Health`
   Plan 18 Step 2 will decide per-row whether these become new FE operations or stay backend-only.
3. **Path shape mismatch on identifiers.** FE uses `:Id` on licenses/users; BE uses `{LicenseKey}` (regex-constrained) and `{UserId}` (numeric). Step 4 will file this under "PARITY-SHAPE" in the gap report.
4. **`admin.metrics.kpis` is declared FE-side but not present in the trimmed slice of `routes/api.php` inspected in Step 1.** This is consistent with the four red KPI tiles in the attached screenshot (`.lovable/spec/tasks/assets/18-backend-seed-login-e2e-error-manage/admin-overview-red-errors.png`). Confirmation deferred to Step 2 which does the full HIT/MISS pass.

## Next

Step 2: For each of the 26 rows, `rg` the exact backend route and mark HIT / MISS in this document's follow-up gap report. This baseline stays frozen.
