#!/usr/bin/env bash
# Plan 09 step 76: Linux parity for run.ps1.
#
# Reads version from package.json, builds the frontend zip natively (bun +
# Compress via zip), and builds the backend zip natively (composer + zip).
# Same failure contract as run.ps1: distinct non-zero exit codes, no swallowed
# errors, prints SHA-256 for each artifact and writes checksums.txt.
#
# Exit codes match the PowerShell scripts where practical:
#   0  success
#   10 ERR_FE_VERSION_MISMATCH (unused; version comes from package.json)
#   11 ERR_FE_BUN_MISSING
#   12 ERR_FE_INSTALL_FAILED
#   13 ERR_FE_BUILD_FAILED
#   14 ERR_FE_DIST_MISSING
#   15 ERR_FE_HTACCESS_MISSING
#   16 ERR_FE_ZIP_FAILED
#   21 ERR_BE_COMPOSER_MISSING
#   22 ERR_BE_PHP_MISSING
#   23 ERR_BE_INSTALL_FAILED
#   24 ERR_BE_ENV_EXAMPLE_MISSING
#   26 ERR_BE_ZIP_FAILED
#   27 ERR_BE_REAL_ENV_PRESENT
#   30 ERR_ORCH_VERSION_MISSING
#   33 ERR_ORCH_ARTIFACT_MISSING
#   40 ERR_ORCH_ZIP_TOOL_MISSING

set -euo pipefail

log()  { printf '\033[36m[run]\033[0m %s\n' "$*"; }
err()  { code="$1"; shift; printf '\033[31mERROR [%s]:\033[0m %s\n' "$code" "$*" >&2; exit "$code"; }

REPO_ROOT="$(cd "$(dirname "$0")" && pwd)"
cd "$REPO_ROOT"

VERSION="$(node -e "process.stdout.write(require('./package.json').version || '')" 2>/dev/null || true)"
if [ -z "${VERSION:-}" ]; then
  VERSION="$(python3 -c "import json; print(json.load(open('package.json')).get('version',''))")"
fi
[ -n "$VERSION" ] || err 30 "package.json has no 'version' field"
log "Version from package.json: $VERSION"

SKIP_FRONTEND=0
SKIP_BACKEND=0
SKIP_INSTALL=0
SKIP_COMPOSER=0
for arg in "$@"; do
  case "$arg" in
    --skip-frontend) SKIP_FRONTEND=1 ;;
    --skip-backend)  SKIP_BACKEND=1 ;;
    --skip-install)  SKIP_INSTALL=1 ;;
    --skip-composer) SKIP_COMPOSER=1 ;;
    *) err 30 "Unknown flag: $arg" ;;
  esac
done

command -v zip >/dev/null 2>&1 || err 40 "'zip' is not installed"

RELEASE_DIR="$REPO_ROOT/release"
mkdir -p "$RELEASE_DIR"

# ---- Frontend --------------------------------------------------------------
FE_ZIP="$RELEASE_DIR/frontend-v${VERSION}.zip"
if [ "$SKIP_FRONTEND" -eq 0 ]; then
  command -v bun >/dev/null 2>&1 || err 11 "bun is not on PATH"
  if [ "$SKIP_INSTALL" -eq 0 ]; then
    log "bun install --frozen-lockfile"
    bun install --frozen-lockfile || err 12 "bun install failed"
  fi
  log "bun run build"
  bun run build || err 13 "bun run build failed"
  [ -d dist ] || err 14 "dist/ missing after build"
  [ -n "$(ls -A dist 2>/dev/null)" ] || err 14 "dist/ is empty"

  FE_STAGE="$RELEASE_DIR/frontend"
  rm -rf "$FE_STAGE"
  mkdir -p "$FE_STAGE"
  cp -R dist/. "$FE_STAGE/"

  HTACCESS_SRC="$REPO_ROOT/scripts/cpanel/.htaccess"
  [ -f "$HTACCESS_SRC" ] || err 15 "scripts/cpanel/.htaccess not found"
  cp "$HTACCESS_SRC" "$FE_STAGE/.htaccess"
  log "Copied .htaccess (SPA fallback + cache headers)"

  rm -f "$FE_ZIP"
  ( cd "$FE_STAGE" && zip -r -q "$FE_ZIP" . ) || err 16 "zip failed"
  [ -f "$FE_ZIP" ] || err 16 "Zip not produced at $FE_ZIP"
else
  log "Skipping frontend"
fi

# ---- Backend ---------------------------------------------------------------
BE_ZIP="$RELEASE_DIR/backend-v${VERSION}.zip"
if [ "$SKIP_BACKEND" -eq 0 ]; then
  command -v composer >/dev/null 2>&1 || err 21 "composer is not on PATH"
  command -v php      >/dev/null 2>&1 || err 22 "php is not on PATH"
  [ ! -f backend/.env ]        || err 27 "backend/.env is present; refuse to zip (secrets must not ship)"
  [ -f backend/.env.example ]  || err 24 "backend/.env.example missing"

  if [ "$SKIP_COMPOSER" -eq 0 ]; then
    log 'composer install --no-dev --optimize-autoloader --prefer-dist'
    ( cd backend && composer install --no-dev --optimize-autoloader --prefer-dist --no-interaction ) \
      || err 23 "composer install failed"
  fi

  # Best-effort cache warm; non-fatal (target rebuilds with real .env).
  ( cd backend && for c in config:clear route:clear view:clear config:cache route:cache view:cache; do \
      php artisan "$c" >/dev/null 2>&1 || true; \
    done )

  BE_STAGE="$RELEASE_DIR/backend"
  rm -rf "$BE_STAGE"
  mkdir -p "$BE_STAGE"
  for item in app bootstrap config database public resources routes tests vendor \
              artisan composer.json composer.lock phpunit.xml phpstan.neon .env.example; do
    if [ -e "backend/$item" ]; then
      log "Copy $item"
      cp -R "backend/$item" "$BE_STAGE/"
    fi
  done

  for rel in bootstrap/cache storage/framework/cache storage/framework/sessions storage/framework/views storage/logs; do
    mkdir -p "$BE_STAGE/$rel"
    find "$BE_STAGE/$rel" -type f ! -name '.gitignore' -delete 2>/dev/null || true
  done

  COMMIT="$(git -C "$REPO_ROOT" rev-parse --short HEAD 2>/dev/null || echo unknown)"
  cat > "$BE_STAGE/PUBLISH-NOTES.md" <<EOF
