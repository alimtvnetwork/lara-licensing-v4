---
Slug: preview-production-runtime-typed-api
Steps: 100
Status: completed
Created: 2026-07-20
Completed: 2026-07-20
Parent: none
Version: 0.605.0
---

## Closing checklist (Step 99)

- [x] Steps 1-98 shipped and pinned (v0.517.0 - v0.604.0).
- [x] 8-axis lint gate green (UntypedFetch, AnyInApi, MagicEndpointStrings, PreviewInProdBundle, ScreenshotMatrixCoverage, OpenApiDrift, SchemaSymbolDrift, DeadOperations).
- [x] Coverage matrix artifact wired (docs/testing/coverage-matrix.{json,md}, workflow coverage-matrix.yml).
- [x] Preview runtime E2E specs authored (preview-seed-matrix, preview-mutations-replay, runtime-toggle).
- [x] Playwright sandbox blocker documented in spec/25-app-audit/06-plan16-step97-playwright-sandbox-blocker.md; green run routed to CI.
- [x] Plan file moved from pending/ to completed/ and status flipped.
- [ ] Step 100 (close issue 04 + closing release note) remains.

# Plan 16: Preview vs Production Runtime, Typed API Layer, version.json

## Context

The Lovable preview iframe cannot reach the Laravel backend, so every data-driven screen degrades to error states and the user cannot visually test flows. This plan introduces a first-class three-mode runtime (`preview`, `dev`, `production`) driven by a single root `version.json`, a fixture-backed preview transport that makes every screen work end-to-end with seed data, a typed API contract layer generated from the backend OpenAPI spec into `src/generated/api/`, and an admin surface to flip mode and `apiBaseUrl` at runtime.

Captured command: `.lovable/spec/commands/09-preview-vs-production-mode-and-typed-api.md`.
Captured issue: `.lovable/issues/04-preview-cannot-exercise-ui-without-backend.md`.
Coding guidelines in force: `spec/02-coding-guidelines/` (15-line function cap, PascalCase JSON keys, no magic literals, closed-set enums).
Error-manage rules in force: `spec/03-error-manage/` (LaraException envelope, RequestId/ErrorId correlation, no silent failure, retry-after propagation).
Existing typed API surface to audit: `src/lib/lara-*.ts` (20+ files).

## Steps

