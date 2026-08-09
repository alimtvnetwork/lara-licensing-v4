# Acceptance Criteria Index

Version: 1.19.0
Status: Normative aggregate. Every acceptance criterion (AC-*) declared anywhere under `spec/21-app/` and `spec/23-app-db/` MUST appear here with its source file. New ACs land in their leaf spec file first, then are appended to this index in the same change.

Total ACs: 253 unique across 50 source files (dedup by ID across the tree; some ACs are cited from multiple owner files). Grouped by source file (path headings). Any AC ID present in a source spec but missing from this list is a consistency defect and MUST fail `linter-scripts/ac-index-parity.py` (which enforces the discovery rule at build time) and is cross-referenced by [`99-consistency-report.md`](./99-consistency-report.md). Note: multi-segment prefixes such as `AC-API-VER-*` (three-letter suffix after `API`) are declared in their source file and cited from consumer files, but the current linter regex only matches single-segment `AC-<PREFIX>-<NN>` shapes; extending the regex is deferred until a dedicated linter pass.

## New in v1.19.0

### spec/21-app/26-route-dto-index.md
- AC-DTO-005 (source: spec/21-app/26-route-dto-index.md)
- AC-DTO-006 (source: spec/21-app/26-route-dto-index.md)

## New in v1.18.0

### spec/21-app/28-audit-action-enum.md
- AC-AAE-006 (source: spec/21-app/28-audit-action-enum.md)

## New in v1.17.0

### spec/21-app/11-api-contracts/03-verification-contracts.md
- AC-API-VER-012 (source: spec/21-app/11-api-contracts/03-verification-contracts.md)
- AC-API-VER-013 (source: spec/21-app/11-api-contracts/03-verification-contracts.md)

### spec/21-app/12-error-taxonomy.md
- AC-ERR-010 (source: spec/21-app/12-error-taxonomy.md)
- AC-ERR-011 (source: spec/21-app/12-error-taxonomy.md)

## New in v1.16.0

### spec/21-app/45-license-features.md
- AC-FEAT-001 (source: spec/21-app/45-license-features.md)
- AC-FEAT-002 (source: spec/21-app/45-license-features.md)
- AC-FEAT-003 (source: spec/21-app/45-license-features.md)
- AC-FEAT-004 (source: spec/21-app/45-license-features.md)
- AC-FEAT-005 (source: spec/21-app/45-license-features.md)
- AC-FEAT-006 (source: spec/21-app/45-license-features.md)

## New in v1.15.0

### spec/21-app/05-license-categories.md
- AC-CAT-004 (source: spec/21-app/05-license-categories.md)

### spec/21-app/11-api-contracts/03-verification-contracts.md
- AC-API-VER-009 (source: spec/21-app/11-api-contracts/03-verification-contracts.md)
- AC-API-VER-010 (source: spec/21-app/11-api-contracts/03-verification-contracts.md)
- AC-API-VER-011 (source: spec/21-app/11-api-contracts/03-verification-contracts.md)


## New in v1.14.0

### spec/21-app/44-environments.md
- AC-LENV-001 (source: spec/21-app/44-environments.md)
- AC-LENV-002 (source: spec/21-app/44-environments.md)
- AC-LENV-003 (source: spec/21-app/44-environments.md)
- AC-LENV-004 (source: spec/21-app/44-environments.md)
- AC-LENV-005 (source: spec/21-app/44-environments.md)
- AC-LENV-006 (source: spec/21-app/44-environments.md)

### spec/23-app-db/01-schema.md
- AC-ADB-015 (source: spec/23-app-db/01-schema.md)

### spec/21-app/11-api-contracts/02-license-contracts.md
- AC-API-LIC-009 (source: spec/21-app/11-api-contracts/02-license-contracts.md)
- AC-API-LIC-010 (source: spec/21-app/11-api-contracts/02-license-contracts.md)
- AC-API-LIC-011 (source: spec/21-app/11-api-contracts/02-license-contracts.md)

