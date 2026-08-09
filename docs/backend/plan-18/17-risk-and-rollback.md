# Plan 18 - Risk register and rollback gates

Owner-step: 17
Status: locked (Phase A)
Consumers: Steps 18, 19, 20, and every implementation step 21-200 that a mitigation is attached to.

## 1. Scope

Ranks the load-bearing risks for Plan 18 execution, assigns each mitigation to specific step numbers in the 21-200 range, and defines the rollback gates that stop the plan advancing when a mitigation fails. Risks not listed here are considered residual and covered by the standard release ceremony at Step 200.

## 2. Ranked risks

Ranking is by expected blast radius (data / auth / DX). R1 is highest.

### R1 - Auth-shape drift between synthetic seed session and production claim envelope

Blast radius: every `_authenticated` route gate misreads the session, admin surfaces render blank or 403 under seed mode.

Trigger conditions:
- `signInWithSeedIdentity` writes a session shape that diverges from `AuthSessionResource` / `MeUser`.
- Backend `LoginController` changes the claim envelope after Step 44 lands but before Step 200.

Owning steps: 8 (spec), 44-46 (implementation), 50 (unit tests), 121-125 (Pest login tests).

Mitigations:
- Step 8 already locks the claim envelope union; Step 44 must import from `src/lib/demo-identities.ts` (not inline).
- Step 46 asserts the synthetic session round-trips through the same Zod schema as the real login response.
- Step 50 covers hydration + mode-flip clearing.
- Pest Step 122 asserts backend `LoginController` still emits the same envelope; if it drifts, rollback gate G-R1 fires.

Rollback gate G-R1: if Step 46 tests fail, revert Steps 42-49 as a block and re-open Step 8. Do NOT advance past Step 50 with a failing round-trip.

### R2 - Preview fixtures leaking into production bundle

Blast radius: production ships with demo credentials or fixture handlers, security incident.

Trigger conditions:
- `<DemoLoginPanel />` imported statically from a route module included in the prod bundle.
- Preview fixture handlers imported at module scope of `preview-transport.ts` without the runtime-mode gate.

Owning steps: 9 (spec), 42-49 (panel), 61-76 (fixtures), 176 (linter extension), 183 (CI).

Mitigations:
- Step 43 must use `React.lazy` behind `isPreview()`, matching the `check-preview-in-prod-bundle.py` marker (`DEMO_LOGIN_PANEL_MARKER`).
- Step 49 extends the linter to fail on any static import of `DemoLoginPanel` outside `preview` gates.
- Step 76 routes profile switch through `runtime-mode` selector so the transport never reaches `error`/`empty` fixtures in production.
- Step 183 wires the extended linter into `coding-guidelines.yml` and `preview-in-prod-bundle` job.

Rollback gate G-R2: any Step 61-76 that adds a fixture without a paired `_shapes.ts` row is blocked at merge; `check-preview-handler-coverage.py` must be green before the next fixture step starts.

### R3 - Admin-metrics divergence between seeder and preview fixture

Blast radius: `/admin` shows green tiles under real backend but red or mismatched numbers under preview seed - the exact defect Plan 18 is meant to close.

Trigger conditions:
- `AdminMetricsSeeder` emits counts derived from seeded rows, but the preview fixture returns hard-coded numbers that diverge.
- Row 20 (`admin.metrics.kpis`) BE implementation lands with a shape not matched by the preview fixture.

Owning steps: 5 (controller skeleton), 6 (seeder coverage), 10 (fixture plan), 30 (seeder), 62-64 (fixture + shape).

Mitigations:
- Step 30 seeder documents the exact counts it produces; Step 62 imports those counts through a shared `demo-metrics-constants.ts` module.
- Step 64 registers Zod shape in `_shapes.ts`; Step 42-runtime `assertPreviewShape` fails fast when the fixture diverges.
- Step 145 Pest test asserts backend `MetricsController@kpis` returns counts matching the seeder.
- Step 155 Playwright spec asserts `/admin` renders four green KPI tiles under `default` seed.

Rollback gate G-R3: if Step 155 fails after Step 64 lands, revert Step 62-64 as a block and re-open Step 30. Advancing past Step 80 with a red KPI tile is forbidden (spec-18 AC-05).

### R4 - Seeder performance regression on CI

Blast radius: `backend-e2e.yml` timeouts, CI flake, developer velocity loss.

