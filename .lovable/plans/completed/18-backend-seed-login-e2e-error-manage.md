# Backend parity + seed-mode demo login + e2e testing + error-manage with notifications

Slug: backend-seed-login-e2e-error-manage
Steps: 200
Status: completed
Created: 2026-07-21

## Context

Fulfills user command captured in `.lovable/spec/commands/11-backend-seed-login-e2e-error-manage.md`, driven by the reproduction in `.lovable/issues/06-admin-overview-kpis-red-error.md` (screenshot at `.lovable/spec/tasks/assets/18-backend-seed-login-e2e-error-manage/admin-overview-red-errors.png`). Full intent, scope, and acceptance criteria live in `.lovable/spec/tasks/18-backend-seed-login-e2e-error-manage.md` (referred to below as "spec-18"). Subtask index at `./subtasks/18-backend-seed-login-e2e-error-manage/README.md`.

Related pending plans that must NOT be duplicated: 05 (RBAC), 06 (Laravel BE/FE publish), 07 (UI spec conformance), 09 (Fluid UI + cPanel), 12/13/14 (Backup/Restore). This plan references them only for boundary alignment.

Normative sources: `spec/03-error-manage/`, `spec/21-app/`, `spec/23-app-db/`, `spec/28-runtime-modes/`, `spec/02-coding-guidelines/`, `.lovable/memory/standards/preview-is-primary-dev-surface.md`.

Release policy: NO version bump, NO changelog entry, NO release-notes edit, NO README pin on any intermediate step. The release ceremony fires ONLY in Step 200, after every prior step is complete and every subtask flipped to `Status: completed`.

## Appended from prior pending tasks

None absorbed. Pending Plans 05, 06, 07, 09, 12, 13, 14 remain independent.

## Steps

### Phase A - Planning + discovery for Steps 21-200 (spec-18 AC-01..AC-10)

Steps 1-20 produce NO runtime code changes. They produce planning artifacts under `docs/backend/plan-18/`, `docs/frontend/plan-18/`, `docs/testing/plan-18/`, and `docs/ci/plan-18/` that fully define what Steps 21-200 will implement. Every later phase must cite a Step 1-20 artifact as its source of truth; if an artifact is missing, the later step is blocked.