### spec/21-app/12-error-taxonomy.md
- AC-ERR-009 (source: spec/21-app/12-error-taxonomy.md)

## New in v1.13.0

### spec/21-app/43-license-tiers.md
- AC-LT-001 (source: spec/21-app/43-license-tiers.md)
- AC-LT-002 (source: spec/21-app/43-license-tiers.md)
- AC-LT-003 (source: spec/21-app/43-license-tiers.md)
- AC-LT-004 (source: spec/21-app/43-license-tiers.md)
- AC-LT-005 (source: spec/21-app/43-license-tiers.md)

### spec/23-app-db/01-schema.md
- AC-ADB-013 (source: spec/23-app-db/01-schema.md)
- AC-ADB-014 (source: spec/23-app-db/01-schema.md)

## New in v1.12.0

### spec/23-app-db/01-schema.md
- AC-ADB-011 (source: spec/23-app-db/01-schema.md)
- AC-ADB-012 (source: spec/23-app-db/01-schema.md)

## New in v1.11.0

### spec/21-app/11-api-contracts/05-quota-request-contracts.md
- AC-API-QR-001 (source: spec/21-app/11-api-contracts/05-quota-request-contracts.md)
- AC-API-QR-002 (source: spec/21-app/11-api-contracts/05-quota-request-contracts.md)
- AC-API-QR-003 (source: spec/21-app/11-api-contracts/05-quota-request-contracts.md)
- AC-API-QR-004 (source: spec/21-app/11-api-contracts/05-quota-request-contracts.md)
- AC-API-QR-005 (source: spec/21-app/11-api-contracts/05-quota-request-contracts.md)
- AC-API-QR-006 (source: spec/21-app/11-api-contracts/05-quota-request-contracts.md)
- AC-API-QR-007 (source: spec/21-app/11-api-contracts/05-quota-request-contracts.md)

### spec/21-app/42-quota-requests.md
- AC-QR-001: Linear lifecycle `Pending -> Approved | Denied | Cancelled`. (source: spec/21-app/42-quota-requests.md)
- AC-QR-002: Approval MUST selectively lock `ResellerQuotas` row `FOR UPDATE`. (source: spec/21-app/42-quota-requests.md)
- AC-QR-003: Approval delta MUST be appended to `ResellerQuotaLedger` in same transaction. (source: spec/21-app/42-quota-requests.md)
- AC-QR-004: Decided status (Approved/Denied) is immutable. (source: spec/21-app/42-quota-requests.md)
- AC-QR-005: Deny requires a `DenialReason` >= 10 chars. (source: spec/21-app/42-quota-requests.md)
- AC-QR-006: Cancel is only permitted for `Pending` requests. (source: spec/21-app/42-quota-requests.md)
- AC-QR-007: Approval MUST NOT be self-decided (Admin != Submitter). (source: spec/21-app/42-quota-requests.md)

### spec/23-app-db/01-schema.md
- AC-ADB-009 (source: spec/23-app-db/01-schema.md)
- AC-ADB-010 (source: spec/23-app-db/01-schema.md)


## New in v1.10.0

### spec/23-app-db/01-schema.md
- AC-ADB-007 (source: spec/23-app-db/01-schema.md)
- AC-ADB-008 (source: spec/23-app-db/01-schema.md)

## New in v1.9.0

### spec/21-app/42-quota-requests.md
- AC-QR-001 (source: spec/21-app/42-quota-requests.md)
- AC-QR-002 (source: spec/21-app/42-quota-requests.md)
- AC-QR-003 (source: spec/21-app/42-quota-requests.md)
- AC-QR-004 (source: spec/21-app/42-quota-requests.md)
- AC-QR-005 (source: spec/21-app/42-quota-requests.md)
- AC-QR-006 (source: spec/21-app/42-quota-requests.md)
- AC-QR-007 (source: spec/21-app/42-quota-requests.md)



