# Authentication: OAuth2

**Version:** 1.0.0
**Updated:** 2026-07-16

---

## Purpose

OAuth2 is used for machine-to-machine access by `AppBuilder` integrations. No password grant, no implicit grant.

## Supported Grants

| Grant | Actor | Use case |
|-------|-------|----------|
| `client_credentials` | AppBuilder server | Server-to-server calls to verify endpoints. |
| `authorization_code` (PKCE) | AppBuilder web console | Reseller-hosted admin panels. |

## Endpoints

- `POST /OAuth/Token` ,  grant_type body param.
- `GET /OAuth/Authorize` ,  authorization-code entry, PKCE required.
- `POST /OAuth/Revoke` ,  revoke access or refresh token.
- `POST /OAuth/Introspect` ,  RFC 7662 token metadata for resource servers.

## Client Registration

| Field | Type | Notes |
|-------|------|-------|
| `ClientId` | string | Public identifier. |
| `ClientSecret` | string | Hashed at rest, shown once. |
| `RedirectUris` | string[] | Required for auth-code grant. |
| `AllowedScopes` | string[] | See scopes below. |
| `TenantId` | int | Reseller scope. |

## Scopes

`license:read`, `license:write`, `serial:generate`, `verify:hash`, `verify:final`, `reseller:manage`.

## Sequence

Diagram: [`../23-app-db/03-oauth-client-credentials.mmd`](../23-app-db/03-oauth-client-credentials.mmd).

## Acceptance

- AC-OAUTH-001: `client_credentials` returns an access token scoped to `AllowedScopes`.
- AC-OAUTH-002: PKCE is mandatory for `authorization_code`; missing `code_challenge` returns 400.
- AC-OAUTH-003: `introspect` returns `active: false` for revoked tokens.
