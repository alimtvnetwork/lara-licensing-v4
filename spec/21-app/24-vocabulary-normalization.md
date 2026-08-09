# Vocabulary and Path Normalization

**Version:** 1.1.0
**Updated:** 2026-07-16

---

## Purpose

Fix the single spelling of resource names, path casing, and cross-file drift across `spec/21-app/`. This file is authoritative for path casing and resource collection names; for the role, license category, and license variation enums it POINTS to the canonical leaf files rather than restating members (single-owner rule introduced in Plan 03 step 18).

## Role vocabulary

The canonical `app_role` enum lives in [`04-roles.md`](./04-roles.md) §Canonical set. This file does not restate members; any drift found elsewhere is superseded by `04-roles.md`. The v1 enum has exactly four members: `Admin`, `Reseller`, `AppBuilder`, `EndUser`. `Auditor` is not a member.

## License category vocabulary

The canonical `LicenseCategory` enum lives in [`05-license-categories.md`](./05-license-categories.md) §Canonical set. This file does not restate members; any drift found elsewhere is superseded by `05-license-categories.md`. Collection name is `LicenseCategories`.

## License variation vocabulary

The canonical `LicenseVariation` shape lives in [`06-license-variations.md`](./06-license-variations.md) §Canonical set. This file does not restate members; any drift found elsewhere is superseded by `06-license-variations.md`. Collection name is `LicenseVariations`.

## Path casing

- API routes are PascalCase, singular for the resource segment and plural for the collection segment, matching [`10-endpoints.md`](./10-endpoints.md) and [`11-api-contracts/`](./11-api-contracts/).
  Examples: `POST /Resellers`, `GET /Users/{UserId}`, `POST /Licenses/{LicenseId}/Serials`, `GET /App/UpdateManifest`.
- UI routes are lowercase kebab-case and NEVER prefixed with the API path.
  Examples: `/admin/resellers`, `/admin/app-updates`, `/reseller/licenses`, `/app/devices`.
- Cross references from UI spec to API spec MUST cite the API path in canonical PascalCase; lowercase citations are drift.

## Resource vocabulary

| Canonical | Forbidden synonyms |
|-----------|--------------------|
| `Licenses`, `Serials`, `Bindings` | `licenses`, `serial_numbers`, `machine_bindings` (SQL table name is fine in DB spec only) |
| `LicenseCategories` | `Categories`, `license_categories`, `LicenseTypes` |
| `LicenseVariations` | `Variations`, `license_variations`, `LicenseVariants` |
| `LicensePackages` | `Packages`, `license_packages`, `LicenseBundles` |
| `Prefixes` | `ResellerPrefixes`, `prefix_values` |
| `AppUpdates` | `Updates`, `Releases`, `Manifests` |
| `IdempotencyRecords` | `IdempotencyKeys`, `idempotency_records` |
| `RateLimitBuckets` | `RateLimits`, `Buckets` |
| `AuditEvents` | `AuditLog`, `audit_log`, `AuditRows` |

`AuditLog` appears in `04-roles.md` line 35 as a legacy singular label. It
is superseded by `AuditEvents` here; the reference stands as a pointer to
the audit log CONCEPT, not a table name.

## Verify endpoint auth (drift fix)

[`10-endpoints.md`](./10-endpoints.md) restricts `/Verify/Serial`,
`/Verify/Hash`, and `/Verify/Final` to `AppBuilder OAuth` clients ONLY.
The permission matrix in [`04-roles.md`](./04-roles.md) previously showed
`Yes` for `Admin` and `Reseller` on these routes; that row is corrected
in `04-roles.md` v1.1.0 and this file is the tiebreaker. Admin and Reseller
JWT sessions calling verify endpoints receive `403 AuthForbidden` with
`ErrorCode: AuthzRoleDenied`.

## UI endpoint citation status

Rows in [`16-ui-surfaces.md`](./16-ui-surfaces.md) sections 3-6 cite several
paths not present in [`10-endpoints.md`](./10-endpoints.md). Each is either
a canonical PascalCase alias or a v1.1 deferral:

| UI citation | Status | Canonical (v1) or note |
|-------------|--------|------------------------|
| `GET /admin/stats` | Deferred v1.1 | Aggregate KPIs not in v1 endpoint set. |
| `GET /admin/audit` | Alias | Reads `GET /AuditEvents` (see [`07-admin-list-envelope-hardening.md`](./11-api-contracts/07-admin-list-envelope-hardening.md)). |
| `GET /admin/abuse` | Deferred v1.1 | `RateLimitBuckets` list endpoint deferred. |
| `GET/PATCH /categories`, `GET/PATCH /variations` | Deferred v1.1 | `LicenseCategories` and `LicenseVariations` are seed-only in v1. |
| `GET/POST/PATCH /packages` | Deferred v1.1 | `LicensePackages` deferred. |
| `POST /auth/register` | Alias | Canonical is `POST /Auth/Register` (see [`01-auth-contracts.md`](./11-api-contracts/01-auth-contracts.md)). |
| `POST /verify/hash` | Alias | Canonical is `POST /Verify/Hash`. |
| `GET /verify/stats`, `GET /builder/clients`, `POST /builder/clients/{id}/rotate`, `GET/POST /builder/keys`, `GET /builder/logs` | Deferred v1.1 | AppBuilder self-service surfaces deferred. |
| `GET /licenses/stats`, `GET /serials` | Deferred v1.1 | Reseller aggregates deferred; use `GET /Licenses` with `ResellerId` filter. |
| `GET /me/licenses`, `GET /me/serials/{id}`, `DELETE /me/bindings/{id}`, `PATCH /me`, `POST /me/oauth/link` | Deferred v1.1 | EndUser self-service surfaces deferred. |

UI screens whose only contract is Deferred v1.1 MUST render an "Available
in v1.1" placeholder in v1, not call an unimplemented endpoint. UI screens
whose citation is an Alias MUST call the canonical PascalCase path.

## Acceptance

- AC-VN-001: Every actor reference in `spec/21-app/` uses one of `Admin`, `Reseller`, `AppBuilder`, `EndUser`. No other actor label may appear in normative clauses.
- AC-VN-002: Every API path citation uses PascalCase. Lowercase or kebab-case API path citations are drift and MUST be corrected on next edit of the offending file.
- AC-VN-003: `Admin` and `Reseller` JWT sessions calling `/Verify/*` receive `403 AuthzRoleDenied`.
- AC-VN-004: UI screens tied to Deferred v1.1 rows render a placeholder, not an unimplemented API call.
- AC-VN-005: `Auditor` is not a role in v1; audit reads are `Admin` only.
