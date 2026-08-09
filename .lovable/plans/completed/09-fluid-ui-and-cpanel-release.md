# Fluid UI Buildout, Backend Gap Sweep, and cPanel Release Automation

Slug: fluid-ui-and-cpanel-release
Steps: 100
Status: completed
Created: 2026-07-19

## Context

Deliver a modern, fluid Lovable-previewable UI for Lara Licensing V1 that connects to every Laravel endpoint already implemented, close the remaining backend gaps, and wire a `run.ps1` + GitHub Actions release pipeline that emits two cPanel-ready zips (`frontend-vX.Y.Z.zip`, `backend-vX.Y.Z.zip`) per tagged release. Typography is fixed: **Ubuntu** for headings, **Poppins** for body. Stack stays TanStack Start + React 19 + Tailwind v4 (no Next.js), loaded per the fonts-via-`<link>` rule in the root route head.

Captured inputs:
- Command: `.lovable/spec/commands/05-fluid-ui-fonts-and-cpanel-release.md`
- Command (prior, still binding): `.lovable/spec/commands/04-laravel-be-fe-and-publish.md`

Prior pending plans folded into scope (see "Appended from prior pending tasks"):
- `.lovable/plans/pending/05-rbac-quota-tier-environment.md` (remaining UI slices)
- `.lovable/plans/pending/06-laravel-be-fe-and-publish.md` (remaining backend + publish steps)
- `.lovable/plans/pending/07-ui-spec-conformance-and-code-finetune.md` (remaining UI-spec conformance)

## Steps

