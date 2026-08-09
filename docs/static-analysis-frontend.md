# Frontend Static Analysis

CI gate: `.github/workflows/frontend-static-analysis.yml` runs a strict,
type-aware ESLint pass plus `tsgo --noEmit` on every push and pull request
touching `src/**` or the lint config. The build fails on any new finding
and on any stale suppression (baseline rot).

Symmetric to `backend/docs/static-analysis.md` (PHPStan gate).

## Stack

- **ESLint 9** with `typescript-eslint` v8 in type-aware mode (`projectService: true`).
- **Rules enabled as `error`** on `src/**/*.{ts,tsx}` (excluding shadcn `src/components/ui/**`, generated `routeTree.gen.ts`, `*.d.ts`, and test files):
  - `@typescript-eslint/consistent-type-imports` (inline `import type`)
  - `@typescript-eslint/no-floating-promises`
  - `@typescript-eslint/no-misused-promises`
  - `@typescript-eslint/no-unnecessary-condition`
  - `@typescript-eslint/no-unused-vars` (with `_` prefix escape)
  - `max-lines-per-function` (max 15, blanks and comments skipped, IIFEs counted)
- **Bulk suppressions** (ESLint 9's built-in `--suppress-all`, `eslint-suppressions.json`) baseline pre-existing violations so the gate can turn on today without a big-bang cleanup.
- **`tsgo --noEmit`** for full type-checking (faster than `tsc`).

Config: `eslint.config.js`. Baseline: `eslint-suppressions.json`.

## Local workflow

```bash
bun install
bun run lint:strict            # run the strict pass (must be green for CI)
bun run lint:strict:suppress   # regenerate suppressions after fixing findings
bun run typecheck              # tsgo --noEmit
bun run verify                 # lint:ui + lint:strict + typecheck + test
```

## Baseline policy

1. **Never hand-edit** `eslint-suppressions.json`. Regenerate it.
2. **Shrinking the baseline is always welcome** and does not require review.
3. **Growing the baseline requires review**: each new suppression is an
   accepted violation and must be justified in the PR description.
4. Unused suppression entries fail the build. If you fix a finding, regenerate
   the baseline in the same PR.

## Why `max-lines-per-function: 15`

Project memory (`mem://index.md`, core rules) mandates a 15-line function-body
cap. The rule enforces it at commit time so drift stops accumulating. Blank
lines and comments do not count, so annotated code is not penalized.

## Why type-aware rules

`no-floating-promises`, `no-misused-promises`, and `no-unnecessary-condition`
catch the exact class of bugs that Vitest cannot: unawaited async work,
`Promise<void>` passed where `void` is expected (event handlers), and dead
branches from over-narrowed types. Symmetric to PHPStan's `level: max` on
the backend.

## Why ESLint, not `tsconfig` flags, for strictness

TypeScript has no baseline mechanism. Turning on
`noUncheckedIndexedAccess` / `noPropertyAccessFromIndexSignature` /
`noUnusedLocals` project-wide would be a big-bang cleanup or ship red.
ESLint 9's bulk suppressions give us the same categorical coverage
(`no-unnecessary-condition` subsumes most nullability drift,
`no-unused-vars` subsumes dead-code drift) with a proper baseline. The
`tsconfig.json` stays at the compile-time settings the build has always
shipped with; new type-strictness lives in the ESLint gate.