1. Dump the FE OperationId inventory (id, method, path, request shape ref, response shape ref) from `src/generated/api/operations.ts` into `docs/backend/plan-18/01-operations-inventory.md`. [DONE - captured under `docs/backend/operations-inventory.md`; copy or symlink into the plan-18 folder in Step 2.]
2. Walk `backend/routes/api.php` and every controller under `backend/app/Http/Controllers/` and produce `docs/backend/plan-18/02-backend-route-inventory.md` listing method, path, controller@action, and IMPLEMENTED / STUB / MISSING. Also relocate Step 1's inventory into `docs/backend/plan-18/01-operations-inventory.md`. [DONE - inventory covers Auth/Public/Admin/Reseller/Portal/App surfaces; only STUB row is `POST /Api/Admin/Backup/Imports`; FE `admin.metrics.kpis` flagged as MISSING for Step 3.]
3. Cross-join Steps 1 and 2 into `docs/backend/plan-18/03-parity-matrix.md`: one row per OperationId with FE-path, BE-path, status, path-case-skew, id-shape-skew, DTO-skew, owning-phase-step-range. [DONE - 26 rows: 18 MATCH, 2 MISMATCH, 7 MISSING. Row 20 `admin.metrics.kpis` confirmed as red-overview root cause with A/B fix options for Step 5. Step 1 inventory relocated to `docs/backend/plan-18/01-operations-inventory.md`.]
4. Group the parity gaps by domain (Admin.Metrics, Admin.Resellers, Admin.Users, Admin.Licenses, Admin.Serials, Admin.Features, Admin.Audit, Admin.Abuse, Admin.Quota, Admin.AppUpdates, Portal, Reseller) and record `docs/backend/plan-18/04-gap-groups.md` with a per-group step budget that sums to the backend budget in Steps 21-40. [DONE - 11 groups G1-G11, budget sums to 20 (Steps 21-40). Row-15 semantics resolved (`admin.quotas` = BE `QuotaRequests` rename). Row-20 preference recorded for Step 5: Option A (BE adds `MetricsController@kpis`). BE-only routes attributed per group with promotion candidates flagged for Step 5.]
5. Draft `docs/backend/plan-18/05-controller-skeleton-plan.md`: for every MISSING/STUB row, name the target controller file, action method, request DTO, response resource, and the exact step number in 21-40 that will create it. [DONE - row-20 ratified as Option A (`MetricsController@kpis`); 6 new actions, 1 new controller (`FeatureController`), 5 new request DTOs, 3 new resources, 3 BE-alias routes; BE-only promotions in Step 40 are codegen-only.]
6. Draft `docs/backend/plan-18/06-seeder-coverage-plan.md`: for each OperationId, list the DB rows required for a non-empty happy-path response and assign each row to a specific seeder file + step in 41-60. [DONE - factories folder empty (bootstrap in 41-45); 11 new seeder files mapped to Steps 41-55; per-tile row counts locked for `admin.metrics.kpis` (>=8 resellers, >=120 licences, >=24 quota requests, etc.).]
7. Draft `docs/backend/plan-18/07-seed-profiles-plan.md` defining the three profiles (`default`, `empty`, `error`), the env-var wiring, and which seeders each profile invokes. [DONE - `SEED_PROFILE` env dispatch defined; `default` chain has 17 seeders, `empty` has 5 (catalogs + demo identities), `error` layers `ErrorProfileSeeder` over default; `DemoIdentitiesSeeder` runs in all profiles so demo login works everywhere.]
8. Draft `docs/backend/plan-18/08-demo-login-plan.md` fixing the demo identities (admin, reseller, portal), password policy (bcrypt cost 4), storage location, and the exact claim shape emitted by `signInWithSeedIdentity`. [DONE - 3 identities locked (admin/reseller/portal @ `lara.local`, matching existing preview strings); constants split into `DemoIdentities.php` + `src/lib/demo-identities.ts`; claim envelope unions `AuthSessionResource` + `MeUser`; demo chips env-gated to prevent production bleed; role -> visible route map defined for Step 12.]
9. Draft `docs/frontend/plan-18/09-demo-login-panel-plan.md`: component tree for `<DemoLoginPanel />`, mount point, mode gate, hotkey, ARIA, and the linter rule that keeps it out of prod bundles. [DONE - panel mounts in `admin.login.tsx` under `surface-elevated`, gated by `isPreview() || seed` runtime check + `React.lazy` build gate + `DEMO_LOGIN_PANEL_MARKER` linter probe; component tree, `Shift+D Shift+D` hotkey, ARIA (`aria-labelledby`, `role=list`), no local storage; test list + Step 15 linter extension defined; identity constants split into `src/lib/demo-identities.ts` mirroring `DemoIdentities.php`.]
10. Draft `docs/frontend/plan-18/10-preview-fixture-plan.md`: enumerate every OperationId that needs a preview-fixture handler, the fixture file it lives in, and the Zod shape it must satisfy (cross-reference `src/lib/preview-fixtures/_shapes.ts`). [DONE - 26/26 OperationIds mapped to 15 existing handler files; zero new fixture files needed for Plan 18; per-profile behaviour (`default`/`empty`/`error`) defined; 5 future OperationIds (auth.session.refresh, admin.licenses.index, admin.serials.show, admin.users.destroy, admin.features.index) queued to same-step fixture rows so the `Record<OperationId, ZodTypeAny>` exhaustiveness check trips if BE codegen lands without a shape; Step 176 linter contract defined against `assertPreviewShape`.]
11. Draft `docs/backend/plan-18/11-error-manage-plan.md`: define `LaraException` hierarchy, `ErrorId` correlation contract, HTTP envelope, log sink, and mapping table from FE `LaraApiError` fields. [DONE - existing `LaraException` becomes non-final base; 6 typed subclasses (Auth/Validation/RateLimit/DomainConflict/NotFound/Internal) with factories; envelope stays backwards compatible (additive `Attributes.Category` + `Attributes.OperationId`); new `X-Error-Id` header + FE fallback parse; secondary `lara-audit-errors` NDJSON sink for admin errors screen; full BE-to-FE field mapping table locked; Steps 81-120 budget crosswalk defined.]
12. Draft `docs/frontend/plan-18/12-notification-center-plan.md`: ring-buffer size, store location, toast bridge, unread badge, route entry, keyboard access, and persistence policy. [DONE - Zustand store at `src/stores/notification-center-store.ts` with 50-entry FIFO ring; bridge lives inside existing `useAppToast` (strictly duplicative, never gating); bell in `AppShell.tsx` with `aria-live` badge; full-page route `_authenticated/notifications.tsx`; `Alt+N` hotkey via existing `CommandPalette` set; roving tabindex + "Copy correlation IDs" action; explicitly non-persisted (durable audit trail is BE `lara-audit-errors` sink from Step 11); Vitest + Playwright test list attached.]
13. Draft `docs/testing/plan-18/13-pest-test-plan.md`: one row per new backend endpoint + seeder + error path with the exact Pest file path and assertion list for Steps 121-150. [DONE - 30 Pest files mapped (13 new + 6 extended + shared fixtures + arch/drift guards) across Auth/Admin/Seed/Errors/Architecture; step-to-file table locks Steps 121-150; determinism rules (RefreshDatabase, per-class SEED_PROFILE, Carbon::setTestNow, monolog stub) recorded; row-count assertions cross-linked to Step 6 seeder coverage and Step 11 error envelope; scope boundaries vs Steps 14/15/146/176 called out.]
14. Draft `docs/testing/plan-18/14-playwright-e2e-plan.md`: enumerate the seed-mode E2E specs (login-as-demo, admin overview renders green, quota flow, abuse flow, notification center) with target seed profile per spec for Steps 151-175. [DONE - 15 new specs under `tests/e2e/specs/plan-18/` + 6 in-place extensions locked to Steps 151-175; per-spec seed profile, OperationId, and assertion checklist recorded; 2 shared helpers (`demo-login.ts`, `notification-center.ts`), 6 screenshot baselines, and a `plan-18-seed-guards.ts` fixture that fails fast on `PLAYWRIGHT_SEED_PROFILE` mismatch; CI shard matrix flagged for Step 183; scope boundaries vs Step 13 (Pest) and Step 179 (visual threshold linter) called out.]
15. Draft `docs/testing/plan-18/15-linter-plan.md`: new/updated linters (`check-endpoint-operation-parity.py`, `check-preview-in-prod-bundle.py` extension, `check-error-envelope-shape.py`) and their integration into `linter-scripts/run.sh` for Steps 176-180. [DONE - 2 new linters (`check-endpoint-operation-parity.py`, `check-error-envelope-shape.py`) + 3 extensions (preview-in-prod-bundle, forbidden-strings, preview-handler-coverage) mapped 1:1 to Steps 176-180; run.sh dispatch positions locked; waiver-file self-lint rule defined; determinism via lock.json snapshots so linters run without live BE; scope boundaries vs Plan 17 Step 42 (runtime), Step 16 (CI yaml), and follow-up visual baseline linter called out.]
16. Draft `docs/ci/plan-18/16-cicd-plan.md`: changes to `backend-e2e.yml`, `frontend-e2e.yml`, seed-profile matrix, and artifact uploads for Steps 176-185. [DONE - 5 workflow edits mapped 1:1 to Steps 181-185 (backend-e2e seed matrix + NDJSON upload; frontend-e2e split into vitest + playwright with 3×2 seed/shard matrix; coding-guidelines waiver self-lint; error-contract asserts Step 11 envelope; nightly-e2e cross-product with browsers); fail-fast=false and per-profile cache keys enforced; wall-clock and rollback gate defined against Plan 17 baseline; release.yml explicitly untouched.]
17. Draft `docs/backend/plan-18/17-risk-and-rollback.md`: rank risks (auth-shape drift, seeder perf, admin-metrics divergence, preview leaking into prod), assign mitigation to specific step numbers, and define rollback gates. [DONE - 7 risks R1-R7 ranked; each mitigation attached to specific 21-200 step numbers; 7 rollback gates G-R1..G-R7 defined with block/revert/re-open semantics; Step 200 release ceremony gated on all gates recorded as closed.]
18. Draft `docs/backend/plan-18/18-acceptance-mapping.md`: map every AC-01..AC-10 in spec-18 to the exact step(s) that satisfy it; any AC with fewer than three covering steps is a planning gap and must be re-budgeted before Step 20. [DONE - AC-01..AC-09 covered by 4-22 steps each; AC-10 (single-step release gate) closed via three-enforcement rule (Steps 20/195/200) instead of adding steps; traceability rule locked.]
19. Refresh `.lovable/plans/subtasks/18-backend-seed-login-e2e-error-manage/README.md` so each SS-01..SS-10 row lists the Step 1-18 artifact(s) it depends on and the exact step range it owns. [DONE - each SS row now cites Phase A artifact dependencies and owned step range; blocking rule + cross-cutting notes locked (row-count constants single source, waiver ownership, release-ceremony blocker).]
20. Freeze the plan: write `docs/backend/plan-18/20-plan-freeze.md` summarizing every artifact from Steps 1-19, the step-range ownership table (21-200), and a checklist confirming no later step is missing a planning source. After this step, Steps 21-200 execute exactly as scheduled; deviations require an amendment appended to `20-plan-freeze.md`. [DONE - Phase A closed; 19 artifacts indexed; step-range ownership table (21-200) locked with overlap notes; 11-row Step-200 pre-release checklist defined including migration/vitest/pest/playwright/linter gates + screenshot evidence + no-intermediate-bump audit.]

