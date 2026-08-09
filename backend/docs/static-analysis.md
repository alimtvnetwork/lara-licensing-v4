# Backend Static Analysis

CI gate: `.github/workflows/backend-static-analysis.yml` runs PHPStan on every
push and pull request touching `backend/**`. The build fails on any new type
error, deprecation, strict-rule violation, or dead-code finding.

## Stack

- **PHPStan** at `level: max`
- **Larastan** (`larastan/larastan`) for Laravel-aware inference (Eloquent, container, facades)
- **phpstan-strict-rules** (looser-than-Psalm strict: no dynamic property access, strict comparisons, etc.)
- **phpstan-deprecation-rules** (fails when calling `@deprecated` symbols)
- **ShipMonk dead-code detector** (`shipmonk/dead-code-detector`): reports
  unused methods, properties, constants. PHPUnit / Pest entrypoints are
  registered so test methods are not flagged.

Config: `backend/phpstan.neon`. Baseline: `backend/phpstan-baseline.neon`.

## Local workflow

```bash
cd backend
composer install
composer phpstan            # run analysis (must be green for CI)
composer phpstan:baseline   # regenerate baseline after fixing findings
```

## Baseline policy

The baseline snapshots pre-existing findings so we can turn the gate on
without a big-bang cleanup. Rules:

1. **Never hand-edit** `phpstan-baseline.neon`. Regenerate it.
2. **Shrinking the baseline is always welcome** and does not require review.
3. **Growing the baseline requires review**: a new baseline entry is an
   accepted latent bug and must be justified in the PR description.
4. `reportUnmatchedIgnoredErrors: true` means CI also fails if a baseline
   entry no longer matches. Regenerate the baseline in the same PR that
   fixes the finding.

## Why not Psalm?

Larastan (PHPStan-based) has first-class Laravel support (Eloquent generics,
facades, container resolution, request macros). Psalm's Laravel plugin
lags. Given the codebase is Laravel 11, PHPStan is the pragmatic pick.