# Licensing Portal backend release

- Version: ${VERSION}
- Commit:  ${COMMIT}
- Built:   $(date -u +%Y-%m-%dT%H:%M:%SZ)

## Deploy (cPanel)

1. Upload backend-v${VERSION}.zip and extract to the application root.
2. Copy .env.example to .env and fill DB, mail, and lara.* settings.
3. Run: php artisan key:generate --force
4. Run: php artisan migrate --force
5. Run: php artisan storage:link
6. Run: php artisan about  # sanity check
7. Point the document root at public/.

Rollback: keep the previous release zip on disk and re-extract it.
EOF

  rm -f "$BE_ZIP"
  ( cd "$BE_STAGE" && zip -r -q "$BE_ZIP" . ) || err 26 "zip failed"
  [ -f "$BE_ZIP" ] || err 26 "Zip not produced at $BE_ZIP"
else
  log "Skipping backend"
fi

# ---- Verify + checksums ----------------------------------------------------
[ -f "$FE_ZIP" ] || err 33 "Missing artifact: $FE_ZIP"
[ -f "$BE_ZIP" ] || err 33 "Missing artifact: $BE_ZIP"

FE_SHA="$(sha256sum "$FE_ZIP" | awk '{print $1}')"
BE_SHA="$(sha256sum "$BE_ZIP" | awk '{print $1}')"

# ---- Combined bundle (frontend + backend + DEPLOY.md) ----------------------
# Single-upload artifact for cPanel-style hosts: extract once, then move
# frontend/ and backend/ into their document roots.
COMBINED_ZIP="$RELEASE_DIR/licensing-portal-v${VERSION}.zip"
COMBINED_STAGE="$RELEASE_DIR/combined"
DEPLOY_MD="$REPO_ROOT/scripts/cpanel/DEPLOY.md"
[ -f "$DEPLOY_MD" ] || err 33 "scripts/cpanel/DEPLOY.md missing (required for combined bundle)"
rm -rf "$COMBINED_STAGE"
mkdir -p "$COMBINED_STAGE/frontend" "$COMBINED_STAGE/backend"
# Reuse the already-staged trees to avoid re-copying the world.
if [ -d "$RELEASE_DIR/frontend" ]; then
  cp -R "$RELEASE_DIR/frontend/." "$COMBINED_STAGE/frontend/"
else
  ( cd "$COMBINED_STAGE/frontend" && unzip -q "$FE_ZIP" )
fi
if [ -d "$RELEASE_DIR/backend" ]; then
  cp -R "$RELEASE_DIR/backend/." "$COMBINED_STAGE/backend/"
else
  ( cd "$COMBINED_STAGE/backend" && unzip -q "$BE_ZIP" )
fi
cp "$DEPLOY_MD" "$COMBINED_STAGE/DEPLOY.md"
{
  printf '%s  frontend-v%s.zip\n' "$FE_SHA" "$VERSION"
  printf '%s  backend-v%s.zip\n'  "$BE_SHA" "$VERSION"
} > "$COMBINED_STAGE/checksums.txt"
rm -f "$COMBINED_ZIP"
( cd "$COMBINED_STAGE" && zip -r -q "$COMBINED_ZIP" . ) || err 41 "combined zip failed"
[ -f "$COMBINED_ZIP" ] || err 41 "Combined zip not produced at $COMBINED_ZIP"
COMBINED_SHA="$(sha256sum "$COMBINED_ZIP" | awk '{print $1}')"

CHECKSUMS="$RELEASE_DIR/checksums.txt"
{
  printf '%s  frontend-v%s.zip\n'             "$FE_SHA"       "$VERSION"
  printf '%s  backend-v%s.zip\n'              "$BE_SHA"       "$VERSION"
  printf '%s  licensing-portal-v%s.zip\n'     "$COMBINED_SHA" "$VERSION"
} > "$CHECKSUMS"

log "OK -> $FE_ZIP"
log "     SHA-256: $FE_SHA"
log "OK -> $BE_ZIP"
log "     SHA-256: $BE_SHA"
log "OK -> $COMBINED_ZIP"
log "     SHA-256: $COMBINED_SHA"
log "checksums.txt written to $CHECKSUMS"

printf 'VERSION=%s\n'          "$VERSION"
printf 'FRONTEND=%s\n'         "$FE_ZIP"
printf 'FRONTEND_SHA256=%s\n'  "$FE_SHA"
printf 'BACKEND=%s\n'          "$BE_ZIP"
printf 'BACKEND_SHA256=%s\n'   "$BE_SHA"
printf 'COMBINED=%s\n'         "$COMBINED_ZIP"
printf 'COMBINED_SHA256=%s\n'  "$COMBINED_SHA"
printf 'CHECKSUMS=%s\n'        "$CHECKSUMS"

