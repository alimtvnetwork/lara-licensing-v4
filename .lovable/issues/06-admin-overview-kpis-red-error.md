# Admin Overview KPI tiles render red "Something failed on our side" in seed mode

Slug: admin-overview-kpis-red-error
Status: open
Raised: 2026-07-21
Reported-by: user (screenshot attached)

## Observed

On `/admin` in preview seed mode ("Seed data -> Backend API" toggle visible bottom-left), every KPI tile renders the destructive fallback:

- ACTIVE RESELLERS: `--` + "Something failed on our side. Try again in a moment."
- ACTIVE SESSIONS: same
- LICENSES ISSUED: same
- QUOTA REQUESTS PENDING: same

"Recent activity" list below the tiles renders fine (populated from AuditWriter seed rows), which proves the seed fixtures partly load but the KPI aggregate operations do not.

## Impact

Seed-mode is documented as the primary dev/demo surface (see `.lovable/memory/standards/preview-is-primary-dev-surface.md`). Four failing KPIs on the landing screen makes the app look broken to any first-time reviewer, and blocks demoing without a live backend.

## Hypotheses to verify

1. KPI operations (`admin.metrics.*` or equivalent) have no preview-fixture handler wired in `src/lib/preview-fixtures/` -> `dispatchPreview` returns a generic ServerError.
2. Handlers exist but the Zod shape assertion in `_shapes.ts` (Plan 17 Step 42) is rejecting them and surfacing as `PreviewFixtureShapeError`.
3. Operations exist in `operations.ts` but not in the shape map, tripping the exhaustive-coverage guard.

## Attachments

- `../spec/tasks/assets/18-backend-seed-login-e2e-error-manage/admin-overview-red-errors.png` - user screenshot showing four red KPI tiles under seed mode.

## Linked plan

`.lovable/plans/pending/18-backend-seed-login-e2e-error-manage.md`
