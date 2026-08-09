# Reseller Shard, Split DB Mapping

**Version:** 1.0.0
**Status:** Draft
**Updated:** 2026-07-18
**AI Confidence:** Draft
**Ambiguity:** Low
**Authoritative refs:** `spec/05-split-db-architecture/00-overview.md`, `spec/05-split-db-architecture/01-fundamentals.md`, `spec/19-main-worker-service/11-split-db-tier-reconciliation.md`

---

## Purpose

Bind the LaraLicensingV1 reseller model to the split-DB architecture from spec 05. Every reseller registered on the Root tier gets its own App-tier database (a "reseller shard"). Licensing data, prefixes, packages, users, and audit trails for that reseller live inside its shard and never in the Root DB.

Spec 05 wins on any conflict. This file is the reseller-specific projection.

---

## Tier Map

| Tier | Scope | DB file | Rows created per reseller |
|---|---|---|---|
| Root | Cross-reseller directory and routing | `data/lara.db` | 1 `Resellers` row, 1..N `ResellerShardRoutes` row |
| App | One DB per reseller shard | `data/resellers/{ResellerSlug}/app.db` | All licensing, prefix, package, and user rows for that reseller |
| Session | One DB per active operator session | `data/resellers/{ResellerSlug}/sessions/{SessionId}.db` | Impersonation and audit correlation state (see spec 21 §46) |

Cache and Document tiers from spec 05 are reserved. Do not add tables to them in v1.0.

---

## Root DB, tables kept

Root DB holds only what is required to locate and gate a shard. It never holds license, prefix, or serial rows.

- `Resellers` (identity, status, contact email, `ResellerSlug` UK)
- `ResellerShardRoutes` (`ResellerId` FK, `AppDbPath`, `ShardStatus`, `SchemaVersion`, `LastMigratedAt`)
- `Users` (SuperAdmin and cross-reseller staff only)
- `UserRoles` (role bindings for Root-scoped users; per-reseller roles live in that reseller's shard)
- `AuditLogs` (Root-scoped actions only; per-reseller actions log to the shard)

Every other table in `spec/23-app-db/01-schema.md` moves to the App tier and is created per shard.

---

## App-tier tables per reseller shard

Created from a single migration template applied when the shard is provisioned. PascalCase names preserved from `01-schema.md`.

- `Users`, `UserRoles` (reseller-scoped staff)
- `Prefixes`
- `LicenseCategories`, `LicensePackages`, `LicenseVariations`
- `Licenses`, `Serials`
- `MachineBindings`, `UserBindings`
- `VerifyKeys`
- `AppBuilders`
- `Quotas`, `QuotaRequests`, `FeatureOverrides`
- `Environments`
- `AuditLogs` (shard-scoped)
- `AuthSessions` including impersonation fields from `01-schema.md` v0.19.0

Foreign keys within a shard stay intra-DB. Cross-shard reads are forbidden at the query layer.

---

## Provisioning Lifecycle

1. `POST /Resellers` inserts `Resellers` in Root, allocates `ResellerSlug`, and inserts a `ResellerShardRoutes` row with `ShardStatus=Provisioning`.
2. Migration runner creates `data/resellers/{ResellerSlug}/app.db`, runs the App-tier migration template forward-only per `spec/04-database-conventions/03-orm-and-views.md`, seeds required closed-set rows (roles, environments, license categories), and sets `ShardStatus=Active`, `SchemaVersion=<current>`.
3. Any failure sets `ShardStatus=Failed` with error captured in `AuditLogs` (Root). Retry is idempotent by `ResellerId`.
4. Deactivation flips `Resellers.IsActive=false` and `ShardStatus=Quiesced`. The shard file is retained; no destructive drop in v1.0.

---

## Routing Rules

- Every authenticated request resolves `ResellerId` from the JWT claim (`ResellerId` for reseller-scoped roles) or from the request path (`/Resellers/{ResellerId}/...` for Admin cross-reseller calls).
- The transport layer opens the shard connection using `ResellerShardRoutes.AppDbPath` and MUST reject a request whose claimed `ResellerId` does not resolve to `ShardStatus=Active`.
- SuperAdmin and Admin operators bypass the ResellerId claim only for Root-scoped endpoints (`/Resellers`, `/Users` SuperAdmin surface). Any per-reseller admin action still opens the target shard.

---

## Impersonation and Session Tier

Per `spec/21-app/46-impersonation.md` and `01-schema.md` v0.19.0, `AuthSessions` lives in the App tier of the reseller shard where the target user resides. An Admin impersonating a reseller user opens a Session-tier DB scoped to that shard. The Root DB never stores impersonation state.

---

## Migration Discipline

- One migration template, versioned in `spec/23-app-db/02-migration-order.md`, is the single source for all shard schemas. Every shard MUST report the same `SchemaVersion`.
- Adding a column: forward-only, nullable, no DEFAULT (Rule 12). Applied to every active shard in the same release.
- The Root DB tracks the migration frontier per shard in `ResellerShardRoutes.SchemaVersion`. A shard behind the frontier is read-only until caught up.

---

## Cross-References

- `spec/05-split-db-architecture/00-overview.md`, tier definitions
- `spec/05-split-db-architecture/01-fundamentals.md`, isolation rules
- `spec/19-main-worker-service/11-split-db-tier-reconciliation.md`, 4-tier framing
- `spec/23-app-db/01-schema.md`, table columns and constraints
- `spec/23-app-db/02-migration-order.md`, migration numbering
- `spec/21-app/46-impersonation.md`, AuthSessions Kind field
- `.lovable/strictly-avoid/sa-031-pascal-case-data.md`, naming rule

---

## Acceptance

- AC-SHARD-001: creating a reseller creates exactly one App-tier DB file and one `ResellerShardRoutes` row.
- AC-SHARD-002: no licensing, prefix, or serial row exists in the Root DB after v1.0 cutover.
- AC-SHARD-003: a request with a `ResellerId` claim that resolves to a non-Active shard returns `403 ResellerShardInactive`.
- AC-SHARD-004: a migration release advances every Active shard to the same `SchemaVersion` before marking the release healthy.
- AC-SHARD-005: impersonation session rows only ever appear in the target reseller's shard, never in Root.
