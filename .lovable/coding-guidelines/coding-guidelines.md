# Coding Guidelines

These rules are mandatory for all code generated in this project. The AI MUST read this file (and all referenced guideline files) before writing or modifying code.

## Files To Read Before Coding

1. This file: `.lovable/coding-guidelines.md`
2. All prompts: `.lovable/prompts/*.*`
3. All specs: `spec/01-app/*.md`
4. Master coding guidelines: `spec/02-coding-guidelines/01-cross-language/15-master-coding-guidelines.md`
5. Memory protocol: `.lovable/memory/protocol/01-workflow-rules.md`
6. Decision log: `.lovable/memory/history/01-decisions.md`
7. Plan: `.lovable/memory/workflow/01-plan.md`

## Hard Rules

1. Function length is **tiered**: ≤ **8 lines** best practice · ≤ **15 lines** hard cap for normal code (linter CODE-RED past this) · **16–25 lines** allowed only with `# lint-allow: function-length reason="..." max=N` waiver · **> 25 lines** only with `framework=true` for language/framework-imposed signatures or large `switch`/`match` blocks (absolute ceiling 60).
2. No nested `if` statements.
3. `if` conditions must be positive and simple — no negations, no `!`.
4. Follow Boolean naming guidelines: prefix with `is` or `has`. Never use negative booleans.
5. Use proper, narrow types. Avoid `any`, `unknown`, `interface{}`, or any wide-range catch-all type. **Exception:** `unknown` (TS) and `any` are acceptable inside a `catch` block, at trust boundaries (e.g. deserializing untrusted JSON), or when interfacing with an external library that returns an untyped value — narrow immediately with a type guard. The goal is compile-time safety, not banning the escape hatch where it is genuinely safer than a forced cast. `Generic<T>` remains the standard tool for parametric types.
6. No swallowed errors. Every `catch` must log per the project logging guidelines.
7. Files / classes ≤ 80–100 lines max.
8. **No magic strings or numbers.** Every literal that carries meaning (status, kind, role, error code, action, limit, timeout, retry count, cache TTL, HTTP status, permission key, feature key, environment name, tier name, ledger action, audit action, table name, column name, endpoint path, header name, regex, cron expression, currency, unit) MUST come from a named `enum`, `const`, or catalog object in a dedicated definitions file. Allowed raw literals: `0`, `1`, `-1`, `''`, boolean, array/loop indexes, obvious math identities, and test fixture values inside `*.test.*`/`*.spec.*`. All CHECK constraints in migrations MUST derive their allowed values from the same shared catalog referenced by the app code (comment the source file). Waiver: `// lint-allow: magic-literal reason="..."` on the line, used sparingly.
9. Definitions live in their own dedicated files, not inline.
10. Keep code DRY — reusability is the highest-priority concern.
11. React/TypeScript components must be as small and reusable as possible. For multi-component features, plan first and produce a Mermaid component diagram.
12. Use Enums (typed) for any `Type`, `Kind`, `Status`, `Category` field.
13. If a `spec/**/error-manage/` folder exists, every error handler MUST follow those guidelines exactly. No exceptions.

## Data & Schema Rules

1. Tables, types, entities → **PascalCase**.
2. Fields/columns → **camelCase**.
3. JSON keys and values (when project uses JSON) → **PascalCase**.
4. Every primary key: `int auto-increment`, named `{PascalCaseTableName}Id`.
5. `Type` / `Status` / `Category` / `Kind` columns → 1-N or N-M join tables (never inline strings/enums in the row).
6. Use the smallest appropriate integer type for category IDs.
7. Default DB: SQLite. Prefer ORM. Define joins, PK/FK explicitly.
8. Any DB discussion must include a Mermaid ERD.

## Error & Logging

1. Catch → log → rethrow or handle. Never silent.
2. Log level appropriate to severity.
3. Include context (operation name, key inputs) in log messages.

## Important

- These guidelines override convenience. If a rule conflicts with a quick fix, follow the rule.
- When in doubt, ask before writing code.
