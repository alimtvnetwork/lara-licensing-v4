# Seed mode reliability + spec/06 seedable-config integration

Slug: seed-mode-reliability-and-config-db
Steps: 50
Status: completed
Created: 2026-07-21

## Context

Seed mode (`Data source = Seed data`) breaks on admin routes: `/admin/audit` renders a
"could not be loaded" StateCard even though the preview handler `admin.audit.list` and
IndexedDB seed rows exist. See screenshot in `.lovable/issues/05-seed-mode-audit-load-failure.md`.
Likely causes: boot race (queries fire before `dispatchPreviewSeed()` finishes and handlers
register), missing fixture-module imports on admin entry, and preview seeds not sourced
from the canonical seedable-config surface in `spec/06-seedable-config-architecture/`
(closed sets, feature catalog, roles). This plan makes seed mode the fast, deterministic
default: boot resolves before render, every route has fixtures + rows, and config-tier
data is generated from spec/06 so BE/FE stay in lockstep.

Related:
- Command: `.lovable/spec/commands/10-seed-mode-must-work-fast-from-config-db.md`
- Issue: `.lovable/issues/05-seed-mode-audit-load-failure.md`
- Spec: `spec/06-seedable-config-architecture/` (fundamentals, features, acceptance)
- Prior plans still open touching this axis: `pending/12`, `pending/14` (BR), `pending/15` (UI). None conflict.

## Steps

