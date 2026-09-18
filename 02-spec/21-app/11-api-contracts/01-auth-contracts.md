# Authentication API Contracts

**Version:** 1.1.0
**Updated:** 2026-07-16

## `POST /Auth/Token`

Request: `Email` string email, `Password` string 12 to 128 characters.

Result: `AccessToken` string, `RefreshToken` string, `TokenType` value `Bearer`, `ExpiresIn` integer seconds.

Responses: `200` success, `400 ValidationFailed`, `401 AuthInvalidCredentials`, `429 RateLimited`.

## `POST /Auth/Refresh`

Request: `RefreshToken` non-empty string.

Result: the same token result as `/Auth/Token`. Rotation invalidates the submitted token atomically.

Responses: `200` success, `401 AuthTokenExpired`, `401 AuthRefreshReused`, `429 RateLimited`.

## `POST /Auth/Revoke`

Request:

| Field | Type | Notes |
|-------|------|-------|
| `Jti` | string | Optional. Exactly one of `Jti` or `RefreshToken` MUST be supplied. |
| `RefreshToken` | string | Optional. Opaque refresh token; not logged. |
| `Scope` | enum `Session`, `Family` | Optional. Defaults to `Session`. `Family` revokes every live row sharing the target's `FamilyId` per [`../31-auth-session-family.md`](../31-auth-session-family.md) §Family Lifecycle step 4. |
| `Reason` | enum `UserLogout`, `AdminForced`, `Security` | Optional. Defaults to `UserLogout`. `AdminForced` and `Security` require caller `has_role(Admin)`. |

Result:

| Field | Type | Notes |
|-------|------|-------|
| `IsRevoked` | boolean | True when at least one row transitioned to `RevokedAt IS NOT NULL`. |
| `RevokedCount` | integer | 1 when `Scope=Session`; `n` live family rows when `Scope=Family`. |
| `FamilyId` | string ULID, nullable | Present when `Scope=Family`. |

Audit: writes `AuthLogout` (id 3) when `Scope=Session`; writes `AuthFamilyRevoked` (id 45) when `Scope=Family` with `PayloadJson = { FamilyId, UserId, RevokedCount, TriggeredByUserId }`.

Responses: `200` success, `400 ValidationFailed` (both or neither of `Jti`/`RefreshToken` present, or unknown `Scope`/`Reason`), `401 AuthUnauthorized`, `403 AuthzRoleDenied` (non-admin requesting `Scope=Family` on another user's session, or `Reason` requiring Admin).

## `POST /OAuth/Token`

Request: `GrantType` enum `ClientCredentials` or `AuthorizationCode`, `ClientId` string, `ClientSecret` string when required, `Code` string when required, `CodeVerifier` string when required, and `RedirectUri` absolute URI when required.

Result: `AccessToken` string, `TokenType` value `Bearer`, `ExpiresIn` integer seconds, `Scopes` array of strings, and optional `RefreshToken`.

Responses: `200` success, `400 OAuthInvalidGrant`, `401 OAuthInvalidClient`, `429 RateLimited`.

## Other OAuth endpoints

| Endpoint | Request | Result | Responses |
|----------|---------|--------|-----------|
| `GET /OAuth/Authorize` | Query: `ClientId`, `RedirectUri`, `CodeChallenge`, `CodeChallengeMethod=S256`, `State`, `Scopes[]` | `302` redirect with `Code` and unchanged `State` | `302`, `400 OAuthInvalidRequest`, `403 AuthForbidden` |
| `POST /OAuth/Revoke` | `Token` string, `TokenTypeHint` optional enum | `IsRevoked` boolean | `200`, `401 OAuthInvalidClient` |
| `POST /OAuth/Introspect` | `Token` string | `IsActive`, `ClientId`, `Scopes[]`, `ExpiresAt`, optional `UserId` | `200`, `401 OAuthInvalidClient` |

## Acceptance

- AC-API-AUTH-001: Secret and token values never appear in logs or error attributes.
- AC-API-AUTH-002: Every refresh operation is transactional and detects token reuse.
- AC-API-AUTH-003: OAuth authorization preserves `State` byte-for-byte.