1. Audit `backend/routes/api.php` and controllers; produce `.lovable/plans/subtasks/09-fluid-ui-and-cpanel-release/SS-01-backend-gap-report.md` listing every endpoint's implementation status, missing FormRequest/Policy/Resource wiring, and untested branches.
2. Audit `src/lib/lara-*.ts` transports against the backend endpoint inventory from step 1; flag any endpoint that has no typed client function.
3. Confirm Ubuntu + Poppins are the only two allowed families; add `<link rel="preconnect">` + `<link rel="stylesheet">` for both to `src/routes/__root.tsx` head.
4. Register `--font-display: "Ubuntu", system-ui, sans-serif;` and `--font-sans: "Poppins", system-ui, sans-serif;` in `src/styles.css` `@theme` block; remove any prior font tokens.
5. Add a Vitest guard `tests/typography-tokens.test.ts` asserting the two families are the only `--font-*` tokens.
6. Apply `font-display` to every `h1..h6`, `PageHeader` title slot, and `AppShell` brand mark via a base rule in `src/styles.css`; body defaults to `font-sans`.
7. Add ESLint rule / linter script `linter-scripts/check-heading-font.py` that fails when a heading element inline-overrides `font-*`.
8. Establish OKLCH color scale for the "fluid modern" direction in `@theme`: primary, accent, surface, surface-elevated, surface-inset, border-subtle, focus-ring; document in `.lovable/memory/design/fluid-palette.md`.
9. Define motion tokens (`--motion-duration-*`, `--motion-ease-*`) already present in Spec 24; verify parity and add missing "spring-lg" preset used by fluid hero surfaces.
10. Define spacing scale extensions (`--space-fluid-*`) using `clamp()` for hero and section rhythm; wire Tailwind utilities via `@utility`.
11. Ship a landing route refit at `src/routes/index.tsx` using the fluid palette + Ubuntu headings + Poppins body; hero, feature grid, CTA, footer.
12. Add `src/components/landing/HeroSection.tsx` with `useReducedMotion` guard and layered gradient surfaces.
13. Add `src/components/landing/FeatureGrid.tsx` reading from a typed catalog module `src/lib/landing-features.ts` (no magic strings).
14. Add `src/components/landing/CtaFooter.tsx` with role-aware sign-in CTA (Admin/Reseller/Portal).
15. Refit `src/components/shell/AppShell.tsx` for fluid density: sidebar 260px, collapsed 72px, topbar 56px, main gutter `clamp(1rem, 2.5vw, 2rem)`.
16. Refit `src/components/shell/AppSidebar.tsx` nav item styling (Poppins 14px, active indicator, focus ring), preserving nav-tree source of truth.
17. Refit `src/components/shell/PageHeader.tsx` to use Ubuntu 28/32 for `title`, Poppins 14 muted for `description`, and slot the `PageActions` portal.
18. Refit `src/components/shell/Breadcrumbs.tsx` with truncation, `aria-current`, and Poppins 13.
19. Refit `src/components/shell/CommandPalette.tsx` visuals: glass surface, shortcut hints via `formatShortcut`, empty state via copy.ts.
20. Ship `src/components/shell/TopbarSearch.tsx` (global search input bound to `FocusGlobalSearch` shortcut).
21. Ship `src/components/shell/UserMenu.tsx` with `ImpersonationBanner` awareness and sign-out.
22. Refit `src/components/ui/button.tsx` fluid variants: `solid`, `soft`, `ghost`, `outline`, `link`; intents: `primary`, `neutral`, `success`, `warning`, `danger`.
23. Refit `src/components/ui/card.tsx` with `surface`, `surface-elevated`, `surface-inset` variants and hover elevation token.
24. Refit `src/components/ui/data-table.tsx` for sticky header, zebra optional, empty/loading/error slots wired to `route-states`.
25. Ship `src/components/ui/pagination.tsx` (server-side) consumed by DataTable.
26. Ship `src/components/ui/filter-bar.tsx` (chip filters, date range, saved views placeholder).
27. Ship `src/components/ui/stat-card.tsx` for admin dashboard tiles (KPI + delta + sparkline slot).
28. Ship `src/components/ui/timeline.tsx` for audit/impersonation history views.
29. Refit `src/routes/_authenticated/admin.index.tsx` into a fluid dashboard: stat cards (Licenses, Resellers, Active Sessions, Pending Quota Requests) + recent activity timeline.
30. Wire the dashboard KPIs to real endpoints via new `src/lib/lara-admin-metrics.ts` transport.
31. Add backend `Admin\MetricsController@index` returning the four KPIs via a `MetricResource`; register route under `admin/metrics`.
32. Add PHPUnit test `AdminMetricsTest.php` for RBAC + envelope + counts.
33. Refit `src/routes/_authenticated/admin.resellers.tsx` list with DataTable, filter-bar, and row actions.
34. Refit `admin.resellers.$resellerId.tsx` detail: identity card, prefixes panel, licenses panel, activity timeline.
35. Refit `admin.resellers.new.tsx` as a stepper (Identity, Prefixes, Confirm) using new `src/components/ui/stepper.tsx`.
36. Refit `admin.users.tsx` list with role filter chips, impersonation action gated by `callerRole`.
37. Refit `admin.users.$userId.tsx` detail: profile, role picker, sessions timeline, last-admin guard preserved.
38. Refit `admin.serials.tsx` with issue-form drawer + lookup panel; reuse `SerialIssueForm`.
39. Refit `admin.licenses.new.tsx` as a stepper (Reseller, Tier, Features, Environments, Confirm) with quota preflight.
40. Refit `admin.licenses.$licenseId.tsx` detail: envelope card, features table, environments table, ledger timeline, revoke action with `If-Match` conflict UI.
41. Ship `src/routes/_authenticated/admin.updates.tsx` for self-update manifest management: upload asset, publish, yank, per-version status.
42. Add `src/lib/lara-app-updates.ts` transport for the manifest / upload-ticket / publish / yank endpoints.
43. Ship `src/routes/_authenticated/admin.audit.tsx` audit log viewer with server-side pagination, filter by actor/action/entity, JSON diff drawer.
44. Add `Admin\AuditController@index` (paginated, filterable) + `AuditResource` + PHPUnit coverage.
45. Refit `reseller.$resellerId.quota-requests.tsx` list + submit form for the fluid direction.
46. Ship `src/routes/_authenticated/reseller.$resellerId.index.tsx` reseller dashboard (stat cards + recent licenses + pending quota requests).
47. Ship `src/routes/_authenticated/reseller.$resellerId.licenses.tsx` list with reseller-scoped filters and issue action.
48. Ship `src/routes/_authenticated/reseller.$resellerId.licenses.$licenseId.tsx` detail (reseller-scoped view; hides admin-only actions).
49. Ship `src/routes/_authenticated/portal.tsx` end-user portal home (serial lookup, my licenses, download update).
50. Wire portal serial lookup to `Portal\SerialController` via `src/lib/lara-serial.ts`.
51. Wire portal update download to `SelfUpdateController` streaming asset endpoint.
52. Ship `src/routes/admin.login.tsx` refit: Ubuntu display headline, Poppins form, error surface via `useAppToast`.
53. Ship `src/routes/register.tsx` (public first-user bootstrap flow) that calls `Auth\RegisterController` and redirects to `/admin` on SuperAdmin bootstrap.
54. Ship `src/components/auth/AuthCard.tsx` shared layered surface used by login/register/reset.
55. Ship `src/routes/forgot-password.tsx` + backend `Auth\PasswordResetController` (Sanctum + Laravel password broker).
56. Ship `src/routes/reset-password.$token.tsx` with token validation and success state.
57. Add PHPUnit `PasswordResetTest.php` covering broker, throttling, and audit lineage.
58. Add `Admin\LicenseController@destroy` alias to revoke for REST symmetry; keep audit action name.
59. Backfill missing FormRequest classes flagged in step 1; ensure every mutation endpoint uses a dedicated FormRequest.
60. Backfill missing Policy classes flagged in step 1; wire via `AuthServiceProvider` with `Gate::policy`.
61. Backfill missing API Resources flagged in step 1; ensure PascalCase JSON keys and envelope compliance.
62. Add OpenAPI/Swagger generation via `darkaonline/l5-swagger`; annotate every controller action; expose `/api/documentation` behind Admin RBAC.
63. Add `linter-scripts/check-swagger-parity.py` to fail CI when a route lacks an OpenAPI annotation.
64. Ship `src/routes/_authenticated/admin.api-docs.tsx` iframe embedding the Swagger UI, gated by Admin role.
65. Add global 404 route `src/routes/$catchall.tsx` with fluid design and back-to-home CTA.
66. Add global error boundary refit in `__root.tsx` `errorComponent` using fluid `StateCard`.
67. Add Playwright smoke `tests/e2e/fluid-shell.spec.ts` for landing, login, admin dashboard render.
68. Add Playwright smoke for reseller dashboard and portal serial lookup.
69. Add Vitest visual-regression-lite: snapshot the computed CSS custom properties on `:root` so token drift fails a test.
70. Update `README.md` Typography section: Ubuntu (headings), Poppins (body), with `<link>` snippet and rationale.
71. Update `.lovable/memory/design/fluid-palette.md` with final OKLCH values, contrast ratios, and semantic mapping.
72. Create `scripts/publish-frontend.ps1` that runs `bun install --frozen-lockfile`, `bun run build`, copies `dist/` + `.htaccess` into `release/frontend/`, and zips to `release/frontend-v<version>.zip`.
73. Author the cPanel-ready `.htaccess` (SPA fallback + gzip + cache headers) at `scripts/cpanel/.htaccess` used by step 72.
74. Extend `scripts/publish-laravel.ps1` (from command 04) to emit `release/backend-v<version>.zip` including `vendor/`, cached configs, `.env.example`, and a `PUBLISH-NOTES.md`.
75. Create root `run.ps1` orchestrator: reads version from `package.json`, invokes both publish scripts in parallel-safe order, verifies both zips exist, prints SHA-256.
76. Add `run.sh` shell parity script for Linux release runners (calls the same underlying build steps).
77. Add `.github/workflows/release.yml` triggered on `v*` tags: runs `pwsh run.ps1`, uploads both zips as Release assets, computes checksums.
78. Add `.github/workflows/frontend-build.yml` PR gate: `bun install`, `tsgo`, `bun run build`, Vitest.
79. Add `.github/workflows/backend-build.yml` PR gate: composer install, PHPStan max, Pest suite, migration dry-run.
80. Add `.github/workflows/release-smoke.yml` post-release job that unzips both artifacts into a scratch dir and runs a health-check curl against a booted preview.
81. Document cPanel upload runbook at `docs/deploy/cpanel-runbook.md` (upload zip, extract, set document root, run `php artisan migrate --force`).
82. Add `docs/deploy/environment-matrix.md` mapping `.env` keys to cPanel setup (DB creds, mail, storage path).
83. Add `docs/deploy/rollback.md` describing zip-based rollback (keep previous two release zips on the server).
84. Bump versions in `package.json`, `backend/composer.json` `version` field, `README.md`, `CHANGELOG.md`, `RELEASE-NOTES.md` to the next minor when this plan starts execution.
85. Add `linter-scripts/check-version-sync.py` to fail CI when the four version sources drift.
86. Verify `run.ps1` on a clean checkout via `pwsh -NoProfile -File run.ps1` in the release-smoke workflow.
87. Verify `frontend-v<version>.zip` unzips to a valid SPA (index.html + assets + .htaccess) via a Playwright headless serve.
88. Verify `backend-v<version>.zip` unzips to a runnable Laravel app (`php artisan about` succeeds) via the workflow.
89. Add `preview_ui--set_preview_device_viewport` snapshots for desktop + tablet + mobile of landing, admin dashboard, license detail.
90. Add responsive audit checklist to `.lovable/memory/design/fluid-palette.md` (breakpoints, container queries, focus-visible).
91. Add `useAppToast` copy coverage test ensuring every error code from `backend/config/lara.php` has a UI copy entry in `src/lib/copy.ts`.
92. Add `linter-scripts/check-copy-coverage.py` enforcing step 91 in CI.
93. Add `src/components/ui/skeleton.tsx` fluid variants and wire into every list/detail loading state.
94. Add `src/components/ui/empty-state.tsx` illustrations slot per Spec 24 §26; register two illustrations under `src/assets/illustrations/`.
95. Wire empty-state into every list route (resellers, users, licenses, serials, quota requests, audit, updates).
96. Add Storybook-lite gallery route `src/routes/_authenticated/admin._design.tsx` (Admin-only) showcasing every primitive; behind a `VITE_LARA_DESIGN_GALLERY=1` gate.
97. Run `bun run build` + `tsgo` + Vitest + Pest + PHPStan; fix any regressions surfaced.
98. Move this plan file to `.lovable/plans/completed/09-fluid-ui-and-cpanel-release.md` and flip `Status:` to `completed`.
99. Archive superseded prior plans (`05`, `06`, `07`) into `.lovable/plans/completed/` with `Status: completed` after their remaining scope is folded here and delivered.
100. Publish to Lovable preview, then cut the git tag `vX.Y.0` and confirm the release workflow uploads both cPanel zips.