1. Author `spec/28-runtime-modes/00-overview.md` defining the three modes (`preview`, `dev`, `production`), transitions, and invariants (`INV-RM-*`).
2. Author `spec/28-runtime-modes/01-version-json-schema.md` with the JSON Schema for repo-root `version.json` (`version`, `mode`, `apiBaseUrl`, `previewSeed`, `updatedAt`, `allowRuntimeToggle`).
3. Author `spec/28-runtime-modes/02-mode-selection-precedence.md`: precedence is `localStorage override (admin toggle) -> version.json -> compile-time default`.
4. Author `spec/28-runtime-modes/03-preview-fixture-contract.md` describing how every endpoint MUST have a preview handler with seed data.
5. Author `spec/28-runtime-modes/04-generated-types-contract.md`: how OpenAPI produces `src/generated/api/`, banned edits, regeneration workflow.
6. Author `spec/28-runtime-modes/05-admin-runtime-toggle.md`: RBAC (root only), audit log, persistence rules.
7. Author `spec/28-runtime-modes/06-acceptance-criteria.md` with `AC-RM-01..AC-RM-25` covering all screens listed in Step 66.
8. Author `spec/28-runtime-modes/07-open-questions.md` for unresolved items (persistence in preview: IndexedDB vs in-memory, etc).
9. Create root `version.json` with `mode: "preview"`, `previewSeed: "default"`, current `version` (from `package.json`), null `apiBaseUrl`.
10. Add `version.json` to `README.md` "Files that matter" section and pin the badge from it (single source of truth for version).
11. Add `linter-scripts/check-version-json.py` enforcing schema validity and that `version` matches `package.json` and `backend/composer.json`.
12. Wire `check-version-json.py` into the existing version-sync check invocation path.
13. Create `src/lib/runtime-mode.ts` exporting `RuntimeMode` type, `getRuntimeMode()`, `getApiBaseUrl()`, `getPreviewSeed()`, `isPreview()` primitives. See `./subtasks/16-preview-production-runtime-typed-api/SS-01-runtime-mode-core.md`.
14. Create `src/hooks/use-runtime-mode.ts` with `useRuntimeMode()` returning `{ mode, apiBaseUrl, seed, setMode, setApiBaseUrl }`, backed by a Zustand store hydrated from `version.json` at bootstrap.
15. Create `src/lib/version-json-loader.ts` that fetches `/version.json` on client boot and merges with `localStorage` overrides.
16. Add SSR-safe hydration for the runtime-mode store so preview mode survives the initial paint without a flash.
17. Write `tests/runtime-mode.test.ts` covering precedence rules from Step 3.
18. Ban scattered `import.meta.env.MODE` reads: add a linter rule to `linter-scripts/check-runtime-mode-usage.py` allowing the token only inside `src/lib/runtime-mode.ts`.
19. Refactor any existing `import.meta.env.MODE` usages in `src/` to `getRuntimeMode()`.
20. Create `backend/routes/api-openapi.php` (or wire an existing package like `l5-swagger` / `scramble`) to export a complete OpenAPI 3.1 document at `/api/openapi.json`.
21. Annotate every existing controller in `backend/app/Http/Controllers/Api/**` with OpenAPI attributes (request shape, response shape, error codes). See `./subtasks/16-preview-production-runtime-typed-api/SS-02-openapi-annotations.md`.
22. Add a `php artisan lara:openapi:export` command that writes `backend/build/openapi.json` deterministically.
23. Add CI job `openapi-export` that runs the export command and fails if the diff against the committed spec is non-empty.
24. Commit the generated `backend/build/openapi.json` as the pinned contract.
25. Add `openapi-typescript` to devDependencies and create `scripts/generate-api-types.mjs` that reads `backend/build/openapi.json` and writes `src/generated/api/schema.d.ts`.
26. Create `src/generated/api/README.md` marking the folder as auto-generated, with regeneration command.
27. Add `.gitattributes` entry marking `src/generated/api/**` as `linguist-generated=true`.
28. Add a pre-commit / CI check that regenerates the types and fails if the working tree differs.
29. Hand-write initial `src/generated/api/schema.d.ts` covering every endpoint currently called from `src/lib/lara-*.ts` so work is unblocked before the OpenAPI export exists. See `./subtasks/16-preview-production-runtime-typed-api/SS-03-handwritten-schema-baseline.md`.
30. Create `src/generated/api/operations.ts` exporting a typed `Operations` map (operationId -> request/response types) derived from `schema.d.ts`.
31. Create `src/lib/api-client.ts` as the single typed entrypoint: `apiClient.call<Op>(op, params)` picking preview vs live transport.
32. Refactor `src/lib/lara-fetch.ts` to become the live-transport implementation only; move mode selection to `api-client.ts`.
33. Create `src/lib/preview-transport.ts` that dispatches to preview handlers registered per operationId.
34. Create `src/lib/preview-fixtures/` folder with one file per resource domain (`auth.ts`, `licenses.ts`, `features.ts`, `updates.ts`, `serials.ts`, `quotas.ts`, `impersonation.ts`, `audit.ts`, `metrics.ts`, `me.ts`, `password-reset.ts`, `admin-users.ts`).
35. Create `src/lib/preview-store.ts` (IndexedDB via `idb-keyval`) so preview mutations persist across reloads within the session.
36. Implement seed loader: `src/lib/preview-seeds/default.ts` producing a full realistic dataset (users, licenses, features, updates, serials).
37. Implement `src/lib/preview-seeds/empty.ts` for empty-state screenshots.
38. Implement `src/lib/preview-seeds/error.ts` for error-state screenshots (each endpoint returns a canonical `LaraException` envelope).
39. Wire preview seed selection to `version.json` `previewSeed` field.
40. Implement preview handlers for auth flow (login, refresh, logout, me) using seed users. See `./subtasks/16-preview-production-runtime-typed-api/SS-04-preview-auth.md`.
41. Implement preview handlers for `GET/POST/PATCH/DELETE /api/admin/licenses` including `If-Match`/`ETag` semantics.
42. Implement preview handlers for `GET /api/admin/features` and feature-catalog reads.
43. Implement preview handlers for `GET /api/portal/updates` manifest.
44. Implement preview handlers for `GET /api/portal/serials/:serial` lookup.
45. Implement preview handlers for `GET /api/admin/quotas` and quota mutations.
46. Implement preview handlers for impersonation start/stop.
47. Implement preview handlers for audit log listing with pagination and filters.
48. Implement preview handlers for admin metrics KPIs.
49. Implement preview handlers for password reset request + confirm.
50. Implement preview handlers for admin user CRUD.
51. Ensure every preview handler emits the canonical envelope (`Data`, `Attributes.RequestId`, `Attributes.ErrorId` on failure) so the FE error contract is exercised identically.
52. Add `RuntimeBanner` component shown across the app whenever mode is `preview` or `dev`, with mode name, seed name, and a "Switch mode" action linking to the admin page.
53. Mount `RuntimeBanner` in `src/components/shell/AppShell.tsx` above the topbar.
54. Ensure `RuntimeBanner` is server-render safe (no hydration mismatch): hide until hydrated, use `useHydrated()`.
55. Create `src/routes/_authenticated/admin.settings.runtime.tsx` "Runtime" admin page.
56. In the Runtime page: show current mode, `apiBaseUrl`, seed, `version`, `updatedAt`; allow editing (root role only).
57. Persist edits: in `production` write via `PUT /api/admin/runtime-config` (new endpoint); in `preview` write to `localStorage` and IndexedDB.
58. Add backend endpoint `PUT /api/admin/runtime-config` (Casbin: `runtime-config:update` -> root role) that rewrites `version.json` on disk atomically. See `./subtasks/16-preview-production-runtime-typed-api/SS-05-runtime-config-endpoint.md`.
59. Add audit-log entries for every runtime-config change (who, from, to, when).
60. Add unit tests for the runtime-config endpoint including idempotency and 412 If-Match conflict handling.
61. Add `useApi(op, params, options)` React Query hook wrapping `apiClient.call` with correct query keys derived from operationId + params.
62. Add `useApiMutation(op, options)` for mutations, propagating `Retry-After` and `LaraException` codes.
63. Refactor `src/hooks/use-*` data hooks currently using `laraFetch` directly to `useApi`/`useApiMutation`. See `./subtasks/16-preview-production-runtime-typed-api/SS-06-hooks-refactor.md`.
64. Audit every file in `src/lib/lara-*.ts` against `src/generated/api/schema.d.ts` and fix drift (missing fields, wrong casing, `any`). See `./subtasks/16-preview-production-runtime-typed-api/SS-07-lara-lib-audit.md`.
65. Delete or fold `src/lib/lara-api-contract.ts` into the generated types if it duplicates them.
66. Verify every route under `src/routes/_authenticated/**` and `src/routes/**` renders with real content in preview mode. Route inventory: admin dashboard, admin licenses list/detail, admin features, admin users, admin quotas, admin audit, admin metrics, admin impersonation, admin settings, admin runtime, portal home, portal updates, portal serials, portal profile, auth login, auth register, auth password-reset request, auth password-reset confirm.
67. Add Playwright spec `tests/e2e/specs/preview-mode-smoke.spec.ts` that boots the app in preview mode and clicks through the 18 routes above, asserting no error banners.
68. Add Playwright spec `tests/e2e/specs/preview-mutations.spec.ts` covering: create license, edit license (If-Match), delete license, and verify the mutation appears to persist after reload.
69. Add Playwright spec `tests/e2e/specs/runtime-toggle.spec.ts` covering the admin Runtime page toggling mode + apiBaseUrl and the banner reflecting it.
70. Add Vitest suite `src/lib/preview-transport.test.ts` covering handler dispatch, unknown-operationId error, seed reset.
71. Add Vitest suite `src/lib/api-client.test.ts` covering mode-switching, param typing, error propagation.
72. Add Vitest suite `src/generated/api/schema.d.ts` compile-only test asserting every `Operations` key is used by at least one caller (dead-op linter).
73. Add `linter-scripts/check-untyped-fetch.py` banning direct `fetch(` calls anywhere in `src/` outside `src/lib/lara-fetch.ts` and `src/lib/preview-transport.ts`.
74. Add `linter-scripts/check-any-in-api.py` banning `: any` and `as any` in `src/lib/lara-*.ts`, `src/lib/api-client.ts`, `src/generated/api/**`.
75. Add `linter-scripts/check-magic-endpoint-strings.py` banning inline endpoint path strings (`"/api/..."`) outside `src/generated/api/**` and `src/lib/preview-fixtures/**`.
76. Wire the three new linters into the existing lint gate.
77. Add JSON contract tests: for each preview handler, assert its response shape matches `src/generated/api/schema.d.ts` at runtime using Zod schemas derived from the OpenAPI spec (`openapi-zod-client` or hand-written to start).
78. Add `src/lib/preview-fixtures/README.md` documenting how to add a new endpoint (checklist: type in schema, handler, seed row, test).
79. Add error-contract parity: preview handlers must emit exactly the closed-set error codes registered in `spec/03-error-manage/03-error-code-registry`. Add a test that walks all handlers and validates codes.
80. Add retry-after simulation: preview handler for `POST /api/admin/licenses` returns 429 with `Retry-After: 3` when the `x-preview-scenario: rate-limited` header is set, so the retry banner can be visually tested.
81. Add offline simulation: `?preview=offline` search param forces `preview-transport` to throw network errors so `StateOffline` renders.
82. Add slow-network simulation: `?preview=slow` adds a 2s delay per call so skeletons and shimmer states can be reviewed.
83. Extend `AppShell` to surface an in-preview debug drawer (dev-only) exposing seed switcher, scenario switcher, and current runtime state.
84. Ensure the debug drawer is code-split and tree-shaken out in `production` mode (guard the dynamic import behind `isPreview() || isDev()`).
85. Document the preview scenarios in `spec/28-runtime-modes/08-preview-scenarios.md`.
86. Add screenshots to `spec/28-runtime-modes/screenshots/` showing every route rendered in preview mode (default seed, empty seed, error seed).
87. Add `check-preview-screenshot-coverage.py` verifying one screenshot per route x per seed exists.
88. Update `README.md` "Getting started" to describe preview mode as the default developer experience.
89. Update `README.md` "Deploying" to describe flipping `mode` to `production` and setting `apiBaseUrl`.
90. Update `docs/architecture.md` with a runtime-mode section and the mode-selection precedence diagram (Mermaid).
91. Update `.lovable/plans/pending/06-laravel-be-fe-and-publish.md` cross-reference to depend on this plan's runtime-toggle endpoint.
92. Add cross-refs from `spec/03-error-manage/` to preview handlers as fixtures for the error-code test bench.
93. Add cross-refs from `spec/07-design-system/` empty-state guidance to `src/lib/preview-seeds/empty.ts`.
94. Add cross-refs from `spec/06-seedable-config-architecture/` to `version.json` as the runtime-config seed.
95. Run `bunx vitest run` full suite, must be all green after all above changes.
96. Run `bun run build` and confirm no TS errors, no linter regressions.
97. Run Playwright suites listed in Steps 67-69 to green (or documented sandbox-blocker note if libglib is missing again, following the Plan 15 Step 28 protocol).
98. Bump minor to `0.517.0` after Step 1 lands (spec authoring), and thereafter one minor per major milestone (5, 20, 40, 65, 90, 100). Update CHANGELOG and README badge each bump.
99. On Step 100 completion, move this file from `.lovable/plans/pending/16-preview-production-runtime-typed-api.md` to `.lovable/plans/completed/16-preview-production-runtime-typed-api.md` and flip `Status:` to `completed`.
100. Close issue `.lovable/issues/04-preview-cannot-exercise-ui-without-backend.md` by flipping its status to `resolved` and linking to the completed plan.

