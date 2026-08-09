<?php

declare(strict_types=1);

/*
 | Plan 06 steps 26 + 27 + 28 + 29.
 |
 | Route groups gated by RBAC, shard binding, or HMAC signature.
 | Controllers for the remaining Admin/Reseller/Portal surface land in
 | Plan 06 steps 30-42; this file wires the middleware skeleton plus
 | the first live Admin controller (Resellers) so end-to-end plumbing
 | (envelope + Idempotency-Key + ETag/If-Match) is provable now.
 |
 | References:
 |  - spec/21-app/04-roles.md                     (RBAC surface)
 |  - spec/23-app-db/10-reseller-shard-split-db.md (shard binding rule)
 |  - spec/21-app/11-api-contracts/09-concurrency-control.md (If-Match)
 */

use App\Http\Controllers\Admin\BindingController as AdminBindingController;
use App\Http\Controllers\Admin\LicenseController;
use App\Http\Controllers\Admin\FeatureController;
use App\Http\Controllers\Admin\PrefixController;
use App\Http\Controllers\Admin\QuotaRequestController as AdminQuotaRequestController;
use App\Http\Controllers\Admin\ResellerController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Auth\CaptchaController;
use App\Http\Controllers\Auth\ForgotPasswordController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\Auth\ResetPasswordController;
use App\Http\Controllers\Auth\MeController;
use App\Http\Controllers\Public\HealthController;

use App\Http\Controllers\Reseller\LicenseController as ResellerLicenseController;
use App\Http\Controllers\Reseller\QuotaRequestController as ResellerQuotaRequestController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Plan 06 step 43 (login substrate) + Plan 09 login modernization.
// Auth surface: unauthenticated register/login/captcha, authenticated logout.
// AuthSessions writes live in Root DB (Kind = Normal). Register is the
// bootstrap-only path that creates the first SuperAdmin; once any Root user
// exists it returns AuthRegistrationClosed (403).
Route::middleware('rate.auth:register')->post('/Auth/Register', RegisterController::class)->name('api.auth.register');
Route::middleware('rate.auth:login')->post('/Auth/Login', LoginController::class)->name('api.auth.login');
Route::middleware('rate.auth:captcha')->get('/Auth/Captcha', CaptchaController::class)->name('api.auth.captcha');
Route::middleware('rate.auth:forgot')->post('/Auth/ForgotPassword', ForgotPasswordController::class)->name('api.auth.forgot_password');
Route::middleware('rate.auth:reset')->post('/Auth/ResetPassword', ResetPasswordController::class)->name('api.auth.reset_password');

Route::middleware(['auth:sanctum', 'session.active'])->post('/Auth/Logout', LogoutController::class)->name('api.auth.logout');
Route::middleware(['auth:sanctum', 'session.active'])->get('/Me/Capabilities', [MeController::class, 'capabilities'])->name('api.me.capabilities');

// v0.300.0. Public readiness probe for cPanel deploys and load balancers.
// Unauthenticated by design (see /api/public/* handbook). Root DB ping only;
// authenticated shard status lives on Admin\MetricsController::shardStatus.
Route::get('/Public/Health', HealthController::class)->name('api.public.health');