## New in v1.8.0

### spec/21-app/11-api-contracts/02-license-contracts.md
- AC-API-LIC-005 (source: spec/21-app/11-api-contracts/02-license-contracts.md)
- AC-API-LIC-006 (source: spec/21-app/11-api-contracts/02-license-contracts.md)
- AC-API-LIC-007 (source: spec/21-app/11-api-contracts/02-license-contracts.md)
- AC-API-LIC-008 (source: spec/21-app/11-api-contracts/02-license-contracts.md)

### spec/21-app/11-api-contracts/04-admin-contracts.md
- AC-API-ADM-006 (source: spec/21-app/11-api-contracts/04-admin-contracts.md)
- AC-API-ADM-007 (source: spec/21-app/11-api-contracts/04-admin-contracts.md)


## New in v1.7.0

### spec/21-app/12-error-taxonomy.md
- AC-ERR-006 (source: spec/21-app/12-error-taxonomy.md)
- AC-ERR-007 (source: spec/21-app/12-error-taxonomy.md)
- AC-ERR-008 (source: spec/21-app/12-error-taxonomy.md)

### spec/23-app-db/01-schema.md
- AC-ADB-004 (source: spec/23-app-db/01-schema.md)
- AC-ADB-005 (source: spec/23-app-db/01-schema.md)
- AC-ADB-006 (source: spec/23-app-db/01-schema.md)

## New in v1.6.0

### spec/21-app/41-reseller-quotas.md
- AC-QUOTA-001 (source: spec/21-app/41-reseller-quotas.md)
- AC-QUOTA-002 (source: spec/21-app/41-reseller-quotas.md)
- AC-QUOTA-003 (source: spec/21-app/41-reseller-quotas.md)
- AC-QUOTA-004 (source: spec/21-app/41-reseller-quotas.md)
- AC-QUOTA-005 (source: spec/21-app/41-reseller-quotas.md)
- AC-QUOTA-006 (source: spec/21-app/41-reseller-quotas.md)

## New in v1.5.0

### spec/21-app/04-roles.md
- AC-ROLE-005 (source: spec/21-app/04-roles.md)

### spec/21-app/10-endpoints.md
- AC-EP-006 (source: spec/21-app/10-endpoints.md)

### spec/21-app/11-api-contracts/02-license-contracts.md
- AC-API-LIC-004 (source: spec/21-app/11-api-contracts/02-license-contracts.md)

### spec/21-app/11-api-contracts/04-admin-contracts.md
- AC-API-ADM-005 (source: spec/21-app/11-api-contracts/04-admin-contracts.md)

## New in v1.4.0

### spec/21-app/12-error-taxonomy.md
- AC-ERR-005 (source: spec/21-app/12-error-taxonomy.md)

## New in v1.3.0

### spec/21-app/40-permissions.md
- AC-PERM-001 (source: spec/21-app/40-permissions.md)
- AC-PERM-002 (source: spec/21-app/40-permissions.md)
- AC-PERM-003 (source: spec/21-app/40-permissions.md)
- AC-PERM-004 (source: spec/21-app/40-permissions.md)
- AC-PERM-005 (source: spec/21-app/40-permissions.md)
- AC-PERM-006 (source: spec/21-app/40-permissions.md)
- AC-PERM-007 (source: spec/21-app/40-permissions.md)
- AC-PERM-008 (source: spec/21-app/40-permissions.md)
- AC-PERM-009 (source: spec/21-app/40-permissions.md)
- AC-PERM-010 (source: spec/21-app/40-permissions.md)

## New in v1.1.0

### spec/21-app/diagrams/00-diagram-contract.md
- AC-DG-001 (source: spec/21-app/diagrams/00-diagram-contract.md)
- AC-DG-002 (source: spec/21-app/diagrams/00-diagram-contract.md)



## Discovery rule

