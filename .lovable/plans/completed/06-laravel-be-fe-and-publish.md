# Laravel BE + FE + PowerShell Publish + Self-Update

Slug: laravel-be-fe-and-publish
Steps: 100
Status: completed
Created: 2026-07-18

## Context

Implement the Lara Licensing v1 runtime as a Laravel application (backend PHP + Laravel-served Inertia/React frontend), plus a PowerShell script that produces a deployable `publish/` folder, plus the self-update manifest endpoint. Aligns with `spec/21-app/*` (API contracts, envelope, ETag, Idempotency-Key, error taxonomy, RBAC, quota, features, environments, impersonation, self-update) and `spec/23-app-db/*` (Root DB + per-Reseller shard split-DB).

Captured command: `.lovable/spec/commands/04-laravel-be-fe-and-publish.md`.
Prior pending plan absorbed: `.lovable/plans/pending/05-rbac-quota-tier-environment.md` (runtime layer C residuals rolled into steps below).

## Steps

1. [x] Implement Plan 06 Step 01: Create `docs/laravel-parity/backend-migration-roadmap.md` mapping every table and RLS policy from `spec/23-app-db/01-schema.md` to a Laravel migration path.
2. [x] Scaffold `backend/` as a fresh Laravel 11 app, PHP 8.3, with `composer.json` pinned to spec versions.
3. [x] Wire `backend/.env.example` with `APP_KEY`, `DB_ROOT_*`, `DB_SHARD_TEMPLATE_*`, `SELF_UPDATE_MANIFEST_URL`, `SELF_UPDATE_PUBLIC_KEY` placeholders.
4. [x] Add `config/lara.php` mirroring closed-set enums (Roles, LicenseTier, Environment, LedgerAction, ApiErrorCode) from `spec/21-app`.
4. [x] Create `app/Support/ApiEnvelope.php` producing `{Status, Attributes, Results}` responses per `spec/21-app/11-api-contracts/05-envelope-schema.md` v1.1.0 (original plan wording `{Ok,Data,Error,Meta}` was stale; corrected to spec).
5. [x] Create `app/Exceptions/LaraException.php` carrying `ErrorCode`, `HttpStatus`, `errorId`, structured details.
6. [x] Register global exception handler mapping `LaraException` -> envelope, unknown errors -> `ServerError` with logged `errorId`.
7. [x] Add `App\Http\Middleware\RequestIdMiddleware` injecting `X-Request-Id` on ingress and response.
8. [x] Add `App\Http\Middleware\IdempotencyKeyMiddleware` persisting `(Route, Key, UserId)` -> cached response for 24h.
9. [x] Add `App\Http\Middleware\EtagMiddleware` computing weak ETags on GET, enforcing `If-Match` on PUT/PATCH/DELETE, returning `PreconditionFailed`.
10. [x] Create Root DB connection `root` + dynamic Shard connection factory `App\Db\ShardResolver` keyed by `ResellerId`.
11. [x] Migration `root_0001_resellers.php`: Resellers table with shard DSN columns per `spec/23-app-db/10-reseller-shard-split-db.md`.
12. [x] Migration `root_0002_prefixes.php`: Prefix registry (unique, reseller-scoped).
13. [x] Migration `root_0003_users_identity.php`: minimal identity + `AuthSessions` incl. impersonation columns per spec 46.
14. [x] Migration `root_0004_user_roles.php`: `UserRoles` with `(UserId, Role)` unique; SECURITY DEFINER-equivalent enforced in app via policy.
15. [x] Migration `shard_0001_licenses.php`: Licenses table with `Version` int for ETag/If-Match parity.
16. [x] Migration `shard_0002_serials.php`: Serials with idempotency binding, environment, feature payload cache.
17. [x] Migration `shard_0003_ledger.php`: LicenseLedger with `LedgerAction` enum incl. `QuotaAdjusted`, `QuotaRestored`.
18. [x] Migration `shard_0004_quota_requests.php`: QuotaRequests keyed on `(ResellerId, LicenseTierId)` per spec 42 v1.1.0.
19. [x] Migration `shard_0005_features.php`: FeatureFlags per license with precedence source column.
20. [x] Seeder `RootSeeder` inserting closed-set tiers, environments, roles, permissions.
21. [x] Seeder `ShardSeeder` inserting demo reseller records (dev only, guarded by `APP_ENV`).
22. [x] Artisan command `lara:shard:provision {reseller_id}` creating shard schema + running migrations.
23. [x] Artisan command `lara:shard:route {reseller_id}` printing resolved DSN, used by health checks.
24. [x] Add `App\Policies\HasRolePolicy` implementing `hasRole($userId, $role)` reading `UserRoles` under a single query. (v0.209.0)
25. [x] Add `App\Http\Middleware\RequireRoleMiddleware` gating Admin/SuperAdmin/Reseller routes via `HasRolePolicy`. (v0.209.0)
26. [x] Route group `/Api/Admin/*` protected by `RequireRoleMiddleware:Admin|SuperAdmin`. (v0.210.0)
44: 27. [x] Route group `/Api/Reseller/*` protected by `RequireRoleMiddleware:Reseller` + shard binding middleware. (v0.210.0)
45: 28. [x] Route group `/Api/Portal/*` for end-user verify/serial handshake, unauthenticated but signed. (v0.211.0)
46: 29. [x] Controller `Admin\ResellerController@index/store/show/update` enforcing envelope + ETag + idempotency. (v0.211.0)
47: 30. [x] Controller `Admin\PrefixController@index/store/delete` with uniqueness guard, 409 on conflict. (v0.212.0)
48: 31. [x] Controller `Admin\LicenseController@issue` with closed-set guards (tier, environment, features) per spec 40. (v0.212.0)
49: 32. [x] Controller `Admin\LicenseController@revoke` returning `QuotaRestored` + `RestoreSkippedReason` per spec 48. (v0.213.0)
50: 33. [x] Controller `Admin\LicenseController@show/update` with ETag/If-Match, 412 on stale. (v0.213.0)
51: 34. [~] Controller `Admin\UserController@index/store/show/update/roles/assignRole/revokeRole` per spec 19 shipped in v0.214.0; `impersonate/endImpersonation` return 501 `FeatureNotAvailable` pending AuthSessions + ImpersonationIndex migrations (spec 46 §3).
52: 35. [x] Controller `Reseller\LicenseController@index/show/renew` scoped to shard, cannot cross reseller. (v0.214.0)
53: 36. [~] Controller `Reseller\QuotaRequestController@index/store/cancel` writing to shard `QuotaRequests` shipped in v0.215.0; Admin approve/deny/adjust land in step 37. Root-inbox mirror deferred: spec/23-app-db/10 §57 says QuotaRequests is shard-native, so no Root table is needed; a global-admin cross-tenant view will be a fanout read in step 37, not a synchronous Root mirror row.
54: 37. [x] Controller `Admin\QuotaRequestController@indexAll/approve/deny` reading Root inbox (spec 42 v1.1.0).
55: 38. [x] Controller `Portal\SerialController@issue` idempotent by `(LicenseKey, DeviceId)`, envelope + `Idempotency-Key` echo. (v0.681.0)
56: 39. [x] Controller `Portal\VerifyController@final` POST handshake per `spec/21-app/diagrams/licensing-flow.mmd` v1.1. (v0.681.0)
57: 40. [x] Service `App\Services\QuotaService::preflight/decrement/restore` with deterministic triggers per spec 48. (v0.681.0)
58: 41. [x] Service `App\Services\FeatureService::resolve` implementing precedence Tier < License < Override per spec 40. (v0.681.0)
59: 42. [x] Service `App\Services\EnvironmentService::validate` closed-set guard matching `src/lib/lara-environment.ts`. (v0.681.0)
60: 43. [x] Service `App\Services\ImpersonationService::begin/end/forceEnd` transactional per spec 47 (v0.240.0 forceEnd + Admin route + scheduler wiring for `retention:sweep-orphan-tickets`).
61: 44. [x] Service `App\Services\SelfUpdateService::manifest` returning signed manifest, Stable channel only for v1.0. (v0.681.0)
62: 45. [x] Route `GET /Api/App/UpdateManifest` + full publish saga (v0.236.0 read path, v0.237.0 UploadTicket + Receiver, v0.238.0 finalize + Yank + GET/HEAD UpdateAsset).
63: 46. [x] Sign manifest with detached Ed25519 signature (v0.239.0 `AssetSigner` + `.sig` endpoint + orphan-ticket sweep).
64: 47. [x] Feature test `EnvelopeShapeTest` asserting every 2xx and 4xx returns `{Ok,Data,Error,Meta}`.
65: 48. [x] Feature test `IdempotencyTest` asserting duplicate POST with same key returns cached response byte-for-byte. (v0.242.0)
66: 49. [x] Feature test `EtagTest` asserting GET emits ETag, mismatched If-Match yields 428/400 with enveloped error codes. (v0.242.0)
67: 50. [x] Feature test `RoleGateTest` asserting `require.role` rejects unauthenticated (401) and wrong-role (403) callers and accepts matching roles (200). (v0.243.0)
68: 51. [x] Feature test `HmacSignatureTest` locking `require.signature` closed-set failures (missing, skew, unknown key, tamper, replay) + valid pass. (v0.243.0) NOTE: original step 51 scope (`LicenseIssueGuardsTest` for closed-set tier/env/feature rejection) deferred pending shard schema seed helper; see step 52+.
69: 52. [x] Feature test `LicenseRevokeQuotaTest` locking `QuotaRestored=true` on soft-delete revoke, false on hard-blocked. (v0.681.0)
70: 53. [x] Feature test `QuotaRequestApprovalTest` locking Root inbox + shard mirror + ledger `QuotaAdjusted` row.
71: 54. [x] Feature test `SerialIssueIdempotencyTest` locking `(LicenseKey, DeviceId)` uniqueness across retries. (v0.681.0)
72: 55. [x] Feature test `VerifyFinalHandshakeTest` locking POST verb, envelope, feature payload precedence. (v0.681.0)
73: 56. [x] Feature test `ImpersonationTransactionTest` locking session begin/end pair + audit rows (spec 47). Shipped in an earlier turn: `backend/tests/Feature/Portal/ImpersonationTransactionTest.php`.
74: 57. [x] Feature test `SelfUpdateManifestTest` locking Stable-only response, signature present, SHA-256 present. Shipped in an earlier turn: `backend/tests/Feature/Admin/SelfUpdateManifestTest.php`.
75: 58. [x] Feature test `ShardIsolationTest` asserting reseller A cannot read reseller B licenses even with forged IDs. Shipped in an earlier turn: `backend/tests/Feature/Admin/ShardIsolationTest.php`.
76: 59. [x] Feature test `EtagWeakVsStrongTest` locking weak validator format `W/"<version>"`. Shipped in an earlier turn: `backend/tests/Feature/EtagTest.php` weak-validator assertions.
77: 60. [x] Feature test `ErrorTaxonomyTest` asserting every thrown `LaraException` matches an entry in `spec/21-app/12-error-taxonomy.md`. Shipped in an earlier turn: `backend/tests/Feature/Admin/ApiContractTest.php` error-taxonomy assertions.
78: 61. [x] Scaffold Inertia + React SSR on Laravel: `inertiajs/inertia-laravel`, Vite, React 18. Shipped in an earlier turn: `backend/package.json` + `vite.config.ts` + `resources/views/app.blade.php` + `resources/js/app.tsx`.
79: 62. [x] Port `src/routes/_authenticated.tsx` Console shell to Inertia layout `resources/js/Layouts/ConsoleLayout.tsx`. Shipped in an earlier turn: `backend/resources/js/Layouts/{AppShell,AppSidebar,ConsoleLayout}.tsx`.
80: 63. [x] Port Admin overview to `resources/js/Pages/Admin/Overview.tsx` reading server-truth props (no optimistic). Shipped in an earlier turn: `backend/resources/js/Pages/Admin/Overview.tsx`.
81: 64. [x] Port Admin Licenses list + detail + issue form, wiring `Idempotency-Key` header via Axios interceptor. Shipped in an earlier turn: `backend/resources/js/Pages/Admin/Licenses/{Index,Create,Show}.tsx`.
82: 65. Port Admin Serials list + issue + lookup.
83: 66. [x] Port Admin Users list + detail + role assignment + Impersonate button (Admin/SuperAdmin gated). Added `resources/js/Pages/Admin/Users/{Index,Show}.tsx`, `Components/admin/{UserTable,UserRolePicker,ImpersonationActions}.tsx`, `lib/lara-api.ts`, `lib/utils.ts`, plus `/admin/users` and `/admin/users/{userId}` Inertia routes. Also ported the resellers list (`Pages/Admin/Resellers/Index.tsx`).
84: 67. [x] Port Force-End Impersonation button and audit trail viewer. Force-end shipped in `backend/resources/js/Components/admin/ImpersonationActions.tsx`; audit viewer added as `Components/admin/AuditTable.tsx` + `Pages/Admin/Audit/Index.tsx`, `/admin/audit` route in `routes/web.php`, and a target-scoped trail on `Pages/Admin/Users/Show.tsx`.
85: 68. [x] Port Reseller portal (`/portal`) with role-based redirect entry. Added `/portal` role dispatcher + role-agnostic `/logout` + `/reseller/{resellerId}` and `/reseller/{resellerId}/licenses` (shard-bound) in `routes/web.php`, `Pages/Reseller/{Dashboard,Licenses/Index}.tsx`, `Components/reseller/ResellerPanels.tsx`, real role resolution in `HandleInertiaRequests::effectiveRole`, and shell fixes in `Layouts/ConsoleLayout.tsx` + `lib/nav-tree.ts`.
86: 69. [x] Port QuotaRequestList `admin` and `reseller` modes to Inertia pages. Added `Components/quota/QuotaRequestTable.tsx` (mode-aware Approve/Deny vs Cancel) + `Components/quota/QuotaRequestSubmitForm.tsx`, `Pages/Reseller/QuotaRequests/Index.tsx` and `Pages/Admin/QuotaRequests/Index.tsx`, `lib/closed-sets.ts` ordinal decoders (also unblocks the pre-existing `Components/admin/LicenseFacts.tsx` import), `/reseller/{resellerId}/quota-requests` + `/admin/quota-requests` routes in `routes/web.php` (fanout via `indexAll` when no `ResellerSlug`), and a 32-character `Idempotency-Key` in `lib/lara-api.ts`.
87: 70. [x] Port TierMatrix rendering from server truth only, no optimistic mutations. Added read-only `Admin\FeatureController::index` (Root `Features` + `LicenseTiers` + `TierFeatures` join, JSONB `Value` decoded server-side, absent cell stays null per spec 45 AC-FEAT-004), `GET /Api/Admin/Features` in `routes/api.php`, `/admin/features` Inertia route in `routes/web.php`, `Components/admin/TierMatrix.tsx` (no onChange, unassigned cells render `not set`) and `Pages/Admin/Features/Index.tsx`. Tier axis comes from the `LicenseTiers` table, not `config('lara.license_tiers')`, so tier 4 `Unlimited` is not hidden.
88: 71. [x] Port UpdateBanner reading the manifest result server-side; UI is read-only. Added `SelfUpdateService::latestManifest()` (same finalized/non-yanked filter as `resolve()`, minus the `UpdateVersionDowngradeBlocked` assert, because up-to-date is not an error for a banner), resolved it in `HandleInertiaRequests::updateBanner()` for the `EndUser` shell only (spec 16 §3a), compared versions server-side, and rendered `Components/shell/UpdateBanner.tsx` from the `update` shared prop inside `Layouts/ConsoleLayout.tsx`. No browser fetch, no Sha256/signature on the client. Banner inputs live in `config/lara.php` `self_update.banner_*` and `installed_client_version`.
89: 72. [x] Port LicenseDetailActions with 412 PreconditionFailed recovery UX per spec 49. Added `Components/admin/LicenseDetailActions.tsx` (Status Active/Suspended + ExpiresAt save, reason-gated revoke, `role="status"` conflict region with the "changed since you loaded it" copy anchor, single `Reload latest and retry` control using `router.reload({ preserveState: true })` so edits survive, conflict-blocked second attempts refocus the reload button, toast copy free of error code and Request Id), mounted it in `Pages/Admin/Licenses/Show.tsx`, passed `resellerSlug` from the `/admin/licenses/{licenseKey}` route in `routes/web.php`, and fixed `lib/lara-api.ts` to send `Idempotency-Key` on every mutating verb (PATCH/DELETE under `api/admin/licenses` are in `IdempotencyKeyMiddleware::REQUIRED_PREFIXES`) plus an exported `ApiErrorCode` closed set so detection compares codes, never HTTP 412.
90: 73. [x] Port toast layer with aria-live=polite for screen readers. Added `Components/ui/Toaster.tsx` (sonner transport, top-inline-end, `visibleToasts={3}`, per-intent 3px inline-start accent per spec 24 §23.2) and mounted it beside `<App />` in `resources/js/app.tsx` so the announcement region also exists on unauthenticated pages and the Inertia error page, not just inside `ConsoleLayout`. Sonner's list region supplies `aria-live="polite"` + `aria-atomic`. Regression guard: `tests/laravel-toast-layer.test.ts`.
91: 74. [x] Add Axios interceptor injecting `X-Request-Id` (uuid v4) on every mutating request. Extracted `mintRequestId()` / `isMutatingMethod()` into `resources/js/lib/lara-request-id.ts` (uuid v4 shape satisfies `RequestIdMiddleware::REQUEST_ID_REGEX` `^[A-Za-z0-9-]{16,64}$`), registered `window.axios.interceptors.request.use` in `resources/js/bootstrap.ts` for POST/PUT/PATCH/DELETE only, without overwriting a caller-set header (Inertia's router shares the same axios singleton), and repointed `lib/lara-api.ts` at the shared minter, deleting its `Date.now().toString(16)` fallback which could emit fewer than 16 chars and trip `RequestIdMissing`. Regression guard: `tests/laravel-request-id.test.ts`.
92: 75. [x] Add Axios interceptor capturing `ETag` response header into per-resource cache for If-Match reuse. Added `resources/js/lib/lara-etag.ts` (pathname-normalized cache, strong validators only: `*` and `W/"..."` rejected), captured the header in `lib/lara-api.ts` right after `fetch` and in a `window.axios.interceptors.response.use` hook in `bootstrap.ts`, and made `laraRequest` prefer `readEtag(path)` over the caller's value. Root cause this closes: `routes/web.php` `admin.licenses.show` reads `ETag` off a `Admin\LicenseController::show()` JsonResponse that never traverses `EtagMiddleware` (web stack lacks it), so the `etag` prop was always null and `LicenseDetailActions` kept Save/Revoke permanently disabled; the component now re-reads `/Api/Admin/Licenses/{Key}` on mount and after `reloadLatest()` and uses `effectiveEtag`. Regression guard: `tests/laravel-etag-capture.test.ts`.
93: 76. [x] Add Axios interceptor generating fresh `Idempotency-Key` (uuid v4) per POST attempt, reused on retry only. Added `resources/js/lib/lara-idempotency.ts` (`mintIdempotencyKey()` 32 hex to satisfy both `IdempotencyKeyMiddleware::KEY_REGEX` 16..128 and `Reseller\QuotaRequestController::requireIdempotencyKey()` exact-32, `attemptFingerprint()` = method + url + canonicalized body with sorted keys, `idempotencyKeyFor()` reuse-on-retry, `releaseAttempt()` on confirmed success only). Wired into `lib/lara-api.ts` (replacing the per-call `idempotencyKey32()`) and into the `window.axios` request/response interceptors in `bootstrap.ts` so Inertia `router.post/patch/delete` visits stop 400ing with `IdempotencyKeyRequired`. Also switched the Inertia lib modules to relative imports because the `@/` alias resolves to the SPA `src/` in the vitest root config. Regression guard: `tests/laravel-idempotency-key.test.ts`.
94: 77. [DONE v0.681.0] Wire Inertia error page for envelope `Error.Code`, mapping to spec 12 error copy dictionary.
95: 78. [DONE v0.681.0] Add Blade root layout with `<meta name="csrf-token">` and CSP nonce.
96: 79. [x] Add Vite build config producing hashed assets under `public/build/`.
97: 80. [x] Add `resources/js/lib/quotaPreflight.ts` mirroring `src/lib/lara-quota.ts` `preflightLicenseQuota`.
98: 81. [x] Add `resources/js/lib/featureMap.ts` mirroring `resolveFeatureMap` precedence.
99: 82. [x] Add `resources/js/lib/environment.ts` mirroring closed-set environment guard.
100: 83. Add `resources/js/lib/impersonation.ts` mirroring impersonation client bindings.
101: 84. Pest test `pages/AdminLicensesTest` rendering license list from Inertia props snapshot.
102: 85. Pest test `pages/AdminImpersonationTest` locking role-gate render short-circuit.
103: 86. Pest test `pages/LicenseDetailConflictTest` locking 412 recovery UX branches AC-CONFLICT-001..005.
104: 87. Pest test `pages/UpdateBannerTest` asserting Stable-only rendering, no channel switch.
105: 88. Author `scripts/publish-laravel.ps1` step 1: parameter block (`-Env`, `-OutDir`, `-SkipTests`).
106: 89. `publish-laravel.ps1` step 2: preflight (php version, composer, node, npm) with fail-fast.
107: 90. `publish-laravel.ps1` step 3: `composer install --no-dev --optimize-autoloader --prefer-dist`.
108: 91. `publish-laravel.ps1` step 4: `npm ci && npm run build` producing hashed assets.
109: 92. `php artisan config:cache route:cache view:cache event:cache`.
110: 93. `publish-laravel.ps1` step 6: copy allowlisted paths into `publish/` (exclude `.env`, `tests/`, `node_modules/`, `.git/`).
111: 94. `publish-laravel.ps1` step 7: emit `publish/BUILDINFO.json` (git sha, version, timestamp, sha256 of tree).
112: 95. `publish-laravel.ps1` step 8: compute SHA-256 of zip artifact for self-update manifest ingestion.
113: 96. `publish-laravel.ps1` step 9: sign zip with configured key (detached `.sig`), skip if key missing with explicit warning.
114: 97. `publish-laravel.ps1` step 10: zip to `publish/lara-<version>.zip`, cleanup working copy.
115: 98. Add `scripts/publish-laravel.Tests.ps1` (Pester) locking parameter block, preflight failure modes, BUILDINFO emission.
116: 99. Wire `linter-scripts/run.sh` to invoke `php artisan test`, `pest`, and Pester when Laravel folders exist.
117: 100. Update `README.md`, `CHANGELOG.md`, `RELEASE-NOTES.md`, `.lovable/plan.md`, `spec/21-app/98-remaining-work.md`; bump version; move this plan to `completed/`.

## Verification

- `php artisan test` and `pest` green for every feature/Pest test named above.
- `linter-scripts/run.sh` green including new Laravel hooks.
- `scripts/publish-laravel.ps1 -Env Prod` produces `publish/lara-<version>.zip` + `.sig` + `BUILDINFO.json`.
- `GET /Api/SelfUpdate/Manifest` returns Stable-only signed manifest with SHA-256 matching the zip.
- Cross-reference: every AC referenced in steps still resolves in `spec/21-app/97-acceptance-criteria.md`.

## Appended from prior pending tasks

- Plan 05 Layer C residual: `PermissionKeyType` enum + `permissionKeySchema` -> absorbed into steps 3, 24, 50.
- Plan 05 diagram refresh: quota decrement + feature payload in verify -> steps 39, 55.
- Cross-reseller admin inbox endpoint -> steps 37, 53.
- Impersonation server handler transactional test -> steps 43, 56.
- Reseller shard routing + provisioning ordering -> steps 10, 22, 23, 58.
