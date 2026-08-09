# Acceptance Criteria

| ID | Criterion | Evidence |
|---|---|---|
| AC-BR-1 | Super Admin can export full system | E2E Test |
| AC-BR-2 | Export contains schemas, config, and files | Integration Test |
| AC-BR-3 | Import restores system state idempotently | Integration Test |
| AC-BR-4 | Import verifies contentHash and schemaHash | Integration Test |
| AC-BR-5 | Point-in-time snapshot can be created | E2E Test |
| AC-BR-6 | Snapshot can be restored | E2E Test |
| AC-BR-7 | Casbin roles correctly restrict Backup.* actions | Integration Test |
| AC-BR-8 | Errors mapped to proper error-contract | Unit Test |