### Phase B - Seeder coverage matrix (spec-18 AC-05, AC-06)

21. Read all existing seeders in `backend/database/seeders/` and record the entities produced by each into `docs/testing/seed-mode-baseline.json` under a new `plan18` key.
22. For each operation in the inventory, list the DB rows required to return a non-empty happy-path response; add missing rows to `E2EFixturesSeeder.php`.
23. Split `E2EFixturesSeeder` into three deterministic profiles: `default` (populated), `empty` (schema present, no rows beyond closed sets), `error` (rows that trip error paths).
24. Add a `SEED_PROFILE` env var read at the top of `DatabaseSeeder.php`; branch to the correct profile seeder.
25. Add `DemoLoginSeeder.php` producing a stable admin user, reseller user, portal user with documented credentials.
26. Store the demo credentials only in `backend/database/seeders/DemoLoginSeeder.php` and `docs/testing/test-data.md`; never in `.env`.
27. Use bcrypt cost 4 for the demo password so tests run fast; document the trade-off in `docs/testing/test-data.md`.
28. Ensure the demo admin has every capability required to render `/admin` under the `default` seed (impersonation, audit read, abuse read, app-updates read).
29. Ensure the demo reseller has an active license and at least one issued serial.
30. Add `AdminMetricsSeeder` producing counts that answer `admin.metrics.overview` deterministically (resellers, sessions, licenses, quota).
31. Add `AuditWriterSeeder` producing >= 15 recent-activity rows so the dashboard's "Recent activity" list stays populated across all three profiles.
32. Add `AppUpdatesSeeder` producing at least one published `update.installed` row so the update banner has content to show.
33. Add `QuotaRequestsSeeder` producing pending, approved, rejected rows so `/admin/quota-requests` renders all badge tones.
34. Add `AbuseEventsSeeder` producing at least one `AbuseBlocked` and one `RateLimited` row for `/admin/abuse`.
35. Add `FeatureCatalogSeeder` extension covering every entry in `spec/21-app/12-feature-catalog.md`.
36. Add closed-set completeness assertion in `ClosedSetsSeeder.php` (every enum from `config/lara.php` must round-trip).
37. Add `php artisan db:seed --class=DemoLoginSeeder` to the CI `backend-e2e.yml` bootstrap.
38. Add a `MigrationsAreIdempotentTest` Pest test (deferred item from `.lovable/plan.md`) - migrate:fresh --seed twice, assert byte-identical `describe`.
39. Add `docs/testing/test-data.md` "Demo credentials" section with the exact strings devs paste at login.
40. Add a `SeederFixtureShapeTest` Pest test asserting each seeded row satisfies the Eloquent cast + policy expectations.

