# Route to DTO Index

**Version:** 1.1.0
**Updated:** 2026-07-17

---

## Purpose

Single lookup table binding every route in [`10-endpoints.md`](./10-endpoints.md) to (a) the contract file that defines its request and response DTOs, (b) the error-management row in [`21-error-management-binding.md`](./21-error-management-binding.md), (c) the caller retry class from [`25-retry-decision-matrix.md`](./25-retry-decision-matrix.md), and (d) the `PermissionKey` from [`40-permissions.md`](./40-permissions.md) (or `None` for session and verify exemptions per [`10-endpoints.md`](./10-endpoints.md) §Auth/§Verification). Prevents contract writers from inventing DTO fields on the fly and gives reviewers one file to check for coverage gaps.

## Normative sources

- [`10-endpoints.md`](./10-endpoints.md): canonical route list.
- [`11-api-contracts/`](./11-api-contracts/): DTO definitions.
- [`21-error-management-binding.md`](./21-error-management-binding.md): per-route log level and server retry class.
- [`25-retry-decision-matrix.md`](./25-retry-decision-matrix.md): caller retry class per `ErrorCode`.
- [`40-permissions.md`](./40-permissions.md): canonical `PermissionKey` set.

## Index

| Route | Contract source | Request DTO | Response DTO | Idempotency | Server retry class | PermissionKey |
|-------|-----------------|-------------|--------------|:-----------:|:------------------:|:-------------:|
| `POST /Auth/Token` | `01-auth-contracts.md` §Token | `AuthTokenRequest` | `AuthTokenResponse` | n/a | `NoRetry` | `None` |
| `POST /Auth/Refresh` | `01-auth-contracts.md` §Refresh | `AuthRefreshRequest` | `AuthTokenResponse` | n/a | `NoRetry` | `None` |
| `POST /Auth/Revoke` | `01-auth-contracts.md` §Revoke | `AuthRevokeRequest` | envelope only | n/a | `NoRetry` | `None` |
| `POST /OAuth/Token` | `03-authentication-oauth.md` §ClientCredentials | `OAuthTokenRequest` | `OAuthTokenResponse` | n/a | `RetryWithBackoff` | `None` |
| `GET /OAuth/Authorize` | `03-authentication-oauth.md` §AuthorizationCode | query params | 302 redirect | n/a | `NoRetry` | `None` |
| `POST /OAuth/Revoke` | `03-authentication-oauth.md` §Revoke | `OAuthRevokeRequest` | envelope only | n/a | `NoRetry` | `None` |
| `POST /OAuth/Introspect` | `03-authentication-oauth.md` §Introspect | `OAuthIntrospectRequest` | `OAuthIntrospectResponse` | n/a | `RetryWithBackoff` | `None` |
| `POST /Licenses` | `02-license-contracts.md` §Create | `LicenseCreateRequest` (incl. `LicenseTierId`, `EnvironmentId`) | `LicenseResource` (echoes `LicenseTierId`, `EnvironmentId`) | required | `RetryOnce` | `Licenses.Create` |
| `GET /Licenses` | `04-admin-contracts.md` §Licenses + `07-admin-list-envelope-hardening.md` | pagination params | `LicenseResource[]` + `Attributes.Pagination` | n/a | `RetryWithBackoff` | `Licenses.Read` |
| `GET /Licenses/{LicenseId}` | `02-license-contracts.md` §Read | path param | `LicenseResource` | n/a | `RetryWithBackoff` | `Licenses.Read` |
| `PATCH /Licenses/{LicenseId}` | `02-license-contracts.md` §Renew | `LicenseUpdateRequest` | `LicenseResource` | required (renew) | `RetryOnce` | `Licenses.Update` |
| `DELETE /Licenses/{LicenseId}` | `02-license-contracts.md` §Revoke | envelope only | envelope only | required | `RetryOnce` | `Licenses.Revoke` |
| `POST /Licenses/{LicenseId}/Serials` | `02-license-contracts.md` §Serials | `SerialIssueRequest` | `SerialResource` | required | `RetryOnce` | `Serials.Issue` |
| `GET /Serials/{SerialValue}` | `02-license-contracts.md` §SerialLookup | path param | `SerialResource` | n/a | `RetryWithBackoff` | `Serials.Lookup` |
| `POST /Verify/Serial` | `03-verification-contracts.md` §Serial | `VerifySerialRequest` | `VerifySerialResponse` | n/a | `RetryWithBackoff` | `None` |
| `POST /Verify/Hash` | `03-verification-contracts.md` §Hash | `VerifyHashRequest` | `VerifyHashResponse` | n/a | `RetryWithBackoff` | `None` |
| `POST /Verify/Final` | `03-verification-contracts.md` §Final | `VerifyFinalRequest` | `VerifyFinalResponse` (incl. `LicenseTierId`, `EnvironmentId`, `Features` map) | n/a | `NoRetry` | `None` |
| `POST /Resellers` | `04-admin-contracts.md` §Resellers | `ResellerCreateRequest` | `ResellerResource` | optional | `RetryOnce` | `Resellers.Manage` |
| `GET /Resellers` | `04-admin-contracts.md` §Resellers + `07-admin-list-envelope-hardening.md` | pagination params | `ResellerResource[]` + `Attributes.Pagination` | n/a | `RetryWithBackoff` | `Resellers.Manage` |
| `GET /Resellers/{ResellerId}` | `04-admin-contracts.md` §Resellers | path param | `ResellerResource` | n/a | `RetryWithBackoff` | `Resellers.Manage` |
| `PATCH /Resellers/{ResellerId}` | `04-admin-contracts.md` §Resellers | `ResellerUpdateRequest` | `ResellerResource` | n/a | `RetryOnce` | `Resellers.Manage` |
| `DELETE /Resellers/{ResellerId}` | `04-admin-contracts.md` §Resellers | envelope only | envelope only | n/a | `NoRetry` | `Resellers.Manage` |
| `GET /Resellers/{ResellerId}/Prefixes` | `04-admin-contracts.md` §Prefixes | pagination params | `PrefixResource[]` | n/a | `RetryWithBackoff` | `Prefixes.Manage` |
| `POST /Resellers/{ResellerId}/Prefixes` | `04-admin-contracts.md` §Prefixes | `PrefixCreateRequest` | `PrefixResource` | n/a | `RetryOnce` | `Prefixes.Manage` |
| `PATCH /Prefixes/{PrefixId}` | `04-admin-contracts.md` §Prefixes | `PrefixUpdateRequest` | `PrefixResource` | n/a | `RetryOnce` | `Prefixes.Manage` |
| `DELETE /Prefixes/{PrefixId}` | `04-admin-contracts.md` §Prefixes | envelope only | envelope only | n/a | `NoRetry` | `Prefixes.Manage` |
| `GET /Resellers/{ResellerId}/Quotas` | `04-admin-contracts.md` §Quotas | pagination params | `ResellerQuotaResource[]` + `Attributes.Pagination` | n/a | `RetryWithBackoff` | `Resellers.Manage` |
| `GET /Resellers/{ResellerId}/QuotaLedger` | `04-admin-contracts.md` §Quotas | pagination + filters | `ResellerQuotaLedgerResource[]` + `Attributes.Pagination` | n/a | `RetryWithBackoff` | `Resellers.Manage` |
| `POST /Resellers/{ResellerId}/QuotaRequests` | `05-quota-request-contracts.md` §Submit | `QuotaRequestSubmitRequest` | `QuotaRequestResource` | required | `RetryOnce` | `Quotas.Request` |
| `GET /Resellers/{ResellerId}/QuotaRequests` | `05-quota-request-contracts.md` §List | pagination + filters | `QuotaRequestResource[]` + `Attributes.Pagination` | n/a | `RetryWithBackoff` | `Quotas.Request` |
| `GET /QuotaRequests/{RequestId}` | `05-quota-request-contracts.md` §Read | path param | `QuotaRequestResource` | n/a | `RetryWithBackoff` | `Quotas.Request` |
| `POST /QuotaRequests/{RequestId}/Approve` | `05-quota-request-contracts.md` §Approve | `QuotaRequestApproveRequest` | `QuotaRequestResource` | required | `NoRetry` on 4xx | `Quotas.Approve` |
| `POST /QuotaRequests/{RequestId}/Deny` | `05-quota-request-contracts.md` §Deny | `QuotaRequestDenyRequest` | `QuotaRequestResource` | required | `NoRetry` on 4xx | `Quotas.Approve` |
| `POST /QuotaRequests/{RequestId}/Cancel` | `05-quota-request-contracts.md` §Cancel | envelope only | `QuotaRequestResource` | required | `NoRetry` on 4xx | `Quotas.Request` |
| `POST /Resellers/{ResellerId}/Quotas/{CategoryId}/Adjust` | `05-quota-request-contracts.md` §Adjust | `QuotaAdjustRequest` | `ResellerQuotaResource` | required | `NoRetry` on 4xx | `Quotas.Adjust` |
| `GET /Features` | `02-license-contracts.md` §Feature admin | pagination params | `FeatureCatalogResource[]` | n/a | `RetryWithBackoff` | `Licenses.Read` |
| `GET /Tiers/{LicenseTierId}/Features` | `02-license-contracts.md` §Feature admin | path param | `TierFeatureResource[]` | n/a | `RetryWithBackoff` | `Licenses.Read` |
| `PUT /Tiers/{LicenseTierId}/Features/{FeatureKey}` | `02-license-contracts.md` §Feature admin | `TierFeaturePutRequest` (typed `Value`) | `TierFeatureResource` | required | `NoRetry` on 4xx | `Roles.Assign` |
| `DELETE /Tiers/{LicenseTierId}/Features/{FeatureKey}` | `02-license-contracts.md` §Feature admin | envelope only | envelope only | required | `NoRetry` on 4xx | `Roles.Assign` |
| `GET /Licenses/{LicenseId}/Features` | `02-license-contracts.md` §Feature admin | path param | `LicenseFeatureResource[]` | n/a | `RetryWithBackoff` | `Licenses.Read` |
| `PUT /Licenses/{LicenseId}/Features/{FeatureKey}` | `02-license-contracts.md` §Feature admin | `LicenseFeaturePutRequest` (typed `Value`) | `LicenseFeatureResource` | required | `NoRetry` on 4xx | `Licenses.Update` |
| `DELETE /Licenses/{LicenseId}/Features/{FeatureKey}` | `02-license-contracts.md` §Feature admin | envelope only | envelope only | required | `NoRetry` on 4xx | `Licenses.Update` |
| `POST /Users` | `19-user-management.md` §Create | `UserCreateRequest` | `UserResource` | optional | `RetryOnce` | `Users.Manage` |
| `GET /Users` | `19-user-management.md` §List + `07-admin-list-envelope-hardening.md` | pagination params | `UserResource[]` + `Attributes.Pagination` | n/a | `RetryWithBackoff` | `Users.Manage` |
| `POST /Users/{UserId}/Roles` | `19-user-management.md` §Roles | `UserRoleGrantRequest` | `UserRoleResource` | optional | `RetryOnce` | `Roles.Assign` |
| `DELETE /Users/{UserId}/Roles` | `19-user-management.md` §Roles | `UserRoleRevokeRequest` | envelope only | n/a | `NoRetry` | `Roles.Assign` |
| `GET /Admin/Users/{UserId}/Roles` | `19-user-management.md` §Roles | path param | `UserRoleResource[]` | n/a | `RetryWithBackoff` | `Users.Manage` |
| `GET /Me/Roles` | `19-user-management.md` §Self | envelope only | `UserRoleResource[]` | n/a | `RetryWithBackoff` | `None` |
| `GET /AuditEvents` | `04-admin-contracts.md` §AuditEvents + `07-admin-list-envelope-hardening.md` | pagination + filters | `AuditEventResource[]` + `Attributes.Pagination` | n/a | `RetryWithBackoff` | `Audit.Read` |
| `GET /App/UpdateManifest` | `17-self-update-endpoint.md` §Manifest | query (`Product`, `Platform`, `Channel`) | `UpdateManifest` | n/a | `RetryWithBackoff` | `None` |
| `HEAD /App/UpdateAsset/{Version}/{Platform}` | `17-self-update-endpoint.md` §Asset | path params | headers (`X-Sha256`, `Content-Length`) | n/a | `NoRetry` | `None` |
| `GET /App/UpdateAsset/{Version}/{Platform}` | `17-self-update-endpoint.md` §Asset | path params | binary stream | n/a | `NoRetry` (mismatch = abort) | `None` |
| `POST /Admin/AppUpdates/UploadTicket` | `17-self-update-endpoint.md` §UploadTicket | `UploadTicketRequest` | `UploadTicketResponse` | n/a | `RetryOnce` | `Updates.Publish` |
| `POST /Admin/AppUpdates` | `17-self-update-endpoint.md` §Publish | `PublishManifestRequest` | `UpdateManifest` | idempotent by `(Product, Version, Channel)` | `NoRetry` on non-5xx | `Updates.Publish` |

