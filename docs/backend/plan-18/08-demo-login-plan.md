# Demo Login Plan (Plan 18, Step 8)

Fixes the demo identities, password policy, storage, and the exact claim envelope emitted after `signInWithSeedIdentity`. Sourced from real files: `backend/app/Models/User.php` (PascalCase `Users` table, `PasswordHash`, `TenantId` reseller anchor), `backend/app/Http/Resources/AuthSessionResource.php` (session wire shape), `src/lib/preview-fixtures/auth.ts` (existing FE preview auth handler + token strings), `src/lib/preview-seeds/default.ts` (existing `ADMIN_USER` + `RESELLER_USER` + `auth::credentials` map). No portal demo identity exists yet.

## Root cause the demo requires

The user's exact ask: **"When I go to the back-end login section, I should have a demo password and every section should be visible if I'm on the seeding section."** Existing FE preview code already ships two identities (`admin@lara.local` / `preview-admin`, `reseller@lara.local` / `preview-reseller`) but:

1. Nothing on the login screen surfaces them (no chips, no one-click login).
2. No portal identity exists (Portal is the third role called out in the plan).
3. BE has no matching rows, so switching from preview to a real backend breaks the "same credentials everywhere" promise.
4. The two existing FE identities live in a preview-only credentials map, not in a canonical constants module that BE seeder + FE UI can both import from.

## Demo identities (canonical)

| Slot | Email | Password | Roles | TenantId / ResellerId | Notes |
|------|-------|----------|-------|-----------------------|-------|
| admin | `admin@lara.local` | `preview-admin` | `["SuperAdmin"]` | NULL (Root scope) | Sees every admin route. |
| reseller | `reseller@lara.local` | `preview-reseller` | `["Reseller"]` | `01H000000000000000RSLLR1` (first demo reseller from `DemoResellersSeeder`) | Sees reseller portal + reseller-scoped admin views. |
| portal | `portal@lara.local` | `preview-portal` | `["PortalOperator"]` | same reseller as above | Sees portal ops screens. |

Password policy: **bcrypt cost 4** for demo identities only, so seeders finish fast in CI. Production users use the runtime-config default (cost 12). The 4/12 split is documented in `spec/28-runtime-modes/06-acceptance-criteria.md` (added in Step 46 of Plan 17); this plan just consumes it.

Emails and passwords match the strings already hard-coded in `src/lib/preview-seeds/default.ts` for admin and reseller. Portal is new. **Do not change** the two existing strings without updating every preview seed + fixture in one shot.

## Storage locations

### Backend

- Rows live in the Root DB `Users` table (per `User::$connection = 'root'`).
- Password hashes stored in `PasswordHash` column (`User::getAuthPassword()` reads this).
- Role mappings in `UserRole` table (existing `RolesSeeder` populates the enum).
- Seeder: **new** `backend/database/seeders/DemoIdentitiesSeeder.php` (Step 41). Idempotent via `updateOrCreate` on `Email`.
- Constants (new file, Step 41): `backend/app/Support/DemoIdentities.php` exports `EMAIL_ADMIN`, `EMAIL_RESELLER`, `EMAIL_PORTAL`, `PASSWORD_ADMIN`, ... `PASSWORD_PORTAL`, `ROLE_ADMIN`, `ROLE_RESELLER`, `ROLE_PORTAL`, `TENANT_ID_DEMO_RESELLER`. Seeder + tests import from here. Production runtime never touches this file.

### Frontend

- Constants (new file, Step 61): `src/lib/demo-identities.ts` re-exports the same three emails + passwords + role labels as typed constants.
- Preview credentials map: `src/lib/preview-seeds/default.ts` and `empty.ts` are updated in Step 61 to import from `src/lib/demo-identities.ts` instead of hard-coding strings. `error.ts` continues to reject.
- Login UI (Step 66): `src/routes/admin.login.tsx` grows a "Demo access" section that renders three chip buttons, each pre-fills the form and submits. Visibility gated on `import.meta.env.VITE_PREVIEW_SCENARIO !== undefined || __LARA_DEMO_LOGIN__ === true`, so demo chips never render in production.

## `signInWithSeedIdentity` API

New helper in `src/lib/auth/demo-login.ts` (Step 66):