### Phase C - Demo-login credentials + FE seed-mode shortcut (spec-18 AC-04)

41. Read `src/routes/_authenticated.tsx` and the current login route file; document the auth flow entrypoint in the subtask SS-03 file.
42. Add a `useRuntimeMode()` gate on the login route: when `Mode === "preview"`, mount a `<DemoLoginPanel />` component beneath the credential form.
43. Implement `<DemoLoginPanel />` under `src/components/auth/DemoLoginPanel.tsx` listing the three demo accounts (admin, reseller, portal) with copy buttons.
44. Add a "Sign in as admin (seed)" button that calls a new `signInWithSeedIdentity('admin')` helper in `src/lib/preview-auth.ts`.
45. `signInWithSeedIdentity` writes a synthetic session into the same auth store the real login uses; no backend call.
46. Ensure the synthetic session carries the same claim shape as production so route gates in `_authenticated.tsx` do not diverge.
47. Add ARIA labels + hotkey `Ctrl+Shift+D` for the demo panel (per `.lovable/coding-guidelines/check-shortcut-registry.py`).
48. Add a visible chip on the login screen under seed mode: "Seed mode active - demo credentials available" (matches the toggle chip already visible in the attached screenshot).
49. Never render `<DemoLoginPanel />` in production mode - assert via `linter-scripts/check-preview-in-prod-bundle.py` extension.
50. Add unit tests `tests/preview-auth.test.ts` covering: seed sign-in, session hydration, mode flip clearing the synthetic session.
51. Add unit tests `tests/demo-login-panel.test.tsx` covering: rendered only under preview, all three identities visible, copy button writes to clipboard, hotkey activates.
52. Document the demo credentials in `docs/testing/test-data.md` (mirrors the seeder file, single source of truth is the seeder).
53. Update `src/routes/README.md` with a new "Seed-mode login" subsection.
54. Add memory note `.lovable/memory/standards/seed-mode-demo-login.md` describing the invariant "seed mode never blocks the reviewer".
55. Register the new memory file in `.lovable/memory/index.md`.
56. Add `data-testid="demo-login-admin"` etc. so Playwright can drive the panel.
57. Add `<DemoLoginPanel />` story in `src/stories/` (if Storybook present) or a preview route otherwise.
58. Ensure `<DemoLoginPanel />` survives SSR (no `window` at import time; read runtime mode via `useHydrated()`).
59. Verify no hydration mismatch by grepping SSR HTML for the panel markup after preview toggle.
60. Screenshot the login page under seed mode into `docs/ui-baselines/plan18-login-seed.png`; reference from spec-18 acceptance evidence.

