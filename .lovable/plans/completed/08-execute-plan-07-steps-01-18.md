# Execute Plan 07 CR patches and linter implementation, steps 01 to 18

Slug: execute-plan-07-steps-01-18
Steps: 18
Status: pending
Created: 2026-07-18

## Context

Plan 07 (`.lovable/plans/pending/07-cr-patches-and-linter-implementation.md`) enumerates the CR-01..CR-10 documentation patches plus six pending meta-linters queued after Plan 06's cross-blueprint audit (`spec/24-app-ui-design-system/59-cross-blueprint-audit.md`, `60-plan-06-acceptance-report.md`). This plan executes the first 18 discrete deliverables from that queue. Scope is spec-only plus new linters under `.lovable/coding-guidelines/`; no runtime code is touched.

Prior pending items still open elsewhere in `.lovable/`:
- `.lovable/plans/pending/05-rbac-quota-tier-environment.md` (paused, runtime tail).
- `.lovable/plans/pending/07-cr-patches-and-linter-implementation.md` (parent of this plan, stays pending until step 18 lands and graduation happens in step 18).
- `.lovable/pending-issues/issue-001-spec-numbering-drift.md` (open).
- `.lovable/pending-issues/issue-002-lib-runtime-spec-drift.md` (open, partially addressed).
- `.lovable/issues/01-audit-verdict-not-human-readable.md`, `02-diagram-actor-orientation.md`, `03-verify-path-drift.md` (all resolved historically, kept for audit trail).

No new commands or issues were introduced by the triggering message; nothing to route to `.lovable/spec/commands/` or `.lovable/issues/`.

## Steps

1. CR-01: write `spec/24-app-ui-design-system/61-cross-blueprint-audit-remediation.md` recording the back-link diff already greened and pin `AC-CROSS-001` to SATISFIED.
2. Implement `.lovable/coding-guidelines/check-reserved-words.py` (baseline snapshot, exits 0 on current tree).
3. CR-02: patch reserved-word drift F11..F15 across flagged blueprints (`Retire`, `Deactivate`, `Plan`, `Kill switch` to canonical verbs from `56-copy-dictionary.md`).
4. Implement `.lovable/coding-guidelines/check-copy-dictionary.py` referenced by `56-copy-dictionary.md` §14.
5. CR-03: patch copy-dictionary drift F16..F18 in blueprints flagged by `59-` §5.3.
6. Implement `.lovable/coding-guidelines/check-shortcut-registry.py` for `57-keyboard-shortcut-registry.md`.
7. CR-04: patch shortcut drift F19..F21 in blueprints flagged by `59-` §5.4.
8. Implement `.lovable/coding-guidelines/check-analytics-events.py` referenced by `58-analytics-event-catalog.md` §11.
9. CR-05: patch analytics drift F22..F24 in blueprints flagged by `59-` §5.5.
10. CR-06: patch error-code drift F25..F27 (align blueprints to `spec/21-app/12-error-taxonomy.md`).
11. Implement `.lovable/coding-guidelines/check-empty-state-tags.py` for `53-empty-state-catalog.md`.
12. CR-07: patch empty and loading tag drift F28..F30.
13. Implement `.lovable/coding-guidelines/check-loading-state-tags.py` for `54-loading-state-catalog.md`.
14. CR-08: patch illustration spot-check F31 against `52-icon-illustration-registry.md`.
15. CR-09: patch print route gap F32 against `55-print-export-stylesheet.md`.
16. CR-10: patch inline-literal `<Button>` gap F33 (route callers back to `56-copy-dictionary.md`).
17. Wire all six new linters into `linter-scripts/run.sh` and `linter-scripts/run.ps1` with matching exit codes.
18. Mark `AC-CROSS-001..006` SATISFIED in `61-`, move `.lovable/plans/pending/07-cr-patches-and-linter-implementation.md` to `.lovable/plans/complete/` and this plan file to `.lovable/plans/completed/`, bump version, update CHANGELOG, RELEASE-NOTES, README, `package.json`.

## Verification

- After each linter step (2, 4, 6, 8, 11, 13): run the new script, confirm exit 0 on current tree, capture baseline output in the CR patch that follows.
- After each CR patch step (1, 3, 5, 7, 9, 10, 12, 14, 15, 16): re-run the corresponding linter and `.lovable/coding-guidelines/check-blueprint-crossrefs.py`; both must exit 0.
- Step 17: `bash linter-scripts/run.sh` exits 0 and lists all six new checks in its output.
- Step 18: Vitest 175/175 unchanged (`bunx vitest run`), `rg -n "—" spec/24-app-ui-design-system/61-*.md CHANGELOG.md RELEASE-NOTES.md` returns nothing, `package.json` version equals README pin.

## Appended from prior pending tasks

- Plan 05 runtime tail (RBAC / Quota / Tier / Environment) remains pending, out of scope here.
- `issue-001-spec-numbering-drift.md` and `issue-002-lib-runtime-spec-drift.md` remain open, out of scope here.
