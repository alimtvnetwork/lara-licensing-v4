# SS-03: Magic-literal audit for error paths

Parent: 11-coding-guidelines-error-manage-integration
Slug: magic-literals-audit
Status: pending
Created: 2026-07-19

## Goal
spec/02-coding-guidelines forbids magic strings/numbers in domain code. Sweep BE + FE error surfaces to route every literal through enums/config/constants.

## Scope
- BE: HTTP status literals in controllers, error-code string literals outside LaraException::make calls, hard-coded retry seconds.
- FE: raw error-code strings, hard-coded HTTP statuses in fetch layer, toast titles.

## Deliverables
- `linter-scripts/check-magic-literals.py` extended with error-manage rules (list of allowed constants files).
- Fix violations in backend/app/Http/Controllers, backend/app/Services, src/lib/lara-api-error.ts consumers.
- Report file: `spec/03-error-manage/98-magic-literals-report.md` with before/after counts.

## Verification
- Linter run in CI (workflow `lint.yml`) exits 0.
- Zero occurrences of bare error-code string literals outside `lara-api-error.ts` and `backend/config/lara.php`.