### Phase D - Preview-fixture handlers for admin KPIs and lists (spec-18 AC-05, AC-06)

61. Enumerate every operation currently missing from `src/lib/preview-fixtures/` and record in `docs/backend/preview-fixture-gap.md`. [DONE - identified 7 gaps in Auth, Impersonation, and RuntimeConfig]
61a. Implement `password-reset` handlers in `src/lib/preview-fixtures/password-reset.ts`. [DONE]
61b. Fix type errors in `src/lib/preview-fixtures/impersonation.ts` and `src/lib/preview-fixtures/runtime-config.ts` where `ctx.Params` was accessed without casting. [DONE]
61c. Implement `admin.licenses.show` preview handler in `src/lib/preview-fixtures/licenses.ts`. [DONE]
62. Add `preview-fixtures/admin.metrics.overview.ts` returning deterministic counts matching `AdminMetricsSeeder`. [DONE]
63. Register the handler in `preview-transport.ts` dispatch table.
64. Add Zod entry in `_shapes.ts` for `admin.metrics.overview`; regenerate the exhaustive-coverage map.
65. Add `preview-fixtures/admin.resellers.list.ts` returning >= 5 seeded resellers. [DONE]
105: 66. Add `preview-fixtures/admin.users.list.ts` returning >= 8 seeded users across roles. [DONE]
106: 67. Add `preview-fixtures/admin.licenses.list.ts` returning >= 6 licenses across statuses. [DONE]
107: 68. Add `preview-fixtures/admin.serials.list.ts` returning >= 4 serials. [DONE]
108: 69. Add `preview-fixtures/admin.features.list.ts` returning the full seeded feature catalog. [DONE]

70. Add `preview-fixtures/admin.abuse.list.ts` returning at least one `AbuseBlocked` row.
71. Add `preview-fixtures/admin.quotaRequests.list.ts` returning pending/approved/rejected rows.
72. Add `preview-fixtures/admin.appUpdates.list.ts` returning at least one installed update.
73. Add corresponding shape entries in `_shapes.ts` for every fixture added in steps 65-72.
74. Add `empty` variants under `preview-fixtures/empty/*.ts` returning `Results: []` for each list operation.
75. Add `error` variants under `preview-fixtures/error/*.ts` returning `LaraApiError(ServerError, 500)` with a deterministic ErrorId.
76. Route the profile switch through `runtime-mode` seed selector (default / empty / error).
77. Add `tests/preview-fixtures-admin-metrics.test.ts` covering all three profiles.
78. Add `tests/preview-fixtures-admin-lists.test.ts` covering every list operation across the three profiles.
79. Run `bunx vitest run` and add a summary row to `docs/testing/coverage-matrix.md`.
80. Confirm `/admin` under `default` seed shows zero "Something failed" copy (manual Playwright screenshot into `docs/ui-baselines/plan18-admin-default.png`).

### Phase E - Error-manage backend (spec-18 AC-07, AC-08, AC-09; spec/03-error-manage)