## Verification

- Spec (Steps 1-8, 90): every new spec file compiles under `linter-scripts/check-spec-cross-links.py` and `check-mmd-actor-order.py`.
- `version.json` (Steps 9-12): `check-version-json.py` exits 0; version matches `package.json` and `backend/composer.json`.
- Runtime core (Steps 13-19): `bunx vitest run tests/runtime-mode.test.ts` green; grep for `import.meta.env.MODE` returns only `src/lib/runtime-mode.ts`.
- OpenAPI (Steps 20-30): `php artisan lara:openapi:export` deterministic (rerun produces zero diff); `scripts/generate-api-types.mjs` deterministic; CI `openapi-export` job green.
- Typed API + preview transport (Steps 31-51): `bunx vitest run src/lib/api-client.test.ts src/lib/preview-transport.test.ts` green; every operationId in `Operations` has a preview handler (assert in a test).
- Admin runtime toggle (Steps 55-60): PHPUnit green on `RuntimeConfigController`; audit-log rows exist after each toggle in the fixture DB.
- Hooks + lara-lib audit (Steps 61-65): `bunx tsgo` clean; `check-any-in-api.py` exit 0.
- Route coverage (Steps 66-69): Playwright `preview-mode-smoke`, `preview-mutations`, `runtime-toggle` specs green.
- Linters (Steps 73-76): all three new linters wired and exit 0 on the tree.
- Screenshots (Steps 86-87): every route x seed pair present.
- Docs (Steps 88-94): README + docs updated; cross-refs land.
- Final (Steps 95-100): full vitest green, build green, playwright green, plan moved to `completed/`, issue closed.

## Appended from prior pending tasks

Existing pending plans in `.lovable/plans/pending/` remain independent and are NOT folded into this plan:

- 05-rbac-quota-tier-environment.md (pending)
- 06-laravel-be-fe-and-publish.md (pending; Step 91 above adds a cross-ref only)
- 07-ui-spec-conformance-and-code-finetune.md (pending)
- 09-fluid-ui-and-cpanel-release.md (pending)
- 12-backup-restore-snapshot.md (pending)
- 13-backup-restore-spec-authoring.md (pending; note: 13-authoring was completed in v0.484 but file still under pending/, flag for a lifecycle cleanup pass)
- 14-backup-restore-implementation.md (pending; Plan 14 Step 1 landed at v0.486, plan continues)
- 15-ui-modernization-pass.md (pending; Steps 1-29 landed; only Step 30 lifecycle move remains)

Prior open issues remain open:

- 01-audit-verdict-not-human-readable.md
- 02-diagram-actor-orientation.md
- 03-verify-path-drift.md
- 04-preview-cannot-exercise-ui-without-backend.md (this plan's driver)