## Verification

- Build: `bun run build` succeeds; `tsgo` clean; `bun run test` green.
- Backend: `composer test`, `vendor/bin/phpstan analyse --memory-limit=1G`, `php artisan migrate --pretend` all green.
- CI: Both PR workflows green; release workflow produces `frontend-v<version>.zip` + `backend-v<version>.zip` + `checksums.txt` as Release assets.
- Preview: Lovable preview renders landing, login, admin dashboard, reseller dashboard, portal with Ubuntu headings and Poppins body confirmed via computed-style snapshot.
- Deploy: `docs/deploy/cpanel-runbook.md` walkthrough succeeds on a scratch cPanel account.
- Playwright: `fluid-shell.spec.ts` and reseller/portal smokes pass headless.

## Appended from prior pending tasks

- `.lovable/plans/pending/05-rbac-quota-tier-environment.md` — remaining UI slices for quota, tiers, environments are absorbed into steps 29-51.
- `.lovable/plans/pending/06-laravel-be-fe-and-publish.md` — remaining backend controllers, publish PowerShell, and CI wiring are absorbed into steps 1-2, 31-32, 44, 58-63, 72-80.
- `.lovable/plans/pending/07-ui-spec-conformance-and-code-finetune.md` — remaining UI-spec conformance items are absorbed into steps 3-28, 65-66, 89-95.
- Step 99 archives these three plan files after their scope is delivered here.

