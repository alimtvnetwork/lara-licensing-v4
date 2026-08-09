# SS-01: Synthetic JWT & Claim Generation

Parent: 21-backend-parity-phase-c
Status: pending

## Context

This subtask covers the core logic in `src/lib/preview-auth.ts` for generating the synthetic auth artifacts that mimic the Laravel backend's responses.

## Acceptance Criteria

- [ ] `generateSyntheticToken` returns a string that parses as a valid JWT header/payload (signing is optional for preview).
- [ ] `generateSyntheticUser` returns a `MeUser` object with role-appropriate permissions.
- [ ] Claims include `iat`, `exp`, and `sub` (userId).
- [ ] Zod validation passes against `AuthSessionResource`.

## Affected Files

- `src/lib/preview-auth.ts`
- `src/generated/api/schema.d.ts`
