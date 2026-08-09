# Seed Profiles Plan (Plan 18, Step 7)

Defines the three seed profiles (`default`, `empty`, `error`), the env-var wiring, and the exact seeder chain each profile invokes. Sourced from real code: `backend/database/seeders/DatabaseSeeder.php` (existing `CHAIN` constant + `callOrSkip` mechanism) and `spec/28-runtime-modes/03-preview-fixture-contract.md` (FE preview scenario contract).

## Profile matrix

| Profile | FE preview scenario | BE `SEED_PROFILE` env | Purpose |
|---------|---------------------|-----------------------|---------|
| `default` | `default` | `default` | Populated happy path. Every dashboard tile non-zero, every list has >= 1 page. Demo login enabled. |
| `empty` | `empty` | `empty` | Fresh install. Only catalog seeders (roles, features, closed-sets, runtime-config defaults) run. Every list renders empty-state. Demo login **still enabled** (identities are catalog-level, not domain data). |
| `error` | `error` | `error` | Deterministic error surface. `default` rows plus rows that trigger classified errors (expired licences, orphaned bindings, stalled backup, quota over-limit). |

Profile contract INV: FE preview fixtures must match BE seed profile row counts within a documented tolerance (locked in Step 10). If profiles diverge, the FE renders empty but BE returns rows (or vice versa) and the demo/testing surface lies.

## Env-var wiring

- **Backend**: `SEED_PROFILE` read by `DatabaseSeeder::run()`. Default value `default`. Read via `env('SEED_PROFILE', 'default')` once at the top of `run()`, then dispatched to profile-specific chain.
- **Frontend**: `import.meta.env.VITE_PREVIEW_SCENARIO` (already exists per Spec 28 section 02). No change needed; Step 10 confirms parity of scenario names.
- **CI / Playwright**: each spec declares `data-seed` on its test descriptor; the harness sets both `SEED_PROFILE` (BE) and `VITE_PREVIEW_SCENARIO` (FE) before boot.

## Seeder chains per profile

### `default` chain (Step 50 implementation)

Runs in this exact order (idempotent):

1. `RootSeeder` (existing) - runtime-config + delegated feature catalog.
2. `FeatureCatalogSeeder` (existing).
3. `ClosedSetsSeeder` (existing) - category/tier/environment enums.
4. `RolesSeeder` (existing) - `user_roles` enum parity.
5. `DemoIdentitiesSeeder` (new, Step 41) - 3 demo users + `UserRole` mapping + one password-reset token fixture.
6. `DemoResellersSeeder` (new, Step 42) - 8 resellers.
7. `DemoUsersSeeder` (new, Step 42) - 40 users across resellers.
8. `DemoPrefixesSeeder` (new, Step 49) - 12 prefixes.
9. `DemoLicensesSeeder` (new, Step 43) - 120 licences + ledger entries.
10. `DemoBindingsSeeder` (new, Step 44) - 200 machine bindings.
11. `DemoSerialsSeeder` (new, Step 47) - 80 serials.
12. `DemoQuotaRequestsSeeder` (new, Step 45) - 24 quota requests.
13. `DemoSessionsSeeder` (new, Step 44) - 30 auth sessions + 3 impersonation rows.
14. `DemoAuditSeeder` (new, Step 48) - 500 audit rows across 30d.
15. `DemoAppUpdatesSeeder` (new, Step 47) - 6 releases + 12 assets.
16. `DemoBackupSeeder` (new, Step 49) - 3 backup export rows.
17. `ShardSeeder` (existing) - shard fixtures.
18. `E2EFixturesSeeder` (existing) - retained for legacy E2E; will be reviewed for redundancy in Step 12.

### `empty` chain (Step 46 implementation)

Runs only catalog + enum + runtime-config seeders. NO domain data.

1. `RootSeeder`
2. `FeatureCatalogSeeder`
3. `ClosedSetsSeeder`
4. `RolesSeeder`
5. `DemoIdentitiesSeeder` - the 3 demo users are still seeded because demo login must work in empty mode (the user explicitly asked for "demo password every section visible if I'm on the seeding section"). Empty state = no domain data, not no accounts.

### `error` chain (Steps 51-55 implementation)

Runs the full `default` chain, then `ErrorProfileSeeder` (new) which layers:

- 3 expired licences with `ExpiresAt = now - 7d` and still bound (drives license-expiry error path).
- 5 orphaned `MachineBinding` rows pointing at non-existent licences (drives binding-integrity error).
- 2 `QuotaRequest` rows over the reseller quota cap (drives quota-over-limit error).
- 1 `AppUpdate` row with `Status = failed` + last error text (drives update-manifest error tile).
- 4 `AuthSession` rows with `RevokedAt` in the future (drives session-integrity error).
- 1 `BackupExport` row with `Status = stalled` older than 24h (drives backup error surface).

Each row is tagged in a dedicated `SeederNotes` column (added in Step 51 migration) so error paths can be correlated in tests without brittle string matching.

## `DatabaseSeeder` refactor (Step 50)

Refactor the existing `CHAIN` constant into three constants:

```php
private const CHAIN_DEFAULT = [ /* 17 seeders */ ];
private const CHAIN_EMPTY = [ RootSeeder::class, FeatureCatalogSeeder::class,
    ClosedSetsSeeder::class, RolesSeeder::class, DemoIdentitiesSeeder::class ];
private const CHAIN_ERROR = [ ...self::CHAIN_DEFAULT, ErrorProfileSeeder::class ];

public function run(): void
{
    $profile = env('SEED_PROFILE', 'default');
    Log::info('DatabaseSeeder profile', ['profile' => $profile]);
    $chain = match ($profile) {
        'empty' => self::CHAIN_EMPTY,
        'error' => self::CHAIN_ERROR,
        default => self::CHAIN_DEFAULT,
    };
    foreach ($chain as $seeder) { $this->callOrSkip($seeder); }
}
```

Keep the existing `callOrSkip` (loud-skip on missing classes) so Steps 41-55 can land one at a time without breaking the chain.

## Verification hooks (informs Step 13 Pest plan)

- `SeedProfileDefaultTest` - asserts row counts per Step 6 (>= 8 resellers, >= 120 licences, >= 24 quota requests, 500 audit rows, 30 sessions).
- `SeedProfileEmptyTest` - asserts `Reseller`, `License`, `Serial`, `MachineBinding`, `QuotaRequest`, `AuthSession`, `AppUpdate` all have 0 rows; asserts `User` has exactly 3 rows (demo identities); asserts `Roles`, `Features`, `Prefix` catalogs are populated.
- `SeedProfileErrorTest` - asserts each error signature row exists exactly once.

## Feeds forward

- Step 8 binds the demo login screen to the three identities seeded by `DemoIdentitiesSeeder` (present in all three profiles).
- Step 9 error-manage contract classifies the error rows produced by `ErrorProfileSeeder` into taxonomy codes.
- Step 10 preview-fixture parity plan mirrors these three chains 1:1 into FE preview fixtures.
- Step 14 observability plan uses the `SeederNotes` tag as a correlation vector.
- Step 50 implements the `DatabaseSeeder` profile switch above.