// Admin surface: SuperAdmin + Admin. No shard binding here; Admins act
// across resellers and explicitly select tenants per request.
Route::middleware(['auth:sanctum', 'session.active', 'require.role:Admin|SuperAdmin', 'casbin.pep'])
    ->prefix('Admin')
    ->name('api.admin.')
    ->group(function (): void {
        Route::get('/Ping', function (Request $request) {
            return response()->json([
                'Ok' => true,
                'Scope' => 'Admin',
                'UserId' => (string) $request->user()->getAuthIdentifier(),
            ]);
        })->name('ping');

        // Plan 06 step 29. Reseller directory CRUD on the Root DB.
        Route::get('/Resellers', [ResellerController::class, 'index'])->name('resellers.index');
        Route::post('/Resellers', [ResellerController::class, 'store'])->name('resellers.store');
        Route::get('/Resellers/{ResellerSlug}', [ResellerController::class, 'show'])->name('resellers.show');
        Route::patch('/Resellers/{ResellerSlug}', [ResellerController::class, 'update'])->name('resellers.update');

        // Plan 06 step 70. Feature catalog + TierFeatures matrix (Root, read-only).
        Route::get('/Features', [FeatureController::class, 'index'])->name('features.index');

        // Plan 06 step 30. Prefix registry on the Root DB.
        Route::get('/Prefixes', [PrefixController::class, 'index'])->name('prefixes.index');
        Route::post('/Prefixes', [PrefixController::class, 'store'])->name('prefixes.store');
        Route::delete('/Prefixes/{PrefixValue}', [PrefixController::class, 'destroy'])->name('prefixes.destroy');

        // Plan 06 steps 31-33. License CRUD on the reseller shard. Show/update/revoke
        // require ?ResellerSlug=... so the caller selects the shard explicitly.
        Route::post('/Licenses', [LicenseController::class, 'issue'])->name('licenses.issue');
        Route::get('/Licenses/{LicenseKey}', [LicenseController::class, 'show'])
            ->where('LicenseKey', '[A-Z0-9-]{4,80}')->name('licenses.show');
        Route::patch('/Licenses/{LicenseKey}', [LicenseController::class, 'update'])
            ->where('LicenseKey', '[A-Z0-9-]{4,80}')->name('licenses.update');
        // Plan 09 step 58. `destroy` is the REST-symmetric alias; it delegates
        // to `revoke()` so the audit action name stays `LicenseRevoked`.
        Route::delete('/Licenses/{LicenseKey}', [LicenseController::class, 'destroy'])
            ->where('LicenseKey', '[A-Z0-9-]{4,80}')->name('licenses.revoke');
        // Plan 06 step 48. LicenseLedger read (shard-scoped via ?ResellerSlug).
        Route::get('/Licenses/{LicenseKey}/Ledger', [LicenseController::class, 'ledger'])
            ->where('LicenseKey', '[A-Z0-9-]{4,80}')->name('licenses.ledger');

        // Plan 06 step 39 (admin bindings). Spec 30 §"Admin operations".
        // Shard bound via ?ResellerSlug=..., same pattern as other Admin routes.
        Route::get('/Licenses/{LicenseKey}/Bindings', [AdminBindingController::class, 'index'])
            ->where('LicenseKey', '[A-Z0-9-]{4,80}')->name('licenses.bindings.index');
        Route::post('/Licenses/{LicenseKey}/Bindings/{MachineBindingId}/Release', [AdminBindingController::class, 'release'])
            ->where(['LicenseKey' => '[A-Z0-9-]{4,80}', 'MachineBindingId' => '[0-9]+'])
            ->name('licenses.bindings.release');
        Route::post('/Licenses/{LicenseKey}/Bindings/{MachineBindingId}/ClearCooldown', [AdminBindingController::class, 'clearCooldown'])
            ->where(['LicenseKey' => '[A-Z0-9-]{4,80}', 'MachineBindingId' => '[0-9]+'])
            ->name('licenses.bindings.clear_cooldown');

        // Plan 06 step 34. User directory + role assignment on Root DB.
        Route::get('/Users', [UserController::class, 'index'])->name('users.index');
        Route::post('/Users', [UserController::class, 'store'])->name('users.store');
        Route::get('/Users/{UserId}', [UserController::class, 'show'])
            ->where('UserId', '[0-9]+')->name('users.show');
        Route::patch('/Users/{UserId}', [UserController::class, 'update'])
            ->where('UserId', '[0-9]+')->name('users.update');
        Route::get('/Users/{UserId}/Roles', [UserController::class, 'listRoles'])
            ->where('UserId', '[0-9]+')->name('users.roles.index');
        Route::post('/Users/{UserId}/Roles', [UserController::class, 'assignRole'])
            ->where('UserId', '[0-9]+')->name('users.roles.assign');
        Route::delete('/Users/{UserId}/Roles/{RoleName}', [UserController::class, 'revokeRole'])
            ->where(['UserId' => '[0-9]+', 'RoleName' => '[A-Za-z]{2,32}'])
            ->name('users.roles.revoke');
        Route::post('/Users/{UserId}/Impersonate', [UserController::class, 'impersonate'])
            ->where('UserId', '[0-9]+')->name('users.impersonate');
        Route::post('/Impersonation/End', [UserController::class, 'endImpersonation'])
            ->name('impersonation.end');
        // Plan 06 step 43 (forceEnd). Admin-only termination of any active
        // impersonation session by SessionId (spec 47 §2 AdminForced).
        Route::post('/Impersonation/{SessionId}/ForceEnd', [UserController::class, 'forceEndImpersonation'])
            ->where('SessionId', '[0-9a-fA-F-]{36}')
            ->name('impersonation.force_end');


        // Plan 06 step 37. Admin-scoped quota-request decision surface.
        // Shard is selected explicitly via ?ResellerSlug=... on each call.
        Route::get('/QuotaRequests', [AdminQuotaRequestController::class, 'index'])
            ->name('quota_requests.index');
        // Cross-tenant fanout inbox (spec 42 v1.1.0 §"Admin inbox").
        Route::get('/QuotaRequests/All', [AdminQuotaRequestController::class, 'indexAll'])
            ->name('quota_requests.index_all');
        Route::post('/QuotaRequests/{RequestId}/Approve', [AdminQuotaRequestController::class, 'approve'])
            ->where('RequestId', '[0-9]+')->name('quota_requests.approve');
        Route::post('/QuotaRequests/{RequestId}/Deny', [AdminQuotaRequestController::class, 'deny'])
            ->where('RequestId', '[0-9]+')->name('quota_requests.deny');

        // Plan 06 step 45. Self-update publish saga.
        // Phase 1 (v0.237.0): UploadTicket. Phase 3a/3c (v0.238.0):
        // finalize + yank. Idempotency-Key required (see
        // IdempotencyKeyMiddleware REQUIRED_PREFIXES `api/admin/appupdates`).
        Route::get('/AppUpdates', [\App\Http\Controllers\Admin\AppUpdateController::class, 'index'])
            ->name('app_updates.index');
        Route::post('/AppUpdates/UploadTicket', [\App\Http\Controllers\Admin\AppUpdateController::class, 'uploadTicket'])
            ->name('app_updates.upload_ticket');
        Route::post('/AppUpdates', [\App\Http\Controllers\Admin\AppUpdateController::class, 'publish'])
            ->name('app_updates.publish');
        Route::post('/AppUpdates/{Version}/Yank', [\App\Http\Controllers\Admin\AppUpdateController::class, 'yank'])
            ->where('Version', '[0-9]+\.[0-9]+\.[0-9]+([-+][0-9A-Za-z.\-]+)?')
            ->name('app_updates.yank');

        // Plan 09 step 29. Dashboard KPI aggregator (root counts + shard fanout).
        Route::get('/Metrics', [\App\Http\Controllers\Admin\MetricsController::class, 'index'])
            ->name('metrics.index');

        // Plan 09 step 31. Per-shard reachability probe for the dashboard "Recheck now" control.
        Route::get('/Metrics/ShardStatus', [\App\Http\Controllers\Admin\MetricsController::class, 'shardStatus'])
            ->name('metrics.shardStatus');

        // Plan 09 step 23. Read-only viewer over Root AuditLogs. Filters by
        // Action/TargetType/ActorId; ordered CreatedAt DESC; Limit<=500.
        Route::get('/AuditLogs', [\App\Http\Controllers\Admin\AuditController::class, 'index'])
            ->name('audit.index');

        // v0.298.0. Auth session listing per user + admin-forced revoke by SessionId.
        Route::get('/Users/{UserId}/Sessions', [\App\Http\Controllers\Admin\SessionController::class, 'index'])
            ->where('UserId', '[0-9]+')->name('users.sessions.index');
        Route::delete('/Sessions/{SessionId}', [\App\Http\Controllers\Admin\SessionController::class, 'destroy'])
            ->where('SessionId', '[0-9a-fA-F-]{36}')->name('sessions.destroy');

        // Plan 14 step 9 (v0.614.0). Backup/Restore Export endpoint (S1 shadow).
        // spec/26-backup-restore/11-endpoint-export.md. Idempotency-Key required
        // (see IdempotencyKeyMiddleware REQUIRED_PREFIXES `api/admin/backup/exports`).
        Route::post('/Backup/Exports', [\App\Http\Controllers\Admin\BrExportController::class, 'store'])
            ->name('backup.exports.store');

        // Plan 14 step 27 (v0.676.0). Backup/Restore Import endpoint (S1 shadow,
        // verifyOnly slice). spec/26-backup-restore/12-endpoint-import.md.
        // Idempotency-Key required (REQUIRED_PREFIXES `api/admin/backup/imports`).
        // verifyAndApply is rejected 400 ValidationFailed until a follow-up step
        // lands the apply-phase job dispatch.
        Route::post('/Backup/Imports', [\App\Http\Controllers\Admin\BrImportController::class, 'store'])
            ->name('backup.imports.store');

        // Plan 16 step 58 (v0.563.0). Runtime-config admin surface for
        // repo-root `version.json`. `show` returns ETag over UpdatedAt;
        // `update` enforces If-Match, atomic write, and SuperAdmin RBAC.
        // Contract: spec/28-runtime-modes/05-admin-runtime-toggle.md.
        Route::get('/RuntimeConfig', [\App\Http\Controllers\Admin\RuntimeConfigController::class, 'show'])
            ->name('runtime_config.show');
        Route::put('/RuntimeConfig', [\App\Http\Controllers\Admin\RuntimeConfigController::class, 'update'])
            ->name('runtime_config.update');

        // Plan 18 Step 102. Admin Errors tail endpoint.
        Route::get('/Errors', [\App\Http\Controllers\Admin\ErrorController::class, 'index'])
            ->name('errors.index');
    });


