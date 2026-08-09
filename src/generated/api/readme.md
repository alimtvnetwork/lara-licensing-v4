# src/generated/api/

**AUTO-GENERATED (baseline: hand-written).**

This folder is the single source of TypeScript types for the Lara backend API.
Every request/response shape the frontend touches goes through
`operations.ts`. Do NOT hand-edit `schema.d.ts` after Plan 16 Step 25 wires
`scripts/generate-api-types.mjs` from `backend/build/openapi.json`.

## Current status

- **`schema.d.ts`**: hand-written baseline authored under Plan 16 Step 29
  (v0.535.0). Covers the 11 preview handler groups listed in Plan 16 Step 34:
  auth, password-reset, admin-licenses, features, portal-updates,
  portal-serials, admin-quotas, impersonation, audit, admin-metrics,
  admin-users. Envelope shape mirrors `src/lib/lara-envelope.ts`.
- **`operations.ts`**: typed `Operations` map (operationId -> `{ Request; Response }`)
  consumed by `src/lib/api-client.ts` (Plan 16 Step 31) and the preview
  transport (Step 33).

## Regeneration workflow (Plan 16 Step 22+)

Once `dedoc/scramble` is wired into the backend and `backend/build/openapi.json`
is committed:

```bash
# 1. Regenerate the OpenAPI export from Laravel controller annotations.
cd backend && php artisan lara:openapi:export

# 2. Regenerate schema.d.ts from the committed contract.
bun run scripts/generate-api-types.mjs

# 3. Verify the working tree is clean; a diff means the contract drifted.
git diff --exit-code src/generated/api/ backend/build/openapi.json
```

## Rules

- Frontend code MUST import operation types from `./operations` (or the
  re-exports in `src/lib/api-client.ts`), NEVER from `schema.d.ts` directly.
- Never widen a `Response` type with `| unknown` or `| any` to unblock a
  failing call; fix the backend contract instead.
- The hand-written baseline stays in force until Step 28's CI drift gate is
  green. From that point onward, every regeneration replaces the whole file.
