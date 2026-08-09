# Test Data & E2E Fixtures

Plan 10 step 40. Canonical reference for what the Playwright suite needs, where it comes from, and how to reproduce it locally or in CI. Every artisan command, seeder, and env var referenced by `tests/e2e/**` is documented here. When you add a new spec that touches persisted state, add its data contract to this file in the same PR.

## 1. Environment variables

All e2e env vars are read via `tests/e2e/helpers/env.ts` (`requireEnv` fails loud; `optionalEnv` falls back). Never inline `process.env` in a spec.

| Variable | Required | Default | Consumer | Purpose |
| --- | --- | --- | --- | --- |
| `E2E_BASE_URL` | no | `http://localhost:8080` | `playwright.config.ts`, `helpers/env.ts` | App base URL. In CI, points at the ephemeral preview. |
| `E2E_ADMIN_EMAIL` | yes | none | `fixtures/lara-auth.ts` (`signInAsAdmin`) | Seeded SuperAdmin email. Must match `E2EFixturesSeeder`. |
| `E2E_ADMIN_PASSWORD` | yes | none | `fixtures/lara-auth.ts` (`signInAsAdmin`) | Seeded SuperAdmin password. Plaintext, only used to POST `/Api/Auth/Login`. |
| `E2E_ARTISAN_CMD` | no | `php backend/artisan` | `helpers/backend-token.ts`, `helpers/backend-reseller.ts` | Full artisan invocation. Override for containerized backends (e.g. `docker compose exec app php artisan`). |
| `E2E_ARTISAN_CWD` | no | `process.cwd()` | same | Working dir for artisan spawn. |
| `E2E_RESELLER_ID` | no | none | `helpers/backend-reseller.ts` | Hard override for reseller id. Skips artisan when set. Positive integer only. |
| `CI` | no | none | `playwright.config.ts` lines 27-29 | Enables `forbidOnly`, `retries: 2`, `workers: 2`. |

Never commit real values. Local dev uses a git-ignored `.env.e2e`; CI reads from repo secrets.

## 2. Backend seeders

`backend/database/seeders/DatabaseSeeder.php` orchestrates the following in order. All seeders are idempotent (`updateOrCreate` / `firstOrCreate`) so re-running never duplicates rows.

| Seeder | Produces | Referenced by |
| --- | --- | --- |
| `ClosedSetsSeeder` | Enum-like reference rows (roles, statuses, event kinds) | Every controller that validates against `src/lib/closed-sets.ts` |
| `RolesSeeder` | Role rows for SuperAdmin, Admin, Reseller, EndUser | `signInAsAdmin`, RBAC middleware |
| `RootSeeder` | Root workspace (`WorkspaceId=1`), root prefix | Metrics fanout, Prefix routes |
| `ShardSeeder` | Local dev shard entries | `MetricsController::shardStatus` probes |
| `FeatureCatalogSeeder` | Core feature definitions consumed by `FeatureService` | License issue path |
| `E2EFixturesSeeder` | The SuperAdmin user matching `E2E_ADMIN_EMAIL` / `E2E_ADMIN_PASSWORD`, one reseller, one prefix | Every e2e spec |

Bootstrap sequence (mandatory, in order):

```bash
php backend/artisan migrate:fresh --seed
php backend/artisan db:seed --class=E2EFixturesSeeder   # only if you need to reset e2e state
```

Any spec that assumes a fresh DB (e.g. `auth-register-bootstrap.spec.ts` asserting "Registration is closed") requires the full seed. Do not truncate individual tables between specs; Playwright's parallelization tolerates seeded shared state, not mid-run truncation.

## 3. Artisan commands (E2E-only helpers)

These commands are gated behind an `E2E_ENABLED` config flag and MUST NOT run in production. See `backend/config/app.php`.

### `e2e:mint-reset-token {email}`

File: `backend/app/Console/Commands/E2EMintPasswordResetTokenCommand.php` (signature line 33).

Purpose: password reset flows cannot be tested end-to-end because the plaintext token is only sent by email. This command mints a single-use token for the given email and prints it as JSON so Playwright can redeem it.

Output (stdout, single line):

```json
{"token":"<plaintext>","email":"<email>","expires_at":"<ISO-8601>"}
```