// Reseller surface: Reseller role + automatic shard binding by TenantId.
Route::middleware(['auth:sanctum', 'session.active', 'require.role:Reseller', \App\Http\Middleware\ShardBindingMiddleware::class])
    ->prefix('Reseller')
    ->name('api.reseller.')
    ->group(function (): void {
        Route::get('/Ping', function (Request $request) {
            return response()->json([
                'Ok' => true,
                'Scope' => 'Reseller',
                'ResellerSlug' => (string) $request->attributes->get('ResellerSlug', ''),
            ]);
        })->name('ping');

        // Plan 06 step 35. Reseller-scoped License surface (shard-bound).
        Route::get('/Licenses', [ResellerLicenseController::class, 'index'])->name('licenses.index');
        // Plan 06 step 35b. Reseller-scoped License minting.
        Route::post('/Licenses', [ResellerLicenseController::class, 'issue'])->name('licenses.issue');
        Route::get('/Licenses/{LicenseKey}', [ResellerLicenseController::class, 'show'])
            ->where('LicenseKey', '[A-Z0-9-]{4,80}')->name('licenses.show');
        Route::patch('/Licenses/{LicenseKey}/Renew', [ResellerLicenseController::class, 'renew'])
            ->where('LicenseKey', '[A-Z0-9-]{4,80}')->name('licenses.renew');
        // Plan 06 step 48. LicenseLedger read (shard already bound).
        Route::get('/Licenses/{LicenseKey}/Ledger', [ResellerLicenseController::class, 'ledger'])
            ->where('LicenseKey', '[A-Z0-9-]{4,80}')->name('licenses.ledger');

        // Plan 06 step 36. Reseller-scoped quota-request workflow (shard-bound).
        // Approve/Deny live on the Admin surface per spec 42 §Endpoints.
        Route::get('/QuotaRequests', [ResellerQuotaRequestController::class, 'index'])
            ->name('quota_requests.index');
        Route::post('/QuotaRequests', [ResellerQuotaRequestController::class, 'store'])
            ->name('quota_requests.store');
        Route::post('/QuotaRequests/{RequestId}/Cancel', [ResellerQuotaRequestController::class, 'cancel'])
            ->where('RequestId', '[0-9]+')->name('quota_requests.cancel');
    });