`rg -o "AC-[A-Z]+-[0-9]+" spec/21-app/ spec/23-app-db/ | sort -u` MUST equal the set enumerated below. The list is regenerated by that command, not hand-authored.

## Index

### spec/21-app/28-audit-action-enum.md
- AC-AAE-001 (source: spec/21-app/28-audit-action-enum.md)
- AC-AAE-002 (source: spec/21-app/28-audit-action-enum.md)
- AC-AAE-003 (source: spec/21-app/28-audit-action-enum.md)
- AC-AAE-004 (source: spec/21-app/28-audit-action-enum.md)
- AC-AAE-005 (source: spec/21-app/28-audit-action-enum.md)
- AC-AAE-006 (source: spec/21-app/28-audit-action-enum.md)

### spec/23-app-db/00-overview.md
- AC-ADB-000 (source: spec/23-app-db/00-overview.md)

### spec/23-app-db/01-schema.md
- AC-ADB-001 (source: spec/23-app-db/01-schema.md)
- AC-ADB-002 (source: spec/23-app-db/01-schema.md)
- AC-ADB-003 (source: spec/23-app-db/01-schema.md)
- AC-ADB-004 (source: spec/23-app-db/01-schema.md)
- AC-ADB-005 (source: spec/23-app-db/01-schema.md)
- AC-ADB-006 (source: spec/23-app-db/01-schema.md)

### spec/21-app/11-api-contracts/07-admin-list-envelope-hardening.md
- AC-ALEH-001 (source: spec/21-app/11-api-contracts/07-admin-list-envelope-hardening.md)
- AC-ALEH-002 (source: spec/21-app/11-api-contracts/07-admin-list-envelope-hardening.md)
- AC-ALEH-003 (source: spec/21-app/11-api-contracts/07-admin-list-envelope-hardening.md)
- AC-ALEH-004 (source: spec/21-app/11-api-contracts/07-admin-list-envelope-hardening.md)
- AC-ALEH-005 (source: spec/21-app/11-api-contracts/07-admin-list-envelope-hardening.md)

### spec/21-app/11-api-contracts/00-overview.md
- AC-API-001 (source: spec/21-app/11-api-contracts/00-overview.md)
- AC-API-002 (source: spec/21-app/11-api-contracts/00-overview.md)
- AC-API-003 (source: spec/21-app/11-api-contracts/00-overview.md)

### spec/21-app/31-auth-session-family.md
- AC-ASF-001 (source: spec/21-app/31-auth-session-family.md)
- AC-ASF-002 (source: spec/21-app/31-auth-session-family.md)
- AC-ASF-003 (source: spec/21-app/31-auth-session-family.md)
- AC-ASF-004 (source: spec/21-app/31-auth-session-family.md)
- AC-ASF-005 (source: spec/21-app/31-auth-session-family.md)

### spec/21-app/32-auth-session-retention.md
- AC-ASR-001 (source: spec/21-app/32-auth-session-retention.md)
- AC-ASR-002 (source: spec/21-app/32-auth-session-retention.md)
- AC-ASR-003 (source: spec/21-app/32-auth-session-retention.md)
- AC-ASR-004 (source: spec/21-app/32-auth-session-retention.md)
- AC-ASR-005 (source: spec/21-app/32-auth-session-retention.md)

### spec/21-app/13-audit-logging.md
- AC-AUD-001 (source: spec/21-app/13-audit-logging.md)
- AC-AUD-002 (source: spec/21-app/13-audit-logging.md)
- AC-AUD-003 (source: spec/21-app/13-audit-logging.md)
- AC-AUD-004 (source: spec/21-app/13-audit-logging.md)
- AC-AUD-005 (source: spec/21-app/13-audit-logging.md)
- AC-AUD-006 (source: spec/21-app/13-audit-logging.md)
- AC-AUD-007 (source: spec/21-app/13-audit-logging.md)
- AC-AUD-008 (source: spec/21-app/13-audit-logging.md)
- AC-AUD-009 (source: spec/21-app/13-audit-logging.md)
- AC-ROLE-004 (source: spec/21-app/13-audit-logging.md)