81. Read `spec/03-error-manage/02-error-architecture/06-apperror-package/01-apperror-reference/00-overview.md` and every sibling file; summarize the mandated shape into `docs/backend/error-manage-overview.md`.
82. Confirm `backend/app/Exceptions/LaraException.php` exposes `errorCode`, `errorId`, `requestId`, `details`; extend if missing.
83. Ensure every `throw` in `backend/app/` carries an `ErrorCode` present in `config/lara.php::error_codes`.
84. Add PHPStan rule (or a grep-based linter under `linters/phpcs/`) that fails on `throw new \Exception(` in `app/`.
85. Wire `bootstrap/app.php` exception renderer to always emit the canonical envelope `{Status, Attributes: {ErrorId, RequestId, Code, Details?}, Results: []}`.
86. Ensure 5xx always populates `Attributes.ErrorId`, 4xx always populates `Attributes.Details` when field-level.
87. Confirm `RequestIdMiddleware` (or add one) reads `X-Request-Id` from the request or generates ULID; attach to every response.
88. Add `ErrorIdMiddleware` (or extend renderer) generating ULID `ErrorId` for every 5xx path.
89. Configure the `errors` channel in `backend/config/logging.php` with structured JSON output (ErrorId, RequestId, code, route, userId, IP).
90. Add `LogsUnhandledExceptions` listener writing to the `errors` channel on every LaraException.
91. Ensure server stack traces stay server-side; renderer must NEVER include `trace` in the client envelope.
92. Add `AdminErrorFeed` service reading last N `errors` channel entries for the future notification bell.
93. Expose `GET /Api/Admin/Errors` for admins only, returning `Results: ErrorEntry[]` (redacted trace omitted).
94. Add closed-set enum `ErrorCode` mirroring `ApiErrorCodeType` in TS.
95. Add `scripts/check-error-code-parity.mjs` guard: fail if BE set != FE set.
96. Add Pest test `ErrorEnvelopeTest.php` asserting every controlled failure path returns the canonical envelope.
97. Add Pest test `ErrorIdCorrelationTest.php` asserting `errors` log entry ErrorId matches response `Attributes.ErrorId`.
98. Add Pest test `RequestIdPropagationTest.php` asserting client-provided `X-Request-Id` echoes into the envelope + log.
99. Add Pest test `Never5xxLeaksTraceTest.php` asserting `trace` never appears in responses.
100. Add feature test for `GET /Api/Admin/Errors` gated by admin capability.

### Phase F - Error-manage frontend + notification center (spec-18 AC-07)

101. Read `src/lib/lara-api-error.ts`, `src/lib/error-store.ts`, `src/lib/lara-fetch.ts`; document the current capture flow in subtask SS-06.
102. Extend `error-store.ts` with a bounded ring buffer (last 50 errors) keyed by `ErrorId`.
103. Persist the ring buffer to `sessionStorage` under `lara.error-history.v1` so a full-page refresh keeps recent entries.
104. Add `useErrorFeed()` hook returning the current buffer + unread count.
105. Add `<NotificationBell />` under `src/components/notifications/NotificationBell.tsx` reading from `useErrorFeed()`.
106. Add `<NotificationDrawer />` opening from the bell, listing entries with copyable ErrorId + RequestId + OperationId + timestamp + code.
107. Mount the bell in the admin top bar next to the impersonation banner.
108. Ensure the bell increments on every `LaraApiError` pushed into the store (already routed via `laraFetch`).
109. Ensure 5xx pushes ALSO fire a toast via `use-lara-error-toast.ts` (do not duplicate; both paths read the same store).
110. Add a "Copy ErrorId" button in the drawer using `navigator.clipboard.writeText`.
111. Add `data-testid="notification-bell"` and `data-testid="notification-entry-<index>"` for Playwright.
112. Add `docs/testing/coverage-matrix.md` row for the notification center.
113. Add unit tests `tests/notification-bell.test.tsx` (renders count, badge tone, click opens drawer).
114. Add unit tests `tests/notification-drawer.test.tsx` (renders entries, copy button, empty state).
115. Add unit tests `tests/error-store-buffer.test.ts` (ring buffer capacity, session persistence, dedupe by ErrorId).
116. Wire the drawer to also list backend-fetched entries via `GET /Api/Admin/Errors` when the current user has admin capability.
117. Merge server + client entries by ErrorId in the drawer; server entries take precedence.
118. Add a filter dropdown by `ApiErrorCodeType` in the drawer.
119. Confirm the notification center itself never fails hard: if the fetch to `/Api/Admin/Errors` errors, the drawer still renders client-side entries.
120. Update `.lovable/memory/index.md` with a pointer to the new notification standard file `.lovable/memory/standards/error-notification-center.md`.

### Phase G - Backend Pest tests (spec-18 AC-02)