## New fields introduced by Plan 05

- `LicenseTierId`: closed enum from [`43-license-tiers.md`](./43-license-tiers.md) §2 (`Tier1 | Tier2 | Tier3 | Unlimited`). Required on `POST /Licenses`, `PATCH /Licenses/{LicenseId}`; echoed on every `LicenseResource` and on `POST /Verify/Final` response.
- `EnvironmentId`: closed enum from [`44-environments.md`](./44-environments.md) §2 (`Production | Staging | Development`). Required on `POST /Licenses`; immutable per AC-ENV-002; echoed on every `LicenseResource` and on `POST /Verify/Final` response. Mismatch at verify returns `EnvironmentMismatch` (409) per AC-LENV-004.
- `Features`: PascalCase map keyed by `FeatureKey` from [`45-license-features.md`](./45-license-features.md) §2; values typed per §3 (`Boolean | Number | String | Duration`). Emitted on `POST /Verify/Final` result only per AC-FEAT-003; not accepted on any request.
- `PermissionKey`: required column on this index; every non-`None` row cites exactly one key from [`40-permissions.md`](./40-permissions.md) §2. `None` marks session-establishment and verify routes exempted by [`10-endpoints.md`](./10-endpoints.md) §Auth/§Verification.

## Coverage rules

- Every row in [`10-endpoints.md`](./10-endpoints.md) MUST appear here exactly once.
- Every request DTO name MUST resolve to a header/section in the cited contract file.
- Every response DTO MUST use the universal envelope from [`11-api-contracts/05-envelope-schema.md`](./11-api-contracts/05-envelope-schema.md).
- Every row MUST cite exactly one `PermissionKey` from [`40-permissions.md`](./40-permissions.md) §2 or the literal `None` (matches [`10-endpoints.md`](./10-endpoints.md) column). Enforced by [`../../linter-scripts/check-endpoint-permission-parity.py`](../../linter-scripts/check-endpoint-permission-parity.py).

