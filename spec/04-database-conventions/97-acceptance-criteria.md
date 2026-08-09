# Database Conventions: Acceptance Criteria

**Version:** 3.3.0  
**Updated:** 2026-07-15

---

## Naming

| ID | Criterion | Source |
|----|-----------|--------|
| AC-DB-001 | Tables are singular PascalCase names. | `01-naming-conventions.md` |
| AC-DB-002 | Columns, indexes, views, and API fields use PascalCase. | `01-naming-conventions.md` |
| AC-DB-003 | Boolean columns use positive `Is` or `Has` names. | `01-naming-conventions.md` |

## Schema

| ID | Criterion | Source |
|----|-----------|--------|
| AC-DB-004 | Each primary key is named `{TableName}Id` and uses the smallest suitable integer type. | `02-schema-design.md` |
| AC-DB-005 | Each foreign key uses the exact referenced primary-key name. | `02-schema-design.md` |
| AC-DB-006 | Repeated categorical values are normalized into related tables. | `02-schema-design.md` |
| AC-DB-007 | Schema documentation includes a Mermaid relationship diagram. | `05-relationship-diagrams.md` |

## Data Access and Testing

| ID | Criterion | Source |
|----|-----------|--------|
| AC-DB-008 | Business logic accesses data through an ORM or repository, not embedded raw SQL. | `03-orm-and-views.md` |
| AC-DB-009 | Reusable joins are represented by database views. | `03-orm-and-views.md` |
| AC-DB-010 | Schema, migration, constraint, relationship, and CRUD behavior is tested with an in-memory database. | `04-testing-strategy.md` |
| AC-DB-011 | REST resources and response fields follow the documented PascalCase envelope. | `06-rest-api-format.md` |
| AC-DB-012 | Split databases isolate bounded contexts and are registered, migrated, and tested independently. | `07-split-db-pattern.md` |

## Verification

Run the repository database checks:

```bash
python3 linter-scripts/check-forbidden-strings.py
```

**Expected:** exit 0. Review each applicable criterion above against the schema, migration, model, API, and test files changed by the task.

## Cross-References

- [Overview](./00-overview.md)
- [Consistency Report](./99-consistency-report.md)