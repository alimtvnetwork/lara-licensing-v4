---
Slug: SS-01-swagger-console
Parent: 06-spec-24-ui-fluid-swagger
Status: pending
Created: 2026-07-17
---

# Swagger Console Blueprint (spec/24 → 29-per-surface-blueprints/09-swagger-console.md)

## Purpose

Third-party integrators and internal engineers need a live, permission-gated API console. This blueprint defines the `/api-docs` UI surface.

## Layout (ASCII)

```
+------------------------------------------------------------+
| TopBar: LaraLicensingV1 · API Docs · [Server: prod|sandbox]|
+---------------------+--------------------------------------+
| Tag sidebar (240px) | Operation panel                      |
|  - Auth             |  GET /Licenses/{id}                  |
|  - Licenses         |  operationId: Licenses.Get           |
|  - Serials          |  Auth: Bearer + Api.Swagger.Read     |
|  - Quotas           |  Params | Body | Responses | Try     |
|  - Features         |                                      |
|  - Users            |                                      |
+---------------------+--------------------------------------+
```

## Data source

- Static OpenAPI 3.1 doc served from `/openapi.yaml` (Laravel).
- Rendered by Swagger UI (or Scalar/Stoplight Elements) inside a token-themed shell.

## Auth + permission

- Route is under `_authenticated`.
- Requires permission `Api.Swagger.Read` (add to `spec/21-app/40-permissions.md` in step 41 of the parent plan).
- Anonymous access is 401 → redirect `/auth`; authenticated-without-permission is 403 → `/forbidden`.
- Third parties receive a personal access token; token scopes limit which operations render "Try it out".

## Try-it-out policy

- Read operations: allowed on `sandbox` and `prod` servers.
- Write operations: allowed on `sandbox` only; `prod` shows a disabled "Try" button with tooltip "Use the CLI or a signed request."
- Idempotency-Key auto-populated with a UUIDv4; user can override.
- Retry-After surfaced via the shared `<RetryAfterBanner>` component.

## Calls

| Verb | Path | OperationId | AuthScope | Idempotency | ErrorCodes |
|------|------|-------------|-----------|-------------|------------|
| GET  | /openapi.yaml | Meta.OpenApi | Api.Swagger.Read | n/a | 401, 403 |
| GET  | /Users/Me     | Users.Me     | authenticated    | n/a | 401 |

## States

Loading, Loaded, Forbidden, ServerSelectorOpen, TryItOutRunning, TryItOutFailed(ErrorCode), RateLimited(Retry-After).

## AC-IDs

AC-ADS-024 (Swagger console renders only after permission check), AC-ADS-025 (write ops disabled on prod), AC-ADS-026 (RequestId visible in every response panel).
