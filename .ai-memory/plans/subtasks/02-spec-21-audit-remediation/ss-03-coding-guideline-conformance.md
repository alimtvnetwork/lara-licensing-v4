# SS-03: Coding-guideline conformance annotations on 02-spec/21-app

Slug: coding-guideline-conformance
Parent: 02-spec-21-audit-remediation
Status: pending
Created: 2026-07-16

## Goal

For each normative rule that 02-spec/21-app implies about implementation (PascalCase tables, camelCase fields, JSON PascalCase keys, function length tiers, positive boolean naming, no swallowed errors, Enum-backed Type/Status/Kind/Category columns, join tables for classification), add a "Coding-guideline conformance" section citing the exact rule from `.ai-memory/coding-guidelines.md` and `02-spec/02-coding-guidelines/01-cross-language/15-master-coding-guidelines.md`.

## Files touched

- `02-spec/21-app/04-roles.md`
- `02-spec/21-app/05-license-categories.md`
- `02-spec/21-app/06-license-variations.md`
- `02-spec/21-app/07-serial-generation.md`
- `02-spec/21-app/11-api-contracts/00-overview.md`
- `02-spec/23-app-db/01-schema.md`

## Done when

Each listed file has a bottom section "Coding-guideline conformance" with at least three cited rules and no rewording of the source rule.
