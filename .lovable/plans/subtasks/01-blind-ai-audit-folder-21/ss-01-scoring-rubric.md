# SS-01: Scoring Rubric

Parent: 01-blind-ai-audit-folder-21
Slug: scoring-rubric
Status: pending
Created: 2026-07-16

## Rubric

Four capabilities, each 0-100, weighted equally:

1. **Understand** — a blind AI reading only `spec/21-app/` can restate the product, actors, and flows without inventing details.
2. **Build** — a blind AI can scaffold routes, DB tables, API clients, and UI shells from the spec alone.
3. **Modify** — a blind AI can extend the spec (add an endpoint, a role, a lifecycle state) without breaking existing invariants.
4. **Validate** — a blind AI can write tests and linter checks that a maintainer would accept.

Overall = arithmetic mean. Handoff-weighted = `0.2·Understand + 0.3·Build + 0.25·Modify + 0.25·Validate`.

## Thresholds

| Band | Score | Meaning |
|------|-------|---------|
| A+ | 95-100 | Blind-AI handoff ready |
| A | 85-94 | Minor gaps, ship-with-review |
| B | 70-84 | Structural gaps, needs a follow-up pass |
| C | 50-69 | Major gaps, unsafe for blind handoff |
| F | <50 | Rewrite required |

## Severity legend

- 🔴 Blocker — blind AI cannot proceed
- 🟠 High — blind AI will guess wrong
- 🟡 Medium — blind AI will produce inconsistent output
- 🟢 Low — cosmetic