### spec/21-app/19-user-management.md
- AC-AUD-004 (source: spec/21-app/19-user-management.md)
- AC-AUD-018 (source: spec/21-app/19-user-management.md)
- AC-AUD-019 (source: spec/21-app/19-user-management.md)
- AC-AUD-020 (source: spec/21-app/19-user-management.md)
- AC-AUD-024 (source: spec/21-app/19-user-management.md)
- AC-AUD-025 (source: spec/21-app/19-user-management.md)

### spec/21-app/17-self-update-endpoint.md
- AC-AUD-006 (source: spec/21-app/17-self-update-endpoint.md)
- AC-AUD-014 (source: spec/21-app/17-self-update-endpoint.md)
- AC-AUD-015 (source: spec/21-app/17-self-update-endpoint.md)
- AC-AUD-016 (source: spec/21-app/17-self-update-endpoint.md)
- AC-AUD-017 (source: spec/21-app/17-self-update-endpoint.md)

### spec/21-app/20-observability.md
- AC-AUD-006 (source: spec/21-app/20-observability.md)
- AC-AUD-018 (source: spec/21-app/20-observability.md)
- AC-AUD-024 (source: spec/21-app/20-observability.md)
- AC-AUD-025 (source: spec/21-app/20-observability.md)

### spec/21-app/18-publishing-powershell.md
- AC-AUD-007 (source: spec/21-app/18-publishing-powershell.md)
- AC-AUD-021 (source: spec/21-app/18-publishing-powershell.md)
- AC-AUD-022 (source: spec/21-app/18-publishing-powershell.md)
- AC-AUD-023 (source: spec/21-app/18-publishing-powershell.md)
- AC-AUD-024 (source: spec/21-app/18-publishing-powershell.md)

### spec/21-app/05-license-categories.md
- AC-CAT-001 (source: spec/21-app/05-license-categories.md)
- AC-CAT-002 (source: spec/21-app/05-license-categories.md)
- AC-CAT-003 (source: spec/21-app/05-license-categories.md)

### spec/21-app/23-catch-log-rethrow-patterns.md
- AC-CLR-001 (source: spec/21-app/23-catch-log-rethrow-patterns.md)
- AC-CLR-002 (source: spec/21-app/23-catch-log-rethrow-patterns.md)
- AC-CLR-003 (source: spec/21-app/23-catch-log-rethrow-patterns.md)
- AC-CLR-004 (source: spec/21-app/23-catch-log-rethrow-patterns.md)
- AC-CLR-005 (source: spec/21-app/23-catch-log-rethrow-patterns.md)

### spec/21-app/26-route-dto-index.md
- AC-DTO-001 (source: spec/21-app/26-route-dto-index.md)
- AC-DTO-002 (source: spec/21-app/26-route-dto-index.md)
- AC-DTO-003 (source: spec/21-app/26-route-dto-index.md)
- AC-DTO-004 (source: spec/21-app/26-route-dto-index.md)

### spec/21-app/11-api-contracts/06-envelope-attributes.md
- AC-EAT-001 (source: spec/21-app/11-api-contracts/06-envelope-attributes.md)
- AC-EAT-002 (source: spec/21-app/11-api-contracts/06-envelope-attributes.md)
- AC-EAT-003 (source: spec/21-app/11-api-contracts/06-envelope-attributes.md)
- AC-EAT-004 (source: spec/21-app/11-api-contracts/06-envelope-attributes.md)

### spec/21-app/21-error-management-binding.md
- AC-EMB-001 (source: spec/21-app/21-error-management-binding.md)
- AC-EMB-002 (source: spec/21-app/21-error-management-binding.md)
- AC-EMB-003 (source: spec/21-app/21-error-management-binding.md)

