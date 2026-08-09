# SS-03: Coding-guideline conformance annotations on spec/21-app

Slug: coding-guideline-conformance
Parent: 02-spec-21-audit-remediation
Status: pending
Created: 2026-07-16

## Goal

For each normative rule that spec/21-app implies about implementation (PascalCase tables, camelCase fields, JSON PascalCase keys, function length tiers, positive boolean naming, no swallowed errors, Enum-backed Type/Status/Kind/Category columns, join tables for classification), add a "Coding-guideline conformance" section citing the exact rule from `.lovable/coding-guidelines/coding-guidelines.md` and `spec/02-coding-guidelines/01-cross-language/15-master-coding-guidelines.md`.

## Files touched

- `spec/21-app/04-roles.md`
- `spec/21-app/05-license-categories.md`
- `spec/21-app/06-license-variations.md`
- `spec/21-app/07-serial-generation.md`
- `spec/21-app/11-api-contracts/00-overview.md`
- `spec/23-app-db/01-schema.md`

## Done when

Each listed file has a bottom section "Coding-guideline conformance" with at least three cited rules and no rewording of the source rule.
