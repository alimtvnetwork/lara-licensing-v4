# SS-02: Frontend transport gap report

Parent: 09-fluid-ui-and-cpanel-release
Slug: frontend-transport-gap-report
Status: complete
Delivered: 2026-07-19 (v0.274.0)
Output: `docs/backend/frontend-transport-gap-report.md`

## Goal

Enumerate every `src/lib/lara-*.ts` transport, map each to a backend route from SS-01, and flag any endpoint without a typed client function. Feeds Plan 09 steps 30..51 (per-route UI + transport wiring).

## Procedure

1. Grep `"/*"` literals across `src/lib/lara-*.ts`.
2. Cross-reference against the 41-route inventory in `docs/backend/endpoint-gap-report.md`.
3. Note naming mismatches between transport paths and backend routes.
4. Emit prioritised backfill list.

## Result

- 12 endpoints partially wired, 29 endpoints missing a typed client. Coverage 29 percent.
- Two naming mismatches: frontend `/Auth/Token` vs backend `/Auth/Login`; frontend `/Users/Me` has no backend route yet.
- Detailed table + backfill list in `docs/backend/frontend-transport-gap-report.md`.

## Verification

- Every path literal in `src/lib/lara-*.ts` was accounted for.
- Coverage target: 100 percent gated by a new `tests/transport-coverage.test.ts` (deferred to Plan 09 step 30-51 execution).