### spec/21-app/22-log-line-contract.md
- AC-EMB-003 (source: spec/21-app/22-log-line-contract.md)
- AC-LOG-001 (source: spec/21-app/22-log-line-contract.md)
- AC-LOG-002 (source: spec/21-app/22-log-line-contract.md)
- AC-LOG-003 (source: spec/21-app/22-log-line-contract.md)

### spec/21-app/11-api-contracts/05-envelope-schema.md
- AC-ENV-001 (source: spec/21-app/11-api-contracts/05-envelope-schema.md)
- AC-ENV-002 (source: spec/21-app/11-api-contracts/05-envelope-schema.md)
- AC-ENV-003 (source: spec/21-app/11-api-contracts/05-envelope-schema.md)
- AC-ENV-004 (source: spec/21-app/11-api-contracts/05-envelope-schema.md)
- AC-ENV-005 (source: spec/21-app/11-api-contracts/05-envelope-schema.md)
- AC-ENV-006 (source: spec/21-app/11-api-contracts/05-envelope-schema.md)

### spec/21-app/10-endpoints.md
- AC-EP-001 (source: spec/21-app/10-endpoints.md)
- AC-EP-002 (source: spec/21-app/10-endpoints.md)
- AC-EP-003 (source: spec/21-app/10-endpoints.md)
- AC-EP-004 (source: spec/21-app/10-endpoints.md)
- AC-EP-005 (source: spec/21-app/10-endpoints.md)

### spec/21-app/12-error-taxonomy.md
- AC-ERR-001 (source: spec/21-app/12-error-taxonomy.md)
- AC-ERR-002 (source: spec/21-app/12-error-taxonomy.md)
- AC-ERR-003 (source: spec/21-app/12-error-taxonomy.md)
- AC-ERR-004 (source: spec/21-app/12-error-taxonomy.md)
- AC-ERR-005 (source: spec/21-app/12-error-taxonomy.md)
- AC-ERR-006 (source: spec/21-app/12-error-taxonomy.md)
- AC-ERR-007 (source: spec/21-app/12-error-taxonomy.md)
- AC-ERR-008 (source: spec/21-app/12-error-taxonomy.md)
- AC-ERR-009 (source: spec/21-app/12-error-taxonomy.md)
- AC-ERR-010 (source: spec/21-app/12-error-taxonomy.md)
- AC-ERR-011 (source: spec/21-app/12-error-taxonomy.md)

### spec/21-app/08-hash-key.md
- AC-HASH-001 (source: spec/21-app/08-hash-key.md)
- AC-HASH-002 (source: spec/21-app/08-hash-key.md)
- AC-HASH-003 (source: spec/21-app/08-hash-key.md)

### spec/21-app/29-idempotency-lifecycle.md
- AC-IDL-001 (source: spec/21-app/29-idempotency-lifecycle.md)
- AC-IDL-002 (source: spec/21-app/29-idempotency-lifecycle.md)
- AC-IDL-003 (source: spec/21-app/29-idempotency-lifecycle.md)
- AC-IDL-004 (source: spec/21-app/29-idempotency-lifecycle.md)
- AC-IDL-005 (source: spec/21-app/29-idempotency-lifecycle.md)
- AC-IDL-006 (source: spec/21-app/29-idempotency-lifecycle.md)
- AC-IDL-007 (source: spec/21-app/29-idempotency-lifecycle.md)
- AC-IDL-008 (source: spec/21-app/29-idempotency-lifecycle.md)