```ts
export async function signInWithSeedIdentity(
  slot: "admin" | "reseller" | "portal",
): Promise<AuthLoginResponse>
```

Behavior: looks up email + password from `demo-identities.ts`, calls the existing `laraApi["auth.login"]` operation with the pair, returns the standard `AuthLoginResponse`. No branch on preview vs live: the same call works against either transport because BE seeders create the matching rows.

## Claim envelope (exact shape emitted after login)

Envelope union of BE `AuthSessionResource` and FE `MeUser` (already exists at `src/generated/api/schema.d.ts`):

```jsonc
{
  "Session": {
    "SessionId": "01H...",
    "UserId": 1,
    "Kind": "root",          // "root" | "reseller" | "impersonation"
    "ImpersonatorUserId": null,
    "ParentSessionId": null,
    "CreatedAt": "2026-...",
    "ExpiresAt": "2026-...",
    "EndedAt": null,
    "RevokeReason": null,
    "IsActive": true
  },
  "AccessToken": "<opaque>",
  "AccessTokenExpiresAt": "2026-...",
  "RefreshToken": "<opaque>",
  "RefreshTokenExpiresAt": "2026-...",
  "Me": {
    "Id": "01H...",
    "Email": "admin@lara.local",
    "DisplayName": "Admin Preview",
    "Roles": ["SuperAdmin"],
    "ResellerId": null,
    "CreatedAt": "2026-...",
    "UpdatedAt": "2026-..."
  }
}
```

Delta vs today's FE preview: today the `auth.login` handler in `src/lib/preview-fixtures/auth.ts` returns `AccessToken/RefreshToken` plus expiries but no `Session` envelope and no inline `Me`. Step 22 (BE `auth.me`) + Step 71 (FE claim wiring) align both surfaces on this single shape so `useAuth` reads roles from one place.

## Role -> visible section map (drives "every section visible" requirement)

| Role | Visible top-level routes (must render, not 403) |
|------|-------------------------------------------------|
| `SuperAdmin` | `/admin/overview`, `/admin/users`, `/admin/resellers`, `/admin/licenses`, `/admin/serials`, `/admin/quotas`, `/admin/audit`, `/admin/impersonation`, `/admin/features`, `/admin/prefixes`, `/admin/app-updates`, `/admin/backup`, `/admin/runtime-config`, `/admin/sessions`, `/admin/metrics` |
| `Reseller` | reseller portal shell + `/admin/users` (scoped), `/admin/licenses` (scoped), `/admin/quotas` (own requests), `/admin/audit` (scoped) |
| `PortalOperator` | portal shell only |

Verification: Step 12 (test-account matrix) enumerates one Playwright assertion per (role, route) cell; empty cells must render a documented 403 page, not blank white.

## Failure modes to prevent (each becomes a Pest / Playwright assertion)

- Silent 403: login succeeds but role check hides everything. Fix: `admin.login.tsx` post-login navigates to `/admin/overview` and asserts the KPI tile query resolves; a 403 there fails the smoke test.
- Empty-profile regression: demo login stops working when `SEED_PROFILE=empty`. Fix: `SeedProfileEmptyTest` (from Step 7) explicitly asserts the three demo users exist.
- Password drift: BE bcrypt of `preview-admin` doesn't match the FE preview creds map. Fix: single constants file per surface (`DemoIdentities.php` + `demo-identities.ts`) with a Pest test asserting `Hash::check(PASSWORD_ADMIN, seededHash)` and a Vitest test asserting the FE creds map matches `demo-identities.ts` verbatim.
- Prod bleed: demo chips render on the production login page. Fix: env gate in Step 66 + a Vitest test that asserts chips are absent when `VITE_PREVIEW_SCENARIO` and `__LARA_DEMO_LOGIN__` are both undefined.

## Feeds forward

- Step 9 (error-manage contract) classifies "demo login blocked in production" as a taxonomy row.
- Step 10 (preview-fixture parity) mirrors the `Session + Me` envelope shape into the preview auth handler.
- Step 12 (test-account matrix) consumes the role -> route table above.
- Step 41 implements `DemoIdentitiesSeeder` + `DemoIdentities.php`.
- Step 61 implements `src/lib/demo-identities.ts`.
- Step 66 implements the login UI chips + `signInWithSeedIdentity`.
- Step 71 rewires `useAuth` on the `Session + Me` envelope.