## Acceptance

- AC-DTO-001: Row count in this table equals the row count across all tables in [`10-endpoints.md`](./10-endpoints.md).
- AC-DTO-002: Every `required` idempotency row also appears in [`11-api-contracts/08-idempotency-envelope-hardening.md`](./11-api-contracts/08-idempotency-envelope-hardening.md) §Scope with `Required = required`.
- AC-DTO-003: Every `Attributes.Pagination` row appears in [`11-api-contracts/07-admin-list-envelope-hardening.md`](./11-api-contracts/07-admin-list-envelope-hardening.md).
- AC-DTO-004: Every retry class value matches the row in [`21-error-management-binding.md`](./21-error-management-binding.md); mismatches are a finding logged in [`../25-app-audit/98-findings-index.md`](../25-app-audit/98-findings-index.md).
- AC-DTO-005: Every row's `PermissionKey` column matches the same-route row in [`10-endpoints.md`](./10-endpoints.md); enforced by the permission-parity linter added in Plan 05 Step 07.
- AC-DTO-006: Every row that echoes `LicenseTierId`, `EnvironmentId`, or `Features` cites the owning file ([`43-license-tiers.md`](./43-license-tiers.md), [`44-environments.md`](./44-environments.md), [`45-license-features.md`](./45-license-features.md)) exactly once per Plan 05 Step 37.
