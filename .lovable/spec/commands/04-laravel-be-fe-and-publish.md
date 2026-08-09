# Command 04: Implement Laravel backend, Laravel frontend, and PowerShell publish script

Scope: `spec/21-app` (Lara Licensing) implementation surface.
Captured: 2026-07-18
Verbatim: "implement the back end for Laravel (BE Laravel) and the front end (FE Laravel). Also have a PowerShell file which can create the publish folder deployable on a server. The endpoint should have the self-update mechanism."

Applies when:
- Building the Lara Licensing v1 runtime.
- Producing deployable artifacts for on-prem / hosted servers.

Rules:
- Backend framework: Laravel (PHP), matches `spec/21-app` API contracts (envelope, ETag, Idempotency-Key, error taxonomy).
- Frontend: Laravel-served UI (Blade + Inertia + React), reusing existing blueprints in `spec/24-ui`.
- PowerShell script (`scripts/publish-laravel.ps1`) builds a `publish/` folder: composer install --no-dev, npm build, cache configs, zip.
- Self-update endpoint: `GET /Api/SelfUpdate/Manifest` per `spec/21-app/17-self-update-endpoint.md` v1.3.0 (Stable channel only for v1.0).
- Split-DB: Root DB + per-Reseller shard, per `spec/23-app-db/10-reseller-shard-split-db.md`.