## Step ledger (reconciled 2026-08-06, revision 2)

Revision 1 of this ledger was wrong: it matched artifacts by the exact filenames
written in the Steps list, so nine shipped deliverables that live under different
names read as gaps. Revision 2 was produced by grepping content, not filenames.

Current state: 90 of 100 steps shipped, 10 open.

Renames accepted (step is DONE, filename differs):

| Step | Plan filename | Shipped as |
|------|---------------|------------|
| 5 | `tests/typography-tokens.test.ts` | `tests/font-tokens-closed-set.test.ts` |
| 7 | `linter-scripts/check-heading-font.py` | `linter-scripts/check-heading-fonts.py` (wired at `linter-scripts/run.sh:140`) |
| 21 | `src/components/shell/UserMenu.tsx` | `src/components/shell/ProfileMenu.tsx` |
| 30 | `src/lib/lara-admin-metrics.ts` | `src/lib/lara-metrics.ts` |
| 41 | `admin.updates.tsx` | `admin.app-updates.tsx` |
| 48 | `...licenses.$licenseId.tsx` | `...licenses.$licenseKey.tsx` |
| 55/56 | `Auth\PasswordResetController` | `ForgotPasswordController.php` + `ResetPasswordController.php` |
| 57 | `PasswordResetTest.php` | `PasswordRecoveryFlowTest.php` |
| 65 | `src/routes/$catchall.tsx` | `src/routes/$.tsx` |
| 71 / 8 / 90 | `.lovable/memory/design/fluid-palette.md` | `.lovable/memory/style/fluid-palette.md` |
| 74 | `scripts/publish-laravel.ps1` | `scripts/publish-backend.ps1` + `scripts/publish-lara.ps1` |
| 78 | `.github/workflows/frontend-build.yml` | `frontend-static-analysis.yml` + `frontend-e2e.yml` |
| 79 | `.github/workflows/backend-build.yml` | `backend-static-analysis.yml` + `backend-e2e.yml` |
| 81 / 83 | `docs/deploy/cpanel-runbook.md`, `docs/deploy/rollback.md` | `scripts/cpanel/DEPLOY.md` (has both the install runbook and a `## Rollback` section) |
| 96 | `admin._design.tsx` | `admin.design.tsx` |

