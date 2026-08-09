---
Slug: preview-auth
Status: pending
Created: 2026-07-20
Parent: 16-preview-production-runtime-typed-api
---

# SS-04: Preview auth handlers

## Seed users (default seed)

| Email | Password | Role | Purpose |
| --- | --- | --- | --- |
| root@preview.local | preview | root | full admin, runtime-config toggle allowed |
| admin@preview.local | preview | admin | admin surfaces |
| reseller@preview.local | preview | reseller | reseller portal |
| customer@preview.local | preview | customer | customer portal |
| readonly@preview.local | preview | readonly | forbidden-write scenarios |

## Handlers

- `Auth.Login`: match email + password against the seed set. On match, mint a fake JWT (base64-encoded JSON) and store the session in IndexedDB under `preview.session`.
- `Auth.Refresh`: extend the session by 24h; return the same shape as production.
- `Auth.Logout`: clear `preview.session`.
- `Auth.Me`: return the current session's user projection (id, email, role, name).
- `Auth.PasswordResetRequest`: always succeed; enqueue a fake reset token in IndexedDB with a 15-minute TTL.
- `Auth.PasswordResetConfirm`: consume the token if present and valid; otherwise emit `AuthPasswordResetTokenInvalid` from the closed-set error registry.

## Rules

- The fake JWT must NOT be a valid production token; it must be recognizably marked (`"preview": true` claim).
- `laraFetch` must NEVER accept a preview JWT (defense in depth): the live transport rejects any bearer with the `preview` claim.
- All handlers emit the canonical envelope so the FE code path is identical to production.
- Include a `?preview=slow` delay branch for skeleton verification.
- Never log the fake password back to the console (redact in observability calls).
