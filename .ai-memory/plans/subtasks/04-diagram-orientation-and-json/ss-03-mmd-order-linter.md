# SS-03: mmd participant-order linter

Parent: 04-diagram-orientation-and-json
Slug: mmd-order-linter
Status: pending
Created: 2026-07-17

## Purpose
Prevent diagram-orientation regressions by failing CI when a sequence diagram declares participants in the wrong order.

## Approach
- Small Python script under `linter-scripts/check-mmd-actor-order.py`.
- Walks `spec/**/*.mmd`. For each file whose first non-comment line is `sequenceDiagram`, extract `participant` declarations in order.
- Canonical priority (lower index wins, absent actors are skipped):
  1. EndUser / AppUser
  2. Reseller
  3. Admin / AppBuilder
  4. API / Lara
  5. DB / Split-DB
  6. Audit / AuditLog
- Fail if the declared order does not match the filtered canonical order.
- Wire into `linter-scripts/run.sh` and `linter-scripts/run.ps1`.

## Waivers
Allow an inline `%% lint:allow-actor-order` comment on the line above `sequenceDiagram` for legacy diagrams pending refactor; log a warning, do not fail.