121. Add `backend/tests/Feature/Admin/MetricsOverviewTest.php` asserting `admin.metrics.overview` returns seeded counts under the `default` profile.
122. Add `backend/tests/Feature/Admin/MetricsOverviewEmptyTest.php` asserting the `empty` profile returns zeros without erroring.
123. Add `backend/tests/Feature/Admin/ResellersListTest.php` (default profile).
124. Add `backend/tests/Feature/Admin/UsersListTest.php` (default profile).
125. Add `backend/tests/Feature/Admin/LicensesListTest.php` (default profile).
126. Add `backend/tests/Feature/Admin/SerialsListTest.php` (default profile).
127. Add `backend/tests/Feature/Admin/FeaturesListTest.php` (default profile).
128. Add `backend/tests/Feature/Admin/AuditListTest.php` covering filters (EventType, ActorUserId, Since/Until, cursor pagination).
129. Add `backend/tests/Feature/Admin/AbuseListTest.php`.
130. Add `backend/tests/Feature/Admin/QuotaRequestsListTest.php` covering all status tones.
131. Add `backend/tests/Feature/Admin/AppUpdatesListTest.php`.
132. Add `backend/tests/Feature/Auth/DemoLoginNotAvailableInProductionTest.php` asserting the seeded demo user does not exist when `APP_ENV=production`.
133. Add `backend/tests/Feature/Auth/DemoAdminCanReachEveryAdminRouteTest.php` walking every route in `route:list` with the admin token.
134. Add `backend/tests/Feature/Errors/ErrorEnvelopeParityTest.php` (extends step 96 with route-level coverage).
135. Add `backend/tests/Feature/Errors/ClosedSetErrorCodesTest.php` reading `config/lara.php` and asserting FE lock file parity via file read.
136. Add `backend/tests/Feature/Portal/PortalHomeTest.php`.
137. Add `backend/tests/Feature/Reseller/ResellerIndexTest.php`.
138. Add a Pest-level assertion helper `assertEnvelope(Response, Status, Code?)` in `backend/tests/Support/`.
139. Run `backend/vendor/bin/pest --group=plan18` and capture the report to `backend/tests/Reports/plan18.junit.xml`.
140. Wire the plan18 group into `phpunit.xml` `<groups>`.

### Phase H - Playwright e2e across three seeds (spec-18 AC-03)

141. Add `tests/e2e/admin-overview-default.spec.ts` asserting four green KPI tiles + no "Something failed" text on `/admin` under the `default` seed.
142. Add `tests/e2e/admin-overview-empty.spec.ts` asserting empty-state copy (no red errors) under the `empty` seed.
143. Add `tests/e2e/admin-overview-error.spec.ts` asserting the error tiles surface a copyable ErrorId under the `error` seed.
144. Add `tests/e2e/demo-login-admin.spec.ts` clicking the demo-login button and reaching `/admin` without a backend.
145. Add `tests/e2e/demo-login-reseller.spec.ts` reaching `/reseller/{id}`.
146. Add `tests/e2e/demo-login-portal.spec.ts` reaching `/portal/home`.
147. Add `tests/e2e/admin-resellers-list.spec.ts` under `default` seed.
148. Add `tests/e2e/admin-users-list.spec.ts` under `default` seed.
149. Add `tests/e2e/admin-licenses-list.spec.ts` under `default` seed.
150. Add `tests/e2e/admin-serials-list.spec.ts` under `default` seed.
151. Add `tests/e2e/admin-features-list.spec.ts` under `default` seed.
152. Add `tests/e2e/admin-audit-filters.spec.ts` verifying EventType + ActorUserId + cursor pagination.
153. Add `tests/e2e/admin-abuse-list.spec.ts` under `default` seed.
154. Add `tests/e2e/admin-quota-requests.spec.ts` verifying all three status badges render.
155. Add `tests/e2e/admin-app-updates.spec.ts` under `default` seed.
156. Add `tests/e2e/notification-center.spec.ts` forcing a synthetic 5xx and asserting the bell increments + drawer shows the ErrorId.
157. Add `tests/e2e/error-seed-shows-errorid.spec.ts` forcing the `error` seed and asserting every list surface shows a copyable ErrorId.
158. Add `playwright.config.ts` project matrix `{ default, empty, error }` reading `SEED_PROFILE`.
159. Add screenshot artifacts under `docs/ui-baselines/plan18/` on Playwright green.
160. Update `docs/testing/coverage-matrix.md` E2E table with all specs added in steps 141-157.

### Phase I - CI/CD workflows + linters (spec-18 AC-01, AC-02, AC-03, AC-06, AC-09)

