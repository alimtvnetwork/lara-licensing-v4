# Command: Improve spec/21-app from audit findings

Slug: improve-spec-21-from-audit
Status: open
Created: 2026-07-16

## Command (verbatim intent)

"All these findings, you should actually improve the original 421 spec regarding how it's going to follow through the implementation and a spec-driven design, and also it should follow the coding guide and then error manage as much as possible in the next 50 steps."

## Scope

- Target: every file under `spec/21-app/` (and companions in `spec/23-app-db/`, `spec/24-app-ui-design-system/`).
- Source of truth for gaps: `spec/25-app-audit/` (final report v1.0.0, per-file findings, cross-file consistency, coding-guideline alignment, error-management surface).
- Enforcement axes: (1) spec-driven design faithfulness, (2) `.lovable/coding-guidelines/` and `spec/02-coding-guidelines/`, (3) `spec/03-error-manage/` rules.

## When it applies

Every editing pass on `spec/21-app/` until the plan `02-spec-21-audit-remediation` reaches `completed/`.
