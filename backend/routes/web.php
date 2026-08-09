<?php

use App\Http\Controllers\Admin\AuditController;
use App\Http\Controllers\Admin\FeatureController;
use App\Http\Controllers\Admin\QuotaRequestController as AdminQuotaRequestController;
use App\Http\Controllers\Reseller\LicenseController as ResellerLicenseController;
use App\Http\Controllers\Reseller\QuotaRequestController as ResellerQuotaRequestController;
use App\Http\Controllers\Admin\MetricsController;
use App\Http\Controllers\Admin\LicenseController;
use App\Http\Controllers\Admin\ResellerController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Requests\Admin\UserIndexRequest;
use App\Models\Reseller;
use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

// Plan 06 step 68. Role-based entry. `/` and `/portal` never assume the
// caller is an Admin: the admin group is gated by
// `require.role:Admin|SuperAdmin`, so redirecting a Reseller straight to
// `admin.overview` produced a 403 dead end right after login.
Route::get('/', fn () => redirect('/portal'));

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/portal', function (Request $request) {
        $user = $request->user();
        if ($user === null) {
            return redirect('/login');
        }
        $role = (new App\Http\Middleware\HandleInertiaRequests())->share($request)['auth']['user']['RoleName'] ?? 'EndUser';
        if ($role === 'Reseller') {
            $tenantId = (int) ($user->TenantId ?? 0);
            abort_if($tenantId <= 0, 403, 'Reseller account has no tenant binding.');

            return redirect("/reseller/{$tenantId}");
        }
        if ($role === 'EndUser') {
            return redirect('/portal/home');
        }

        return redirect()->route('admin.overview');
    })->name('portal.entry');

    // Sign-out must live OUTSIDE the admin group: Resellers and EndUsers
    // hit the same control in the shell sidebar.
    Route::get('/logout', function (Request $request) {
        auth()->guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    })->name('logout');
});

// Plan 06 step 68. Reseller portal. Shard binding is done by the same
// middleware the API uses, so every read below is row-scoped to the
// caller's tenant and a cross-reseller URL cannot reach another shard.
Route::middleware(['auth:sanctum', 'require.role:Reseller', \App\Http\Middleware\ShardBindingMiddleware::class])
    ->prefix('reseller/{resellerId}')
    ->whereNumber('resellerId')
    ->group(function () {
        Route::get('/', function (ResellerQuotaRequestController $quotaRequests, ResellerLicenseController $licenses, Request $request, int $resellerId) {
            $boundResellerId = (int) $request->attributes->get('ResellerId', 0);
            abort_if($boundResellerId !== $resellerId, 403, 'URL reseller does not match the bound tenant.');

            // Plan 06 step 80: single projection shared with the quota-request
            // route so both surfaces read identical rows.
            $quotaRows = \App\Support\ResellerQuotaProjection::forReseller($boundResellerId);

            $requestRows = $quotaRequests->index($request)->getData(true)['Results'] ?? [];
            $pending = count(array_filter(
                $requestRows,
                static fn (array $row): bool => (string) ($row['Status'] ?? '') === 'Pending' || (int) ($row['StatusId'] ?? 0) === 1,
            ));
            $licenseRows = $licenses->index($request)->getData(true)['Results'] ?? [];

            $resellerName = DB::connection('root')->table('Resellers')
                ->where('ResellerId', $boundResellerId)
                ->value('ResellerName');

            return Inertia::render('Reseller/Dashboard', [
                'resellerId' => $boundResellerId,
                'resellerName' => $resellerName === null ? null : (string) $resellerName,
                'quotas' => $quotaRows,
                'pendingRequests' => $pending,
                'licenseCount' => count($licenseRows),
            ]);
        })->name('reseller.dashboard');

        Route::get('/licenses', function (ResellerLicenseController $controller, Request $request, int $resellerId) {
            $boundResellerId = (int) $request->attributes->get('ResellerId', 0);
            abort_if($boundResellerId !== $resellerId, 403, 'URL reseller does not match the bound tenant.');

            return Inertia::render('Reseller/Licenses/Index', [
                'resellerId' => $boundResellerId,
                'licenses' => $controller->index($request)->getData(true)['Results'] ?? [],
            ]);
        })->name('reseller.licenses.index');

        // Plan 06 step 69: reseller quota requests (submit / list / cancel).
        // Reseller\QuotaRequestController::index is shard-bound by the group
        // middleware, so rows never cross tenants.
        Route::get('/quota-requests', function (ResellerQuotaRequestController $controller, Request $request, int $resellerId) {
            $boundResellerId = (int) $request->attributes->get('ResellerId', 0);
            abort_if($boundResellerId !== $resellerId, 403, 'URL reseller does not match the bound tenant.');

            return Inertia::render('Reseller/QuotaRequests/Index', [
                'resellerId' => $boundResellerId,
                'requests' => $controller->index($request)->getData(true)['Results'] ?? [],
                // Plan 06 step 80: the submit form preflights against the same
                // shard rows the dashboard tiles render, so an unprovisioned
                // (category, tier) tuple never reaches the mutating endpoint.
                'quotas' => \App\Support\ResellerQuotaProjection::forReseller($boundResellerId),
            ]);
        })->name('reseller.quota-requests.index');
    });