Done 2026-08-06 (this turn):

- Step 35: `src/components/ui/stepper.tsx` shipped (presentational rail, closed
  status set, `aria-current="step"`, back-only step selection) plus
  `src/components/admin/reseller-create-wizard.tsx` (Identity -> Prefixes -> Confirm).
  `src/routes/_authenticated/admin.resellers.new.tsx` now renders the wizard;
  the superseded `src/components/admin/reseller-create-form.tsx` was deleted.

Open steps (10), in execution order:

1. Step 39 - `admin.licenses.new.tsx` is still a flat form; convert to the
   5-step wizard (Reseller, Tier, Features, Environments, Confirm) on the new
   `Stepper`. Quota preflight stays on the Confirm step.
2. Step 62 - `darkaonline/l5-swagger` is absent from `backend/composer.json`;
   no controller annotations exist. `linter-scripts/check-openapi-drift.py` is
   Plan 16 work against `src/generated/api/operations.lock.json` and does NOT
   satisfy this step.
3. Step 63 - `linter-scripts/check-swagger-parity.py` missing; depends on 62.
4. Step 64 - `src/routes/_authenticated/admin.api-docs.tsx` missing; depends on 62.
5. Step 82 - `docs/deploy/environment-matrix.md`: `scripts/cpanel/DEPLOY.md:43`
   says "fill `.env`" without enumerating the keys. Needs a full key -> cPanel
   mapping table.
6. Step 84 - version bump across `package.json`, `backend/composer.json`,
   `README.md`, `CHANGELOG.md`, `RELEASE-NOTES.md`; deferred to plan close.
7. Step 97 - full green run (build, tsgo, Vitest, Pest, PHPStan).
8. Step 98 - move this plan to `completed/`.
9. Step 99 - archive superseded plans 05, 06, 07.
10. Step 100 - publish preview, tag `vX.Y.0`, confirm the release workflow
    uploads both cPanel zips.

Known flake, not a gap: `tests/use-submit-lock.test.ts` intermittently fails to
start its Vitest forks worker ("Timeout waiting for worker to respond"). Re-run
in isolation passes 6/6. Do not "fix" it with a retry wrapper in the test.

Release policy reminder: no version bump, changelog, release-notes, or README pin
until Steps 1-97 are all done and this file leaves `pending/`.