161. Extend `.github/workflows/backend-e2e.yml` to run migrations + `DemoLoginSeeder` + `E2EFixturesSeeder` before Pest.
162. Add a `backend-error-envelope` job running only `ErrorEnvelopeParityTest` and `Never5xxLeaksTraceTest` for fast feedback.
163. Add `.github/workflows/frontend-e2e.yml` running Playwright with all three `SEED_PROFILE` projects.
164. Cache Playwright browsers between runs; pin the Playwright version to match `package.json`.
165. Add `.github/workflows/error-contract.yml` running `scripts/check-error-code-parity.mjs` on every PR.
166. Extend `.github/workflows/coding-guidelines.yml` to run `check-endpoint-operation-parity.py` (step 11).
167. Add `linter-scripts/check-preview-handler-coverage.py` that fails when an OperationId lacks a preview handler AND a shape entry.
168. Wire the linter into `linter-scripts/run.sh`.
169. Add a workflow annotation extractor `linter-scripts/emit-github-annotations.py` for the coding-guidelines run so PR reviewers see line-precise failures.
170. Fail CI if `docs/testing/coverage-matrix.md` is stale (add `linter-scripts/check-coverage-matrix-freshness.py`).
171. Fail CI if the plan18 spec files exist but the plan file references a missing `XX-<slug>.md` (`check-plan-spec-crossrefs.py`).
172. Add a `preview-in-prod-bundle` step ensuring `<DemoLoginPanel />` never ships to a production build (extend existing `check-preview-in-prod-bundle.py`).
173. Add a `release-smoke` job dependency on `frontend-e2e` + `backend-e2e` + `error-contract`; block release without all three green.
174. Add a `plan18-final-gate` workflow that runs only after all subtasks flip to `Status: completed` (checks the file with `rg -c 'Status: completed' .lovable/plans/subtasks/18-...`).
175. Add `.github/workflows/plan18-final-gate.yml` invoking `linter-scripts/check-plan18-completion.py`.
176. Write `linter-scripts/check-plan18-completion.py` verifying every step of this plan has a matching artifact in the repo.
177. Add `docs/testing/README.md` "Plan 18 CI matrix" section.
178. Update `.lovable/cicd-index.md` with the new workflows.
179. Confirm every new workflow uses the same matrix definitions to avoid drift (single source in `.github/workflows/_matrix.yml`).
180. Run every new workflow once via `act` or manual PR; capture green run URLs in `docs/testing/plan-18-ci-baseline.md`.

### Phase J - Docs, verification, and final release ceremony (spec-18 AC-10)

181. Rewrite `docs/testing/coverage-matrix.md` "Plan 18 delta" section to reflect final counts (BE feature tests, FE unit tests, Playwright specs).
182. Update `docs/backend/frontend-transport-gap-report.md` and mark every gap RESOLVED with a link to the plan step that closed it.
183. Update `docs/backend/admin-runtime-runbook.md` with a "Seed-mode demo login" section pointing at `docs/testing/test-data.md`.
184. Update `.lovable/plan.md` M5/M6 progress lines with the closed items from Plan 18.
185. Update `.lovable/memory/index.md` with the two new standards (seed-mode demo login, error notification center).
186. Update `README.md` (root) "Testing" section with the new demo credentials pointer (no version bump yet).
187. Update `AGENTS.md` with a one-line note that seed-mode demo login is the primary manual QA surface.
188. Take an "after" screenshot of `/admin` under the `default` seed and save to `.lovable/spec/tasks/assets/18-backend-seed-login-e2e-error-manage/admin-overview-after.png`.
189. Diff before/after screenshots and record the comparison in the spec-18 acceptance evidence appendix.
190. Confirm every subtask under `.lovable/plans/subtasks/18-backend-seed-login-e2e-error-manage/` reads `Status: completed`.
191. Run the full test matrix: `bunx vitest run`, `bunx playwright test`, `backend/vendor/bin/pest`, `linter-scripts/run.sh`; capture summary into `docs/testing/plan-18-final.txt`.
192. Verify `check-error-code-parity.mjs` green.
193. Verify `check-preview-handler-coverage.py` green.
194. Verify `check-endpoint-operation-parity.py` green.
195. Verify `check-plan18-completion.py` green.
196. Move `.lovable/plans/pending/18-backend-seed-login-e2e-error-manage.md` to `.lovable/plans/completed/18-backend-seed-login-e2e-error-manage.md` and flip `Status: completed` in the same move.
197. Update `.lovable/plans/index.md` entry for slug `backend-seed-login-e2e-error-manage` to `completed` with the completion date.
198. Bump the MINOR version per `spec/11-release.md` (only touch `package.json`, `public/version.json`, and root `README.md` version pin).
199. Prepend a Plan 18 entry to `CHANGELOG.md` and `RELEASE-NOTES.md` covering the entire plan (backend parity, demo login, preview fixtures, error-manage, notification center, e2e, CI).
200. Run the release ceremony completion checks in `spec/11-release.md` and confirm all AC-01..AC-10 in spec-18 are satisfied; Plan 18 is done.

## Verification

- Phases A/D/G/H/I are self-verifying via CI workflows added within the same phase.
- Phase B seeders are verified by Phase G Pest tests + Phase H Playwright specs.
- Phase C demo login is verified by `tests/e2e/demo-login-*.spec.ts` (steps 144-146) + `tests/preview-auth.test.ts`.
- Phase E error-manage is verified by Pest tests 96-100 and the FE parity script (step 165).
- Phase F notification center is verified by tests 113-115 and Playwright step 156.
- Phase J is the human-observable delta: the "after" screenshot at step 188 must be zero-red vs the attached before screenshot.

## Appended from prior pending tasks

None (see Context).