Route::middleware(['auth:sanctum', 'require.role:Admin|SuperAdmin'])
    ->prefix('admin')
    ->group(function () {
        Route::get('/', function (App\Http\Controllers\Admin\MetricsController $controller, Request $request) {
            $response = $controller->index($request);
            $data = $response->getData(true);
            
            return Inertia::render('Admin/Overview', [
                'metrics' => $data['Results'][0] ?? [],
                'warnings' => $data['Attributes']['Warnings'] ?? [],
            ]);
        })->name('admin.overview');

        // Plan 06 step 64: Admin License Management
        Route::prefix('licenses')->group(function () {
            // Index (List) - placeholder for now as it's not yet in the BE
            Route::get('/', function () {
                return Inertia::render('Admin/Licenses/Index');
            })->name('admin.licenses.index');

            // Issue (Create)
            Route::get('/new', function () {
                return Inertia::render('Admin/Licenses/Create');
            })->name('admin.licenses.create');

            // Detail (Show)
            Route::get('/{licenseKey}', function (LicenseController $controller, Request $request, string $licenseKey) {
                // We need to fetch the license data and its ledger
                $showResponse = $controller->show($request, $licenseKey);
                $showData = $showResponse->getData(true);
                
                $ledgerResponse = $controller->ledger($request, $licenseKey);
                $ledgerData = $ledgerResponse->getData(true);
                
                // Get ETag from response headers
                $etag = $showResponse->headers->get('ETag');

                $license = $showData['Results'][0] ?? null;

                // Plan 06 step 81. `Admin\LicenseController::show()` projects
                // `Features` as `[]` (it passes an empty map to `project()`),
                // so the console has to resolve the entitlement layers itself.
                // `show()` already bound the reseller shard via ShardResolver,
                // so the default 'shard' connection is the right tenant here.
                $featureLayers = null;
                if ($license !== null && isset($license['LicenseId'])) {
                    try {
                        $featureLayers = app(\App\Services\FeatureService::class)
                            ->layers((int) $license['LicenseId']);
                    } catch (\Throwable $e) {
                        // Catalog drift or a missing shard row must not blank the
                        // whole detail page; the panel renders its own
                        // "unavailable" state from a null prop.
                        \Illuminate\Support\Facades\Log::warning('console.license.feature_layers_failed', [
                            'licenseKey' => $licenseKey,
                            'reason' => $e->getMessage(),
                        ]);
                    }
                }

                return Inertia::render('Admin/Licenses/Show', [
                    'license' => $license,
                    'ledger' => $ledgerData['Results'] ?? [],
                    'etag' => $etag,
                    'featureLayers' => $featureLayers,
                    // LicenseDetailActions must echo the same tenant selector the
                    // read used, or PATCH/DELETE bind the wrong shard.
                    'resellerSlug' => (string) $request->query('ResellerSlug', ''),
                ]);

            })->name('admin.licenses.show');
        });

        // Plan 06 step 67: Admin audit trail viewer
        Route::get('/audit', function (AuditController $controller, Request $request) {
            $data = $controller->index($request)->getData(true);

            return Inertia::render('Admin/Audit/Index', [
                'rows' => $data['Results'] ?? [],
                'filters' => [
                    'Action' => (string) $request->query('Action', ''),
                    'TargetType' => (string) $request->query('TargetType', ''),
                    'ActorId' => (string) $request->query('ActorId', ''),
                ],
                'limit' => (int) ($data['Attributes']['Limit'] ?? 100),
            ]);
        })->name('admin.audit.index');

        // Plan 06 step 69: Admin quota-request inbox.
        // No ResellerSlug -> indexAll() fanout across every active shard (each
        // row carries its own ResellerSlug, which Approve/Deny need for shard
        // binding). With a slug -> index() on that single shard, and the slug is
        // handed to the page as the row-level fallback.
        Route::get('/quota-requests', function (AdminQuotaRequestController $controller, Request $request) {
            $slug = trim((string) $request->query('ResellerSlug', ''));
            $data = $slug === ''
                ? $controller->indexAll($request)->getData(true)
                : $controller->index($request)->getData(true);
            $meta = $data['Attributes']['Meta'] ?? [];

            return Inertia::render('Admin/QuotaRequests/Index', [
                'requests' => $data['Results'] ?? [],
                'filters' => [
                    'ResellerSlug' => $slug,
                    'Status' => (string) $request->query('Status', ''),
                ],
                'warnings' => $meta['Warnings'] ?? [],
                'shardCount' => (int) ($meta['ShardCount'] ?? 0),
            ]);
        })->name('admin.quota-requests.index');

        // Plan 06 step 70: Feature/tier matrix. Read-only: no TierFeatures
        // PATCH endpoint exists, and spec 24/38 bans optimistic toggles, so
        // the page renders server truth and nothing else.
        Route::get('/features', function (FeatureController $controller, Request $request) {
            $data = $controller->index($request)->getData(true);

            return Inertia::render('Admin/Features/Index', [
                'features' => $data['Results'] ?? [],
                'tiers' => $data['Attributes']['Tiers'] ?? [],
            ]);
        })->name('admin.features.index');

        // Plan 06 step 66: Admin Reseller Management (list)
        Route::prefix('resellers')->group(function () {
            Route::get('/', function (ResellerController $controller, Request $request) {
                $response = $controller->index($request);
                $data = $response->getData(true);

                return Inertia::render('Admin/Resellers/Index', [
                    'resellers' => $data['Results'] ?? [],
                ]);
            })->name('admin.resellers.index');
        });

        // Plan 06 step 66: Admin User Management (list, detail, roles, impersonation)
        Route::prefix('users')->group(function () {
            Route::get('/', function (UserController $controller, UserIndexRequest $request) {
                $data = $controller->index($request)->getData(true);

                return Inertia::render('Admin/Users/Index', [
                    'users' => $data['Results'] ?? [],
                ]);
            })->name('admin.users.index');

            Route::get('/{userId}', function (UserController $controller, AuditController $controllerAudit, Request $request, int $userId) {
                $userData = $controller->show($request, $userId)->getData(true);
                $rolesData = $controller->listRoles($request, $userId)->getData(true);
                $roles = array_map(
                    static fn (array $row): string => (string) ($row['RoleName'] ?? ''),
                    $rolesData['Results'] ?? [],
                );

                $caller = $request->user();
                $callerRole = null;
                if ($caller !== null) {
                    $callerRoles = $controller->listRoles($request, (int) $caller->UserId)->getData(true);
                    foreach ($callerRoles['Results'] ?? [] as $row) {
                        $name = (string) ($row['RoleName'] ?? '');
                        if ($name === 'SuperAdmin' || ($name === 'Admin' && $callerRole !== 'SuperAdmin')) {
                            $callerRole = $name;
                        }
                    }
                }

                $activeSessionId = DB::connection('root')
                    ->table('ImpersonationIndex')
                    ->where('TargetUserId', $userId)
                    ->whereNull('EndedAt')
                    ->value('SessionId');

                // Plan 06 step 67: impersonation lineage for this target.
                // AuditController has no TargetId predicate, so filter the
                // TargetType=User page down to this user in the closure.
                $auditRequest = Request::create('/Api/Admin/AuditLogs', 'GET', ['TargetType' => 'User', 'Limit' => '200']);
                $auditRequest->headers->set('X-Request-Id', (string) $request->headers->get('X-Request-Id', ''));
                $auditRows = $controllerAudit->index($auditRequest)->getData(true)['Results'] ?? [];
                $auditRows = array_values(array_filter(
                    $auditRows,
                    static fn (array $row): bool => (string) ($row['TargetId'] ?? '') === (string) $userId,
                ));

                return Inertia::render('Admin/Users/Show', [
                    'user' => $userData['Results'][0] ?? null,
                    'roles' => array_values(array_filter($roles, static fn (string $r): bool => $r !== '')),
                    'callerUserId' => $caller === null ? null : (int) $caller->UserId,
                    'callerRole' => $callerRole,
                    'activeImpersonationSessionId' => $activeSessionId === null ? null : (string) $activeSessionId,
                    'auditRows' => $auditRows,
                ]);
            })->whereNumber('userId')->name('admin.users.show');
        });

    });
