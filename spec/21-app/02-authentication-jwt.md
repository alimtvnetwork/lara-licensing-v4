# Authentication: JWT

**Version:** 1.1.0
**Updated:** 2026-07-16

---

## Purpose

JWT is used for interactive user sessions (Admin, Reseller, AppBuilder web UI). Tokens are short-lived access tokens plus rotating refresh tokens.

## Token Shape

| Field | Type | Notes |
|-------|------|-------|
| `Sub` | string | `UserId` as string. |
| `Role` | string | One of `Admin`, `Reseller`, `AppBuilder`, `EndUser`. |
| `TenantId` | int | Reseller scope, nullable for Admin. |
| `Iat` | int | Issued at, epoch seconds. |
| `Exp` | int | Access token: `Iat + 900` (15 min). |
| `Jti` | string | Unique per token, used for revocation. |

Refresh token: opaque, stored server-side, 30-day sliding expiry, rotated on every use.

## Endpoints

- `POST /Auth/Token` ,  email + password, returns access + refresh.
- `POST /Auth/Refresh` ,  refresh token, returns new pair, revokes old refresh.
- `POST /Auth/Revoke` ,  revokes a given `Jti` or refresh token.

## Sequence

Diagram: [`../23-app-db/02-jwt-flow.mmd`](../23-app-db/02-jwt-flow.mmd).

## Errors

| Code | Meaning |
|------|---------|
| `AuthInvalidCredentials` | Email or password wrong. |
| `AuthTokenExpired` | Access token past `Exp`. |
| `AuthTokenRevoked` | `Jti` in revocation list. |
| `AuthRefreshReused` | Refresh token replay detected, revokes entire chain. See [`31-auth-session-family.md`](./31-auth-session-family.md) for the normative family model, cascade rules, session cap, and reuse detection contract. |

## Acceptance

- AC-JWT-001: Access token verifies with the platform public key.
- AC-JWT-002: Refresh reuse revokes the entire chain and logs a security event.
- AC-JWT-003: Revoked `Jti` returns 401 within one clock second.
