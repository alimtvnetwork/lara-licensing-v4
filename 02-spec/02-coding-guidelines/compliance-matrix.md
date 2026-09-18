# Coding Guidelines Implementation Compliance Matrix

**Version:** 1.1.0  
**Verified:** 2026-07-21  
**Status:** Not fully compliant

## Honest result

The coding guideline specification is not fully implemented across the frontend and backend. The canonical validator reports 575 frontend and 482 backend CODE-RED findings after generated TanStack route code is correctly excluded. These findings are now ratcheted in CI, so no category can grow unnoticed and every reduction requires the baseline to shrink.

## Compliance matrix

| Invariant | Frontend | Backend | Machine enforcement | Result |
|---|---:|---:|---|---|
| Function body at most 15 lines | 161 findings | 132 findings | `validate-guidelines.py`, ESLint only covers selected frontend source and has bulk suppressions | Partial |
| No nested `if` | 39 findings | 11 findings | Canonical validator, newly wired to CI | Partial |
| Boolean naming | 2 findings | 23 findings | Canonical validator, newly wired to CI | Partial |
| No magic strings in comparisons | 171 findings | 22 findings | Canonical validator plus narrower magic-literal linter | Partial |
| No magic numbers in logic | 40 findings | 16 findings | Canonical validator plus narrower HTTP-status linter | Partial |
| Immutable by default | 18 findings | 0 findings | Canonical validator | Partial |
| File at most 300 lines | 4 findings | 7 findings | Canonical validator | Partial |
| At most 3 parameters | 4 findings | 85 findings | Canonical validator | Partial |
| No mixed boolean operators | 0 findings | 2 findings | Canonical validator | Partial |
| No raw negation on calls | 40 findings | 108 findings | Canonical validator | Partial |
| No positional boolean arguments | 85 findings | 75 findings | Canonical validator | Partial |
| No SQL string concatenation | 0 findings | 1 finding | Canonical validator | Partial |
| Strict TypeScript | Enforced | Not applicable | `tsgo --noEmit` | Compliant |
| Strict PHP analysis | Not applicable | Ratcheted | PHPStan baseline | Partial |
| No new domain magic literals | Ratcheted | Ratcheted | `check-magic-literals.py` | Partial |
| PascalCase API keys | Mostly established | Mostly established | Contract tests cover selected envelopes and resources, not every JSON producer | Partial |
| PascalCase database names | Not applicable | Established in primary schema | No complete migration-schema naming gate | Partial |
| Laravel FormRequest, Policy, Resource, Service conventions | Not applicable | Inconsistent coverage | PHPStan does not enforce architecture ownership | Partial |
| PHP framework guidance matches the backend | Not applicable | `04-php/` describes WordPress hooks, requests, and helpers rather than Laravel 11 | No applicability marker or Laravel replacement chapter | Missing |
| Table plurality is unambiguous | Not applicable | `00-overview.md` requires singular names while the database chapter, migrations, and models use plural names | No specification-consistency gate | Contradictory |
| One authoritative column-casing rule | Not applicable | This folder requires PascalCase while the parallel Lovable guideline document says camelCase | No authority-drift gate | Contradictory |

## Enforcement added in v1.1

1. `.github/workflows/coding-guidelines.yml` runs the canonical validator against `src/` and `backend/app/`.
2. `check-guideline-baseline.py` fails on any increased rule count and on stale counts after a reduction.
3. `bun run verify` now includes the guideline ratchet.
4. Generated `src/routeTree.gen.ts` is excluded because generated code cannot be remediated directly and was creating 33 false findings.

## Specification defects found by the full-folder audit

1. The complete `04-php/` subtree is written for a WordPress plugin. It mandates concepts such as WordPress request classes, hook registration, and plugin path helpers that do not exist in the Laravel 11 backend. It provides no guidance for FormRequest, Policy, Resource, Gate, Eloquent, service providers, queues, or Sanctum.
2. `00-overview.md` requires singular database table names, but the detailed database examples and shipped schema use plural PascalCase names such as `Users`, `Roles`, `Resellers`, and `AuditLogs`.
3. The parallel Lovable coding-guideline document requires camelCase database fields, while this specification and the shipped schema require PascalCase fields. Both documents currently present themselves as mandatory authority.
4. PHPStan is genuinely strict and has an empty suppression baseline. By contrast, frontend ESLint and magic-literal checks carry large legacy baselines. These tools prevent growth but do not prove present compliance.

## Remaining closure rule

The baseline is evidence of debt, not an exemption. Full compliance is reached only when every count is zero and the temporary baseline is removed. Until then, no report may state that this specification is fully implemented.