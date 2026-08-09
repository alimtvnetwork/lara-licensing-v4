# Deploy Rehearsal Report, v1

**Version:** 0.400.0
**Updated:** 2026-07-19
**Status:** Blocking findings. Do NOT run `scripts/publish-frontend.ps1` against a real cPanel host in its current form.

Honest disclosure: no real cPanel account is attached to this sandbox. This rehearsal is a **local dry-run** of the exact steps `scripts/publish-frontend.ps1`, `scripts/publish-backend.ps1`, and `scripts/cpanel/DEPLOY.md` prescribe, executed against the real repository at `v0.399.0`. Every failure below is reproducible on any workstation and will manifest identically on a real host.

---

## 1. Rehearsal environment

| Item | Value |
|------|-------|
| Repo commit | working tree at v0.399.0 |
| Node/Bun | bun 1.x (present) |
| PowerShell 7 | **absent** in sandbox; commands transliterated to bash equivalents |
| PHP 8.3 | **absent** in sandbox |
| Composer 2 | **absent** in sandbox |
| Target host | none (dry-run) |

Absence of pwsh/php/composer is expected in this sandbox and is not itself a finding. Findings below are observations about the **artifacts and scripts themselves**, not the sandbox.

---

## 2. Blocking findings (must fix before any real cPanel rehearsal)

### F1. Frontend build output is Nitro SSR for Cloudflare Workers, not a static SPA

**Severity:** Blocker.
**Where:** `bun run build` output vs `scripts/publish-frontend.ps1` + `scripts/cpanel/DEPLOY.md`.

Observed `dist/` layout after `bun run build`:

```
dist/
  client/         _headers, assets/, favicon.ico    <- no index.html
  server/         index.mjs, wrangler.json, _libs/, _ssr/, _chunks/
  nitro.json
  package.json
  package-lock.json
```

`dist/server/wrangler.json` declares `main: "index.mjs"` with `compatibility_flags: ["nodejs_compat"]`. This is a **Cloudflare Workers / Nitro SSR bundle**, not a static SPA. There is no `index.html`.

`scripts/publish-frontend.ps1` (lines 100 to 108) copies `dist/*` verbatim into `release/frontend/` and appends `scripts/cpanel/.htaccess`. `scripts/cpanel/DEPLOY.md` then instructs the operator to upload the folder to Apache `public_html`. Apache cannot execute `dist/server/index.mjs` (needs Node/workerd) and there is no `index.html` to serve, so the site would 404 on every route including `/`. The `.htaccess` SPA fallback rewrites everything to `/index.html`, which does not exist.

**Fix (pick one, do not defer):**

1. **Recommended.** Add a static-SPA build mode (client-only Vite build) behind an npm script such as `build:spa`, emitting a real `dist/index.html` + `dist/assets/`. Have `publish-frontend.ps1` invoke that script instead of `bun run build`. Verify by opening the built `dist/index.html` in a browser and confirming client-side routing works with `.htaccess` fallback.
2. **Alternative.** Abandon cPanel as the frontend target and publish the SSR bundle to Cloudflare Workers (`wrangler deploy dist/server/wrangler.json`). Delete `scripts/publish-frontend.ps1` and `scripts/cpanel/.htaccess`, and rewrite `scripts/cpanel/DEPLOY.md` as a backend-only deploy.

Until F1 is fixed, the frontend publish script produces a zip that will not serve on Apache.

### F2. `backend/composer.json` version is stale and desynced

**Severity:** High (breaks release integrity checks).
**Where:** `backend/composer.json`.

- `package.json` version: `0.399.0`
- `backend/composer.json` version: `0.389.0`

Plan 10 Step 5 was marked deferred, but `composer.json` was silently backfilled with a `version` field at some earlier point and now drifts by 10 versions. `linter-scripts/check-version-sync.py` does not include it in its check, so this drift is invisible to CI.

`scripts/publish-backend.ps1` bases its version pin only on `package.json`, so `publish-backend.ps1 -Version 0.399.0` will zip a backend whose `composer.json` still claims 0.389.0. Downstream `php artisan about` or update-manifest verification would show the wrong version.

