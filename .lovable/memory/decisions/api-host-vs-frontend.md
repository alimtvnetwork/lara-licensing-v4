---
id: DEC-API-HOST-001
title: LaraLicensingV1 API host vs TanStack frontend split
status: Accepted
date: 2026-07-15
---

# Decision: LaraLicensingV1 is a separate Laravel service; this repo is its TanStack frontend

## Context

`spec/21-app/` names the product `LaraLicensingV1` and specifies endpoints
(`10-endpoints.md`), contracts (`11-api-contracts/`), error taxonomy
(`12-error-taxonomy.md`), audit logging (`13-audit-logging.md`), rate
limiting (`14-rate-limiting.md`), and lifecycle (`15-license-lifecycle.md`)
in a form idiomatic to Laravel (Sanctum tokens, Laravel middleware, MySQL
schema in `spec/23-app-db/01-schema.md`).

This repository is a TanStack Start v1 app running on Cloudflare Workers
with Vite 7. It cannot host PHP.

## Decision

1. LaraLicensingV1 (the API) is an external Laravel service. This repo does
   not implement it; it consumes it.
2. This TanStack Start repo is the **operator UI** for LaraLicensingV1,
   covering the Admin, Reseller, and AppBuilder actors defined in
   `spec/21-app/04-roles.md`. The EndUser device client is out of scope.
3. All API calls go to `import.meta.env.VITE_LARA_API_BASE_URL`.
   No `createServerFn` proxies the Laravel API; the browser calls Laravel
   directly with a bearer token. Server functions are reserved for
   frontend-only concerns (session cache, feature flags).
4. Authentication uses the JWT flow in `spec/21-app/02-authentication-jwt.md`.
   The Laravel host is the sole identity provider. Supabase auth is not used
   for LaraLicensingV1 actors.
5. Lovable Cloud is not required for the operator UI. It stays disabled
   unless a future decision adds a frontend-only feature that needs it.
6. The `/api/public/*` TanStack server routes are reserved for webhooks
   from Laravel to the frontend (for example, license-revoked broadcast),
   not for proxying the domain API.

## Consequences

- The frontend needs one HTTP client module that: reads
  `VITE_LARA_API_BASE_URL`, attaches the JWT from local session storage,
  enforces the response envelope in
  `spec/21-app/11-api-contracts/00-overview.md`, maps `ErrorCode` values
  from `spec/21-app/12-error-taxonomy.md`, and surfaces 429 headers per
  `spec/21-app/14-rate-limiting.md`.
- CORS is a Laravel-side concern; the frontend assumes the API is reachable
  from the operator UI's origin.
- Any spec change that pins a Laravel-only primitive (Eloquent, Artisan
  jobs, Sanctum) stays valid; the frontend only observes the HTTP surface.
- If Lovable Cloud is later enabled for a frontend-only feature, it must
  not overlap with LaraLicensingV1 domain data.

## Non-goals

- Reimplementing LaraLicensingV1 in `createServerFn`.
- Serving the EndUser device verify client from this repo.
- Choosing a specific Laravel deployment target.
