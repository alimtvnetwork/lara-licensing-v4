# SS-04: Reconcile version drift across spec/21-app

Slug: version-drift-reconciliation
Parent: 02-spec-21-audit-remediation
Status: pending
Created: 2026-07-16

## Goal

Close the finding recorded in `spec/25-app-audit/02-file-inventory.md`: `00-overview.md` sits at 3.3.0 while most leaf files sit at 0.1.0 and a few at 0.19.0-0.21.0. Adopt one scheme.

## Chosen scheme

Reset every file under `spec/21-app/` to a shared normative baseline `1.0.0` in one atomic pass, matching the sealed audit (`00-final-report.md` v1.0.0). Record the reset in `.lovable/memory/history/01-decisions.md`.

## Done when

- Every `spec/21-app/**/*.md` frontmatter shows `Version: 1.0.0` and `Updated: 2026-07-16` on the reset commit only.
- `00-overview.md` "Version history" table records the reset with a one-line reason.
- `spec/25-app-audit/02-file-inventory.md` gets a follow-up note saying the drift is closed.