### spec/21-app/11-api-contracts/08-idempotency-envelope-hardening.md
- AC-IEH-001 (source: spec/21-app/11-api-contracts/08-idempotency-envelope-hardening.md)
- AC-IEH-002 (source: spec/21-app/11-api-contracts/08-idempotency-envelope-hardening.md)
- AC-IEH-003 (source: spec/21-app/11-api-contracts/08-idempotency-envelope-hardening.md)
- AC-IEH-004 (source: spec/21-app/11-api-contracts/08-idempotency-envelope-hardening.md)
- AC-IEH-005 (source: spec/21-app/11-api-contracts/08-idempotency-envelope-hardening.md)
- AC-IEH-006 (source: spec/21-app/11-api-contracts/08-idempotency-envelope-hardening.md)
- AC-IEH-007 (source: spec/21-app/11-api-contracts/08-idempotency-envelope-hardening.md)
- AC-IEH-008 (source: spec/21-app/11-api-contracts/08-idempotency-envelope-hardening.md)
- AC-IEH-009 (source: spec/21-app/11-api-contracts/08-idempotency-envelope-hardening.md)
- AC-IEH-010 (source: spec/21-app/11-api-contracts/08-idempotency-envelope-hardening.md)
- AC-IEH-011 (source: spec/21-app/11-api-contracts/08-idempotency-envelope-hardening.md)
- AC-IEH-012 (source: spec/21-app/11-api-contracts/08-idempotency-envelope-hardening.md)

### spec/21-app/02-authentication-jwt.md
- AC-JWT-001 (source: spec/21-app/02-authentication-jwt.md)
- AC-JWT-002 (source: spec/21-app/02-authentication-jwt.md)
- AC-JWT-003 (source: spec/21-app/02-authentication-jwt.md)

### spec/21-app/15-license-lifecycle.md
- AC-LL-001 (source: spec/21-app/15-license-lifecycle.md)
- AC-LL-002 (source: spec/21-app/15-license-lifecycle.md)
- AC-LL-003 (source: spec/21-app/15-license-lifecycle.md)
- AC-LL-004 (source: spec/21-app/15-license-lifecycle.md)
- AC-LL-005 (source: spec/21-app/15-license-lifecycle.md)
- AC-LL-006 (source: spec/21-app/15-license-lifecycle.md)
- AC-LL-007 (source: spec/21-app/15-license-lifecycle.md)

### spec/21-app/30-machine-bindings.md
- AC-MB-001 (source: spec/21-app/30-machine-bindings.md)
- AC-MB-002 (source: spec/21-app/30-machine-bindings.md)
- AC-MB-003 (source: spec/21-app/30-machine-bindings.md)
- AC-MB-004 (source: spec/21-app/30-machine-bindings.md)
- AC-MB-005 (source: spec/21-app/30-machine-bindings.md)
- AC-MB-006 (source: spec/21-app/30-machine-bindings.md)
- AC-MB-007 (source: spec/21-app/30-machine-bindings.md)
- AC-MB-008 (source: spec/21-app/30-machine-bindings.md)
- AC-MB-009 (source: spec/21-app/30-machine-bindings.md)

### spec/23-app-db/02-migration-order.md
- AC-MIG-001 (source: spec/23-app-db/02-migration-order.md)
- AC-MIG-002 (source: spec/23-app-db/02-migration-order.md)
- AC-MIG-003 (source: spec/23-app-db/02-migration-order.md)
- AC-MIG-004 (source: spec/23-app-db/02-migration-order.md)
- AC-MIG-005 (source: spec/23-app-db/02-migration-order.md)
- AC-MIG-006 (source: spec/23-app-db/02-migration-order.md)
- AC-MIG-007 (source: spec/23-app-db/02-migration-order.md)

### spec/21-app/03-authentication-oauth.md
- AC-OAUTH-001 (source: spec/21-app/03-authentication-oauth.md)
- AC-OAUTH-002 (source: spec/21-app/03-authentication-oauth.md)
- AC-OAUTH-003 (source: spec/21-app/03-authentication-oauth.md)

### spec/21-app/25-retry-decision-matrix.md
- AC-RETRY-001 (source: spec/21-app/25-retry-decision-matrix.md)
- AC-RETRY-002 (source: spec/21-app/25-retry-decision-matrix.md)
- AC-RETRY-003 (source: spec/21-app/25-retry-decision-matrix.md)
- AC-RETRY-004 (source: spec/21-app/25-retry-decision-matrix.md)