**Fix:**
- Bump `backend/composer.json` to `0.400.0` in the same commit as this report.
- Extend `linter-scripts/check-version-sync.py` to a three-way sync (`package.json`, `backend/composer.json`, `README.md`) with a hard-fail exit code.
- Add a CI job to `backend-static-analysis.yml` invoking it.

### F3. `publish-frontend.ps1` has never been executed end-to-end

**Severity:** High.
**Where:** `scripts/publish-frontend.ps1`.

There is no CI job that invokes `publish-frontend.ps1`. `.github/workflows/release.yml`, `release-smoke.yml`, and every other workflow ignore it. Combined with F1, the script has been shipping since v0.321.0 (Plan 09) without a single verified green run. The exit codes 10 to 16 are aspirational, not observed.

**Fix:**
- Add a `release-dry-run.yml` GitHub Actions workflow that runs `pwsh -File scripts/publish-frontend.ps1 -Version 0.0.0-ci` on `ubuntu-latest` and asserts a non-empty zip is produced. Require it on `main`.
- Same for `publish-backend.ps1` under a `php8.3` runner with composer preinstalled.

### F4. `publish-frontend.ps1` requires PowerShell 7; `.github/workflows` do not install it

**Severity:** Medium.

The script header says `#Requires -Version 7.0`. Ubuntu runners ship pwsh 7 preinstalled on `ubuntu-latest`, but the release workflow currently does not exercise it. Once F3 lands, verify the `pwsh` shell is used explicitly (`shell: pwsh`) and pin the version.

---

## 3. High-risk findings (rehearse before shipping)

### F5. `scripts/cpanel/.htaccess` SPA fallback assumes `index.html`

Depends on F1. Once F1 is fixed and the build emits `index.html`, verify:
- Deep link to `/admin/login` returns 200 and serves the SPA shell.
- Hashed asset under `/assets/*.js` is served with `Cache-Control: max-age=31536000, immutable`.
- `mod_rewrite` is present. cPanel shared plans usually ship it; some restricted plans disable it. Add a boot-time check that runs `curl -sI https://host/nonexistent-deep-link` and asserts 200 not 404.

### F6. Backend zip ships `vendor/` but no PHP version guard

`scripts/publish-backend.ps1` documents `composer install --no-dev --optimize-autoloader` before zipping. The zip is only portable across hosts running the **exact same PHP minor version** used to install. If the host ships PHP 8.2 and the CI runner used PHP 8.3, `composer.lock` extension constraints can fail at runtime.

**Fix:**
- Add `php artisan about` output to `PUBLISH-NOTES.md` inside the zip, including the PHP version composer install ran under.
- Document the required host PHP version in `scripts/cpanel/DEPLOY.md` (currently silent on this).

### F7. `.env` bootstrap is manual, no rehearsal script

`DEPLOY.md` step 2 tells the operator to hand-fill `.env` and run `php artisan key:generate --force`. There is no rehearsal helper that validates the resulting `.env` (required keys present, DB reachable, mail probe, `APP_URL` matches CORS whitelist). First real deploy will discover missing keys interactively.

**Fix:**
- Add `backend/app/Console/Commands/DeployPreflightCommand.php` (`php artisan deploy:preflight`) that asserts every required env key, opens each shard DB connection, and probes SMTP. Non-zero exit on any failure.
- Reference it in `DEPLOY.md` step 4 before `migrate --force`.

### F8. Migrations run via `--force` with no lock

`DEPLOY.md` step 4 calls `php artisan migrate --force`. If a shared cron or a parallel deploy also invokes migrate, two processes race on the schema. Laravel's migrator does not take an advisory lock by default on MySQL.

**Fix:**
- Wrap deploy migrations in a `SELECT GET_LOCK('licensing_portal_deploy', 30)` guard, or use Laravel's `--isolated` flag (Laravel 11).
- Amend `DEPLOY.md` step 4 accordingly.

