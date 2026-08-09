# Plan 07: CR-01..CR-10 Patches + Pending Linter Implementation

**Status:** pending
**Predecessor:** Plan 06 (complete, see `.lovable/plans/complete/06-spec-24-ui-fluid-swagger.md`)
**Owner queue:** spec-only until §Runtime phase.

## Objective

Green the six OPEN cross-blueprint acceptance criteria (`AC-CROSS-001..006`) surfaced by `spec/24-app-ui-design-system/59-cross-blueprint-audit.md`, and implement the six PENDING meta-linters listed in `60-plan-06-acceptance-report.md` §6 so that spec drift is caught by CI instead of manual audit.

## Scope (in)

1. CR-01: back-link catalog patches for blueprints 33..42 (this pass already greened `check-blueprint-crossrefs.py`; the CR document must still record the diff and pin AC-CROSS-001).
2. CR-02..CR-05: reserved-word, token, shortcut, and analytics drift patches across the flagged blueprints (F11..F24 in `59-` §5).
3. CR-06..CR-07: error-code and empty / loading tag drift patches (F25..F30).
4. CR-08..CR-10: illustration spot-check, print route gap, and inline-literal `<Button>` gap (F31..F33).
5. Implement the six PENDING linters:
   - `check-reserved-words.py`
   - `check-copy-dictionary.py` (already referenced by `56-` §14)
   - `check-analytics-events.py` (already referenced by `58-` §11)
   - `check-shortcut-registry.py`
   - `check-empty-state-tags.py`
   - `check-loading-state-tags.py`

## Scope (out)

- Runtime UI implementation (Plan 08).
- Analytics ingest endpoint + DSAR (Plan 09).
- RBAC / Quota runtime remaining in Plan 05.

## Definition of done

- All six `AC-CROSS-001..006` marked SATISFIED in a new `61-cross-blueprint-audit-remediation.md`.
- Each new linter exits 0 on the current tree (baseline snapshot committed).
- Zero em dashes; Vitest 175/175 unchanged (no runtime code touched).
- Version bumped per step; RELEASE-NOTES + CHANGELOG + README pinned.

## Step budget

~30 steps, executed 2 at a time.
