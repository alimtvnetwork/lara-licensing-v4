# SS-02: Closed-set error-code parity CI check

Parent: 11-coding-guidelines-error-manage-integration
Slug: error-code-parity-ci
Status: pending
Created: 2026-07-19

## Goal
Guarantee every value in `src/lib/lara-api-error.ts::ApiErrorCodeType` exists in `backend/config/lara.php::error_codes` and vice-versa. Failing parity = CI red.

## Changes
- New script `scripts/check-error-code-parity.mjs`:
  - Parse TS enum via regex/AST (ts-morph or simple regex on `Name = "Name"`).
  - Parse PHP config via `php -r "echo json_encode(require 'backend/config/lara.php');"`.
  - Diff both sets; exit 1 with a printed table on mismatch.
- `package.json` script `check:error-codes`.
- `.github/workflows/lint.yml`: add step `bun run check:error-codes` after install.

## Verification
- Run locally with a deliberate mismatch; script prints missing codes and exits 1.
- CI job fails on PR that adds a FE code without BE counterpart.
