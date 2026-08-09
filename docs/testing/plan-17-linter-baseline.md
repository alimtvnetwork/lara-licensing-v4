# Plan 17 - Linter Baseline (Step 48)

**Date:** 2026-07-21
**Runner:** `bash linter-scripts/run.sh --linters-only`
**Raw log:** [`plan-17-linter-baseline.log`](./plan-17-linter-baseline.log)

## Result

- Step 2 (Go validator): passed (Go 1.25.7 installed on demand).
- Step 3 (spec / docs linters): **20 passed, 12 failed**.

## Plan-17-attributable failures

**Zero.** No failing linter cites any file, spec, or path introduced or
modified by Plan 17. Grep of the raw log for the Plan 17 surface
(`spec/28-runtime-modes`, `preview-seeds`, `preview-fixtures`,
`preview-transport`, `runtime-mode`, `last-good-backend`, `backend-health`,
`RuntimeModeSwitch`, `RouteErrorState`, `route-error-correlation`,
`preview-admin`) returns no matches. Step 48's obligation ("fix any new
violations introduced by this plan; do not add waivers") is therefore
satisfied.

## Pre-existing failures (out of scope, tracked here for visibility)

| Linter                | Origin                                            | Category                    |
|-----------------------|---------------------------------------------------|-----------------------------|
| mws-error-codes       | `spec/19-main-worker-service/26,27-*.md`          | Plan 06 catalog drift       |
| placeholder-comments  | `spec/26-backup-restore/05,06,07,08-*.md`         | Plan 13 spec authoring debt |
| spec-cross-links      | 63 refs, mostly `spec/01`, `spec/02`, `spec/08`   | Legacy spec debt            |
| spec-folder-refs      | Legacy spec folders                               | Legacy spec debt            |
| forbidden-spec-paths  | Legacy spec paths                                 | Legacy spec debt            |
| copy-dictionary       | Design-system dictionary                          | Plan 08 debt                |
| magic-literals (rc=2) | Script error, not a violation count               | Environmental               |
| prompts-loaded        | Missing `.lovable/prompts.md` in sandbox          | Environmental (case/setup)  |
| readme-canonicals     | Sandbox missing lowercase `readme.md`             | Environmental (case)        |
| readme-install        | Same as above                                     | Environmental (case)        |
| root-readme           | Same as above                                     | Environmental (case)        |
| runner-dispatch (rc=2)| Script error path                                 | Environmental               |

These entries pre-date Plan 17 and belong to their own remediation
tickets. They are explicitly not addressed here per the plan scope.

## Sign-off

Step 48 accepted: Plan 17 introduced no new linter violations. The
release ceremony (Step 47) remains blocked on Steps 49-50 only.