// Plan 06 step 28. Portal surface: unauthenticated but HMAC-signed.
// No auth guard, no shard binding; individual controllers derive the
// target reseller from the signed KeyId or from request payload.
Route::middleware(['require.signature'])
    ->prefix('Portal')
    ->name('api.portal.')
    ->group(function (): void {
        Route::get('/Ping', function (Request $request) {
            return response()->json([
                'Ok' => true,
                'Scope' => 'Portal',
                'KeyId' => (string) $request->attributes->get('lara.signature.key_id', ''),
            ]);
        })->name('ping');

        // Plan 06 step 38. End-user device requests a Serial for a known
        // LicenseKey. HMAC-signed by SignedRequestMiddleware. Idempotent
        // by UNIQUE(LicenseId, DeviceIdHash) at the DB layer.
        Route::post('/Serials', [\App\Http\Controllers\Portal\SerialController::class, 'issue'])
            ->name('serials.issue');

        // Plan 06 step 39a. First of three verify handshake endpoints.
        // 39c (/Verify/Final) lands after TierFeatures (Plan 06 step 41)
        // and MachineBindings/UserBindings substrates.
        Route::post('/Verify/Serial', [\App\Http\Controllers\Portal\VerifyController::class, 'serial'])
            ->name('verify.serial');

        // Plan 06 step 39b. Second verify handshake endpoint. Mints a
        // single-use VerifyKey bound to SHA-256(HashKey) with 5-minute
        // TTL. MachineBindings persistence deferred to a later Plan 06
        // step; see VerifyController::hash() docblock for scope boundary.
        Route::post('/Verify/Hash', [\App\Http\Controllers\Portal\VerifyController::class, 'hash'])
            ->name('verify.hash');

        // Plan 06 step 39c. Third and final verify handshake endpoint.
        // Consumes the VerifyKey inside a shard transaction, runs the
        // environment gate INSIDE the same transaction per spec 03
        // §Transaction boundary, resolves the Features map via
        // FeatureService (LicenseFeatures > TierFeatures precedence),
        // and returns {IsAuthorized, LicenseId, EnvironmentId,
        // LicenseTierId, Features, AuthorizedAt, ExpiresAt}. Machine /
        // User binding writes remain deferred until the shard binding
        // tables land; response fields stay null (spec-legal, optional).
        Route::post('/Verify/Final', [\App\Http\Controllers\Portal\VerifyController::class, 'final'])
            ->name('verify.final');
    });