Trigger conditions:
- `AuditWriterSeeder` (Step 31) inserts >= 15 rows per test class without transaction batching.
- `MigrationsAreIdempotentTest` (Step 38) runs `migrate:fresh --seed` twice serially and blows the job budget.

Owning steps: 6 (coverage plan), 30-40 (seeders), 38 (idempotency test), 181 (CI edit).

Mitigations:
- Step 6 locks per-tile row counts; no seeder may exceed the documented count without amending Step 6.
- Step 40 `SeederFixtureShapeTest` runs under `RefreshDatabase` once per class, not per test.
- Step 181 CI edit splits `backend-e2e.yml` into a `seed_profile` matrix so seeders don't run serially; per-profile cache key already defined in Step 16.

Rollback gate G-R4: if any Phase B step lands and the `backend-e2e.yml` wall time exceeds 12 minutes, block the next step and revisit Step 6 row budgets.

### R5 - Error-envelope backwards-incompat break for existing FE consumers

Blast radius: every `LaraApiError` toast/notification breaks, admin error surfaces regress.

Trigger conditions:
- Step 85 renderer change drops a field currently read by `src/lib/lara-envelope.ts`.
- Step 11 additive fields land as required instead of optional.

Owning steps: 11 (plan), 82-91 (backend), 101-115 (frontend), 141-143 (Pest error tests), 179 (contract linter).

Mitigations:
- Step 11 already locks additive-only semantics (`Attributes.Category`, `Attributes.OperationId`).
- Step 85 renderer emits new fields alongside existing ones; existing FE decoder must still parse the response.
- Step 179 `check-error-envelope-shape.py` fails on any field removal.
- Step 143 Pest snapshot test asserts the full envelope against a golden fixture.

Rollback gate G-R5: if Step 179 fails, revert Steps 82-91 as a block and re-open Step 11 to re-plan the envelope diff.

### R6 - Notification center persistence bleed

Blast radius: notifications persist across logout, leaking prior-user error correlation IDs.

Trigger conditions:
- Step 12 store implemented with `persist` middleware by mistake.
- Toast bridge fires on stale entries after mode flip.

Owning steps: 12 (plan), 111-118 (store + bell + drawer), 163-166 (Playwright).

Mitigations:
- Step 12 already locks non-persisted store; Step 111 must not add `persist`.
- Step 115 auth-store subscriber clears the ring on logout / mode flip.
- Step 165 Playwright spec asserts logout clears the notification center.

Rollback gate G-R6: if Step 165 fails, revert Steps 111-118 and re-open Step 12.

### R7 - CI matrix cost explosion

Blast radius: CI minutes blow the workspace budget.

Trigger conditions:
- Step 16 3x2 shard matrix runs on every push instead of on PR + nightly.
- Nightly cross-browser adds Firefox+WebKit on top of the 6-shard base.

Owning steps: 16 (plan), 181-185 (workflows).

Mitigations:
- Step 182 gates the 6-shard matrix on PR + `main` only; feature branches run 1 shard.
- Step 185 nightly workflow schedules cross-browser once per day.

Rollback gate G-R7: if the workspace credit balance drops faster than 1.5x the pre-plan baseline over any 7-day window, pause Phase G and revisit Step 16.

## 3. Rollback gates summary

| Gate | Fires when | Blocks | Reverts | Re-opens |
| ---- | ---------- | ------ | ------- | -------- |
| G-R1 | Step 46 round-trip fails | 47-50 | 42-49 | Step 8 |
| G-R2 | Any 61-76 fixture missing `_shapes.ts` row | next fixture step | offending step | none (fix in place) |
| G-R3 | Step 155 red KPI under `default` | 81+ | 62-64 | Step 30 |
| G-R4 | `backend-e2e.yml` > 12min wall time | next seeder step | offending seeder | Step 6 |
| G-R5 | Step 179 envelope-shape linter fails | 92+ | 82-91 | Step 11 |
| G-R6 | Step 165 logout-clear fails | 167+ | 111-118 | Step 12 |
| G-R7 | Workspace credit burn > 1.5x baseline | Phase G steps | none (throttle only) | Step 16 |

## 4. Release-ceremony gate

Step 200 release ceremony is blocked if any gate above is open. Each gate must be recorded as `closed` in `docs/backend/plan-18/20-plan-freeze.md` before version bump.

---

Locked 2026-07-23. Amendments require an entry appended to Section 5 with date + reason; do not rewrite prior sections in place.

## 5. Amendments

- Step 191: Gates G-R1 through G-R7 successfully verified and closed. No rollbacks required during Phase B-I.