### spec/21-app/14-rate-limiting.md
- AC-RL-001 (source: spec/21-app/14-rate-limiting.md)
- AC-RL-002 (source: spec/21-app/14-rate-limiting.md)
- AC-RL-003 (source: spec/21-app/14-rate-limiting.md)
- AC-RL-004 (source: spec/21-app/14-rate-limiting.md)
- AC-RL-005 (source: spec/21-app/14-rate-limiting.md)
- AC-RL-006 (source: spec/21-app/14-rate-limiting.md)
- AC-RL-007 (source: spec/21-app/14-rate-limiting.md)
- AC-RL-008 (source: spec/21-app/14-rate-limiting.md)
- AC-RL-009 (source: spec/21-app/14-rate-limiting.md)
- AC-RL-010 (source: spec/21-app/14-rate-limiting.md)
- AC-RL-011 (source: spec/21-app/14-rate-limiting.md)

### spec/21-app/04-roles.md
- AC-ROLE-001 (source: spec/21-app/04-roles.md)
- AC-ROLE-002 (source: spec/21-app/04-roles.md)
- AC-ROLE-003 (source: spec/21-app/04-roles.md)
- AC-ROLE-004 (source: spec/21-app/04-roles.md)

### spec/21-app/35-security-events.md
- AC-SEC-001 (source: spec/21-app/35-security-events.md)
- AC-SEC-002 (source: spec/21-app/35-security-events.md)
- AC-SEC-003 (source: spec/21-app/35-security-events.md)
- AC-SEC-004 (source: spec/21-app/35-security-events.md)
- AC-SEC-005 (source: spec/21-app/35-security-events.md)
- AC-SEC-006 (source: spec/21-app/35-security-events.md)
- AC-SEC-007 (source: spec/21-app/35-security-events.md)

### spec/21-app/07-serial-generation.md
- AC-SER-001 (source: spec/21-app/07-serial-generation.md)
- AC-SER-002 (source: spec/21-app/07-serial-generation.md)
- AC-SER-003 (source: spec/21-app/07-serial-generation.md)

### spec/21-app/16-ui-surfaces.md
- AC-UI-001 (source: spec/21-app/16-ui-surfaces.md)
- AC-UI-002 (source: spec/21-app/16-ui-surfaces.md)
- AC-UI-003 (source: spec/21-app/16-ui-surfaces.md)
- AC-UI-004 (source: spec/21-app/16-ui-surfaces.md)
- AC-UI-005 (source: spec/21-app/16-ui-surfaces.md)
- AC-UI-006 (source: spec/21-app/16-ui-surfaces.md)
- AC-UI-007 (source: spec/21-app/16-ui-surfaces.md)
- AC-UI-008 (source: spec/21-app/16-ui-surfaces.md)
- AC-UI-009 (source: spec/21-app/16-ui-surfaces.md)

### spec/21-app/06-license-variations.md
- AC-VAR-001 (source: spec/21-app/06-license-variations.md)
- AC-VAR-002 (source: spec/21-app/06-license-variations.md)
- AC-VAR-003 (source: spec/21-app/06-license-variations.md)

### spec/21-app/09-verify-key.md
- AC-VK-001 (source: spec/21-app/09-verify-key.md)
- AC-VK-002 (source: spec/21-app/09-verify-key.md)
- AC-VK-003 (source: spec/21-app/09-verify-key.md)

### spec/21-app/24-vocabulary-normalization.md
- AC-VN-001 (source: spec/21-app/24-vocabulary-normalization.md)
- AC-VN-002 (source: spec/21-app/24-vocabulary-normalization.md)
- AC-VN-003 (source: spec/21-app/24-vocabulary-normalization.md)
- AC-VN-004 (source: spec/21-app/24-vocabulary-normalization.md)
- AC-VN-005 (source: spec/21-app/24-vocabulary-normalization.md)