1. Reproduce the audit-log failure headless via Playwright against `http://localhost:8080/admin/audit` in `Mode=preview, Seed=default`; capture console + network to `/tmp/browser/seed-repro/`.
2. Repeat the repro for every admin route (`resellers`, `users`, `licenses`, `features`, `quota-requests`, `app-updates`, `serials`, `audit`) and every portal + reseller route; record which routes fail and with what error code.
3. Diff the failing operation ids against `src/lib/preview-transport.ts` registered handlers to find gaps (handler missing vs handler present but throwing).
4. Inspect `src/router.tsx` / `src/start.ts` / `src/routes/__root.tsx` for where `bootRuntimeConfig()` and `dispatchPreviewSeed()` are awaited relative to `<Outlet />` / query fire. Document the current sequence.
5. Confirm whether preview fixture modules are all imported at boot; enumerate every `src/lib/preview-fixtures/*.ts` and ensure a central registry imports them (side-effect import) before the first `useApi` call.
6. Read every file under `spec/06-seedable-config-architecture/` (fundamentals, features/**, issues/**, 97/98/99). Extract the canonical closed-set catalog and feature catalog rows that any seedable config DB must expose.
7. Cross-map spec/06 tables to existing preview domains in `src/lib/preview-store` and `preview-seeds/default.ts`; list every config-tier row currently missing from seed.
8. Define a typed `SeedableConfig` module at `src/lib/preview-seeds/config/index.ts` that exports closed sets, feature catalog, roles, and runtime config derived from spec/06 constants.
9. Add generator inputs under `src/lib/preview-seeds/config/` for: `closed-sets.ts`, `feature-catalog.ts`, `roles.ts`, `runtime-config.ts` — each mirroring the tables in spec/06.
10. Wire `loadDefaultSeed`, `loadEmptySeed`, `loadErrorSeed` to always hydrate the config surface first (config is present in all three seeds; only domain data varies).
11. Extend `preview-seeds/_contract.ts` with a `hydrateConfig()` primitive and a `hydrateOnce()` marker keyed per seed id + package version, so config re-seeds after version bumps.
12. Add a linter `linter-scripts/check-seed-config-parity.py` that fails CI when `spec/06` tables and `src/lib/preview-seeds/config/*` diverge (row count, keys, closed-set members).
13. Wire that linter into `linter-scripts/run.sh` and `.github/workflows/` (add to an existing static-analysis workflow, no new workflow file).
14. Refactor `bootRuntimeConfig()` to return a single `PreviewBoot` promise that resolves only after `dispatchPreviewSeed()` completes and all fixture modules are registered. See `./subtasks/17-seed-mode-reliability-and-config-db/SS-01-boot-sequencing.md`.
15. Add a top-level `<PreviewBootGate>` provider in `src/routes/__root.tsx` that suspends children until `PreviewBoot` resolves in `preview` mode; no-op in `dev`/`production`.
16. Ensure `_authenticated/route.tsx` and every admin loader is behind that gate so no query fires before handlers exist.
17. Add a boot-time invariant check: if `getRuntimeMode().Mode === 'preview'` and any registered fixture module count is zero, throw `PREVIEW_BOOT_INCOMPLETE` (never silent).
18. Replace the current handler-missing fallback in `preview-transport.ts` with a typed `UnknownPreviewOperation` LaraApiError that surfaces the operation id in the debug drawer.
19. Add per-handler timing (start/end + ms) logged via `console.info` under `preview-transport:<op>` for the debug drawer.
20. Backfill audit seed rows in `loadDefaultSeed` to at least 25 entries spanning multiple `EventType` values from spec/06 closed sets, so the audit list renders a full page.
21. Backfill quota-request seed rows so `admin.quota-requests` shows both pending and decided rows in `default`.
22. Backfill app-update manifest rows so `admin.app-updates` shows a mix of `Published` / `Draft` / `Superseded` states.
23. Backfill features rows and license-feature bindings from the spec/06 feature catalog so `admin.features` renders without empty lookups.
24. Add negative-path seed rows in `loadErrorSeed` per domain (audit, quota, features) so admin screens exercise both success and StateCard-error paths.
25. Update `loadEmptySeed` to still hydrate config (closed sets, features catalog) but leave transactional domains empty; confirm every list shows a proper empty state, not an error.
26. Add a Vitest suite `src/lib/preview-seeds/config/__tests__/parity.test.ts` locking the config surface to spec/06 fixtures.
27. Add a Vitest suite `src/lib/preview-transport/__tests__/registration.test.ts` asserting every operation id in `src/generated/api/operations.ts` has a registered preview handler.
28. Add an E2E Playwright spec `tests/e2e/preview-admin-routes.spec.ts` that visits every admin route under each seed and asserts no StateCard-error banner is shown for `default`/`empty`.
29. Add a Playwright spec asserting `error` seed shows the *expected* StateCard-error per route (proves the error path renders, not a boot failure).
30. Measure seed-mode cold-reload TTI per admin route and store baselines in `docs/testing/seed-mode-baseline.json`. Target: < 400 ms cold, < 100 ms warm.
31. Add IndexedDB warm-cache hit metrics to the debug drawer (rows loaded, ms per domain).
32. Migrate preview-store keys to consistent `<domain>::<Id>` casing across all fixtures; add a linter to enforce it.
33. Add a `resetPreviewStore()` dev-tool button to the debug drawer so testers can force a re-seed without clearing browser storage manually.
34. Ensure `RuntimeModeSwitch` reload path also re-runs `dispatchPreviewSeed()` when seed changes without a full navigation (fast-path when only `PreviewSeed` flipped).
35. Fix `RuntimeModeSwitch` copy: show current mode explicitly ("Currently: Seed data (default)") and disable Backend button until a URL is entered rather than only inline error.
36. When user switches to Backend API with a URL, ping `${url}/api/health` before committing; on failure show inline error and DO NOT flip mode.
37. Persist the last-good backend URL separately from the current override so switching back to Backend restores it.
38. Add telemetry log lines around each mode switch (from → to, seed id, url present) via `logRuntimeError` info channel.
39. Add a route-level `errorComponent` refinement on each admin route that reports the failing operation id + request id so users can see WHICH call broke, not a generic message.
40. Update `useApi` to attach `operationId` and `requestId` onto the thrown `LaraApiError` for the StateCard fallback.
41. Add a fixture for `admin.audit.list` covering pagination cursors and filter combinations; lock with contract test.
42. Add a runtime assertion in every fixture handler that the response shape matches the generated schema via `zod` parse; failure → typed `PreviewFixtureShapeError`.
43. Regenerate `src/generated/api/operations.lock.json` if any operation id changed during this plan; check the dead-operations linter passes.
44. Update `docs/testing/coverage-matrix.md` to include the new seed-mode E2E rows.
45. Update `.lovable/memory/index.md` with a one-liner rule: "Preview mode is the primary dev surface; every route must render green under `default` and `empty` seeds."
46. Update `spec/28-runtime-modes/` if any invariants change; otherwise stamp v1.x note that boot is gated.
47. Bump package version once per shipped step per protocol; update `CHANGELOG.md` and `RELEASE-NOTES.md` entries; keep `README.md` badge in sync.
48. Run the full `linter-scripts/run.sh` suite; fix any new violations introduced by this plan; do not add waivers.
49. Run `bunx vitest run` and the Playwright seed suite; capture pass/fail matrix into `docs/testing/seed-mode-baseline.json`.
50. Move this plan to `.lovable/plans/completed/17-seed-mode-reliability-and-config-db.md`, flip `Status: completed`, close `.lovable/issues/05-seed-mode-audit-load-failure.md`.

## Verification

- Playwright: every admin/portal/reseller route renders green under `default` and `empty`; expected StateCard-error under `error`.
- Vitest: config-parity + handler-registration suites green.
- Linters: `check-seed-config-parity.py`, dead-operations, schema-symbol-drift all green.
- Perf: seed-mode cold TTI < 400 ms recorded in `docs/testing/seed-mode-baseline.json`.
- Manual: from `/` hero switch, flip Seed data ↔ Backend API and back; audit log page loads without error banner.

## Appended from prior pending tasks

None pulled in — pending plans 05, 06, 07, 09, 12, 13, 14, 15 remain independent and are not blocked by this work.