// Plan 06 steps 44 + 45. Self-update manifest read path per
// spec/21-app/17-self-update-endpoint.md v1.3.0. Stable is anonymous
// (no auth guard). Beta is reserved and rejected in-controller with
// AuthzRoleDenied until the AppBuilder|Admin gate lands post-v1.0.
Route::get('/App/UpdateManifest', \App\Http\Controllers\App\UpdateManifestController::class)
    ->name('app.update_manifest');

// Plan 06 step 45. Self-update publish saga, phase 2 of 3. PUT receiver
// authenticated by the opaque `UploadToken` in the path (single-use,
// TTL-scoped). No Sanctum guard: the token IS the bearer credential.
Route::put('/App/UpdateAssetReceiver/{UploadToken}', \App\Http\Controllers\App\UpdateAssetReceiverController::class)
    ->where('UploadToken', '[a-f0-9]{64}')
    ->name('app.update_asset_receiver');

// Plan 06 step 45 (v0.238.0). Self-update byte-serving endpoint per
// spec 17 v1.3.0 §"HEAD /App/UpdateAsset/{Version}/{Platform}" and
// §"GET". Anonymous on Stable channel (v1.0). Only finalised and
// non-yanked rows visible; missing rows return UpdateAssetNotFound.
Route::match(['GET', 'HEAD'], '/App/UpdateAsset/{Version}/{Platform}', \App\Http\Controllers\App\UpdateAssetController::class)
    ->where(['Version' => '[0-9]+\.[0-9]+\.[0-9]+([-+][0-9A-Za-z.\-]+)?', 'Platform' => '[A-Za-z0-9]+'])
    ->name('app.update_asset');

// Plan 06 step 46 (v0.239.0). Ed25519 detached-signature endpoint per
// spec 17 v1.3.0 §"Signature verification" + §"Response 200" SignatureUrl.
// Anonymous on Stable channel (v1.0). Only finalised, non-yanked rows
// with a persisted signature blob are visible; otherwise 404
// UpdateSignatureUnavailable.
Route::match(['GET', 'HEAD'], '/App/UpdateAsset/{Version}/{Platform}.sig', \App\Http\Controllers\App\UpdateAssetSignatureController::class)
    ->where(['Version' => '[0-9]+\.[0-9]+\.[0-9]+([-+][0-9A-Za-z.\-]+)?', 'Platform' => '[A-Za-z0-9]+'])
    ->name('app.update_asset_signature');


