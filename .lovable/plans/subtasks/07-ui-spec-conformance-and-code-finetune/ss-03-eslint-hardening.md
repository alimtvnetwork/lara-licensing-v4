---
Slug: 03-eslint-hardening
Status: pending
Created: 2026-07-18
Parent: 07-ui-spec-conformance-and-code-finetune
---

# ESLint Hardening

Add strict rules to `eslint.config.js`:

- `max-lines-per-function`: `{ max: 15, skipBlankLines: true, skipComments: true, IIFEs: true }` for `src/**/*.tsx` and `src/**/*.ts` (excluding generated files and test fixtures).
- `@typescript-eslint/consistent-type-imports`: `error`.
- `@typescript-eslint/no-floating-promises`: `error`.
- `@typescript-eslint/no-misused-promises`: `error`.
- `@typescript-eslint/no-unnecessary-condition`: `warn` initially, escalate to `error` after cleanup.
- `react/jsx-no-literals` scoped to route components to enforce copy dictionary usage.

Process:
1. Add rules.
2. Run `bun run lint --fix`.
3. Manually resolve remaining findings by extraction into smaller components / helper hooks (`src/hooks/*`).
4. Never disable a rule inline unless spec explicitly permits (document in the file header).
5. Add pre-commit hook via `simple-git-hooks` running `bun run lint:ui` + `tsgo`.