### F9. No rollback rehearsal

`DEPLOY.md` says "keep the previous zip, re-extract to rollback". This does not roll back the DB. Any deploy that migrated forward and then failed leaves the host in a partial state.

**Fix:**
- Every migration must ship a `down()` that is tested by `MigrationsAreIdempotentTest` (Plan 10 deferred Step 6) plus a `MigrationsAreReversibleTest`.
- `DEPLOY.md` gains a "Rollback" section with the exact `php artisan migrate:rollback --step=N` command mapped to the deployed version.

---

## 4. Medium findings

### F10. Frontend zip has no integrity manifest

`publish-frontend.ps1` prints SHA-256 of the zip to stdout, but nothing inside the zip references it. Once uploaded to cPanel via FileManager, the operator has no way to re-verify contents. `DEPLOY.md` "Verifying the bundle" section tells them to `sha256sum` the outer zip, which is unhelpful after extraction.

**Fix:** emit `MANIFEST.txt` inside the zip listing every file with its SHA-256; add a `Verify-Deploy.ps1` companion that walks the deployed directory and compares.

### F11. `publish-lara.ps1` (CLI publisher) has never been rehearsed against the backend `POST /Admin/AppUpdates` endpoint

`spec/21-app/18-publishing-powershell.md` defines the contract, but there is no e2e spec exercising the full mint-upload-manifest-verify loop. `admin-app-updates.spec.ts` does not exist. The 9560 to 9569 exit codes are unproved.

**Fix:** add `tests/e2e/specs/admin-app-updates.spec.ts` that boots the backend, runs `publish-lara.ps1 -DryRun` first, then asserts the manifest that would be posted matches the schema.

### F12. `LARA_ADMIN_TOKEN` handling relies on operator discipline

`publish-lara.ps1` reads the admin JWT from an env var. There is no timeout, no scope check, no audit log of which token published which version. A leaked admin token can publish arbitrary binaries to the update channel.

**Fix:** issue short-lived (10 min) publisher tokens via `POST /Admin/Publishers/IssueToken` with a `publish:updates` scope only, invalidated after one manifest post. Emit a `PublisherTokenIssued` and `PublisherTokenRevoked` audit event.

---

## 5. Low findings

- **F13.** `scripts/cpanel/DEPLOY.md` uses example host name `licensing.example.com` in the same block that says "adjust names to your host". Add a top banner: **replace every `example.com` before executing**.
- **F14.** No screenshot / video of a completed deploy exists. Once F1 to F9 are resolved, capture a screen recording of one rehearsal and store the link (not the video) in `docs/deploy/rehearsal-2026-XX.md`.
- **F15.** `publish-backend.ps1` exit code 27 (`ERR_BE_REAL_ENV_PRESENT`) refuses to zip if `backend/.env` exists. There is no positive test proving this guard actually triggers. Add one in the same `release-dry-run.yml` job.

---

## 6. Confidence rating for a real deploy today

**Do not attempt.** With F1 unresolved, the frontend publish path produces an unservable artifact. Even if the operator manually re-runs `vite build` (which would still be an SSR bundle in this project), Apache cannot execute it.

Path to a green rehearsal:

1. Fix F1 (add static SPA build target or move to Workers).
2. Fix F2 (composer version pin + three-way linter).
3. Fix F3 (release-dry-run CI).
4. Land F5, F6, F7 in that order.
5. Book one production-like cPanel account, upload the zip, execute `DEPLOY.md` end to end, and update this file with observed timings and errors.

Steps 1 to 4 are pure code + CI; step 5 is the only one that needs a real host.

---

## 7. Traceability

- Feeds `spec/25-app-audit/02-gap-catalog.md` gaps: deploy blockers, RBAC-adjacent (F12).
- Feeds `spec/25-app-audit/03-plan-300-steps.md` steps 283 to 300.
- Supersedes the implicit assumption in Plan 09 that publish scripts are "done at v0.321.0".

*Deploy rehearsal report v1, 2026-07-19.*
