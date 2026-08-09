# Licensing Portal, Combined cPanel Release

This single zip contains everything needed to run the Licensing Portal on a
cPanel-style shared host:

```
frontend/         Static SPA build with .htaccess (SPA fallback + cache)
backend/          Laravel 11 backend (vendor/ prebuilt, no .env)
checksums.txt     SHA-256 of each inner zip (if present) and of this bundle
DEPLOY.md         This file
```

## One-time layout on the host

Recommended directory layout (adjust names to your host):

```
~/licensing.example.com/          <- primary domain document root
    (frontend contents)
~/licensing.example.com/api/      <- subfolder or subdomain
    (backend contents; document root points at backend/public)
```

For a subdomain layout (recommended), create `api.licensing.example.com` in
cPanel and point its document root at `~/licensing-api/public`.

## Frontend install

1. Delete the previous contents of the frontend document root.
2. Upload everything under `frontend/` into that directory (including the
   `.htaccess` file, which enables SPA fallback + long-lived cache on hashed
   assets and forces HTTPS).
3. Verify `https://<frontend-host>/` returns the SPA and that a deep link
   like `https://<frontend-host>/admin/login` also returns the SPA (not
   Apache 404). If deep links 404, `.htaccess` is missing or `mod_rewrite`
   is disabled on the host.

## Backend install

1. Upload everything under `backend/` into the backend directory (e.g.
   `~/licensing-api/`). This ships with `vendor/` prebuilt: do NOT run
   `composer install` on the host unless you have shell access and PHP 8.3.
2. Copy `.env.example` to `.env` and fill:
   - `APP_KEY` (generate: `php artisan key:generate --force`)
   - `APP_URL`, `LARA_FRONTEND_URL`
   - `DB_*` (Root DB + shard DBs, one connection per reseller shard)
   - `MAIL_*`
   - Any `LARA_*` overrides your deployment needs.
3. Ensure the web server's document root points at `backend/public/`, not
   at `backend/`.
4. Run once from SSH:
   ```
   php artisan migrate --force
   php artisan storage:link
   php artisan config:cache
   php artisan route:cache
   php artisan about
   ```
5. If SSH is not available, upload `.env`, then let the first web request
   run migrations via your host's PHP CLI cron. Do NOT commit `.env`.

## Rollback

Keep the previous `licensing-portal-v<v>.zip` on disk. To roll back, empty
the frontend and backend directories and re-extract the previous zip. The
backend keeps schema forwards-compatible for one minor version, so
downgrading by one patch is safe; downgrading further requires reversing
any migrations you rolled forward.

## Verifying the bundle

```
sha256sum licensing-portal-v<v>.zip
# compare against the sha printed in the release notes / checksums.txt
```