Consumer: `tests/e2e/helpers/backend-token.ts` (`mintPasswordResetToken`), used by `auth-password-reset.spec.ts`.

### `e2e:first-reseller-id`

File: `backend/app/Console/Commands/E2EFirstResellerIdCommand.php` (signature line 23).

Purpose: reseller specs need a real seeded reseller id to hit `/reseller/$resellerId/`. Prints the lowest active `ResellerId` as JSON so the id is deterministic without hardcoding a magic number.

Output:

```json
{"reseller_id": 42}
```

Consumer: `tests/e2e/helpers/backend-reseller.ts` (`resolveFirstResellerId`), used by `reseller-dashboard.spec.ts`. Override with `E2E_RESELLER_ID=<n>` to skip artisan.

## 4. Storage state

`tests/e2e/helpers/storage-state.ts` persists the authenticated Sanctum bearer to `tests/e2e/.auth/admin.json` so `signInAsAdmin` is idempotent across spec files. The path is gitignored. Deleted on `playwright test --project=<name>` first invocation of the fixture per worker.

## 5. Spec-to-data matrix

| Spec | Requires artisan | Requires seed | Env vars |
| --- | --- | --- | --- |
| `health.spec.ts` | no | no | `E2E_BASE_URL` |
| `auth-login.spec.ts` | no | `E2EFixturesSeeder` | `E2E_ADMIN_EMAIL`, `E2E_ADMIN_PASSWORD` |
| `auth-register-bootstrap.spec.ts` | no | `E2EFixturesSeeder` (asserts closed) | none |
| `auth-password-reset.spec.ts` | `e2e:mint-reset-token` | `E2EFixturesSeeder` | `E2E_ADMIN_EMAIL`, `E2E_ARTISAN_CMD` |
| `admin-dashboard.spec.ts` | no | full seed | admin creds |
| `admin-license-crud.spec.ts` | no | full seed | admin creds |
| `admin-quota-approval.spec.ts` | no | full seed | admin creds |
| `admin-impersonation.spec.ts` | no | full seed | admin creds |
| `reseller-dashboard.spec.ts` | `e2e:first-reseller-id` | full seed | admin creds, `E2E_ARTISAN_CMD` |
| `portal-serial-lookup.spec.ts` | no | full seed | admin creds |
| `portal-update-download.spec.ts` | no | full seed | admin creds |
| `visual-font-baseline.spec.ts` | no | full seed | admin creds |
| `a11y-axe.spec.ts` | no | full seed | admin creds |

## 6. Local reproduction

```bash
# 1. Fresh backend
php backend/artisan migrate:fresh --seed

# 2. Env (git-ignored)
cat > .env.e2e <<'EOF'
E2E_BASE_URL=http://localhost:8080
E2E_ADMIN_EMAIL=admin@example.test
E2E_ADMIN_PASSWORD=ChangeMe!123
E2E_ARTISAN_CMD=php backend/artisan
EOF

# 3. Dev server (separate terminal)
bun run dev

# 4. Suite
set -a; source .env.e2e; set +a
bun run test:e2e
```

## 7. CI contract (feeds steps 41-43)

- Backend workflow (`backend-e2e.yml`, step 41) runs `migrate:fresh --seed` against a fresh Postgres service before `pest`.
- Frontend workflow (`frontend-e2e.yml`, step 42) starts the backend + Vite preview, then runs `playwright test` with the env vars in section 1 pulled from `secrets.E2E_*`.
- Nightly workflow (`nightly-e2e.yml`, step 43) runs the full matrix (Chromium + Firefox + WebKit) and uploads `playwright-report/`, `test-results/`, and `docs/ui-baselines/*.png` as artifacts.

## 8. Extending

When adding a spec that needs new persisted state:

1. Prefer extending `E2EFixturesSeeder` over creating a new seeder. Idempotent inserts only.
2. If plaintext data must be surfaced to the browser (tokens, one-time codes), add an `E2E*Command` under `backend/app/Console/Commands/` guarded by `E2E_ENABLED`.
3. Add a helper under `tests/e2e/helpers/` that invokes the command via `E2E_ARTISAN_CMD`.
4. Update sections 1, 2, 3, and 5 of this document in the same PR.
