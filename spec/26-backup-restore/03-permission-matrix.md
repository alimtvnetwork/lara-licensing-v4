# Permission Matrix

**Version:** 1.0.0
**Updated:** 2026-07-20
**AI Confidence:** Draft
**Ambiguity:** Low

---

## Keywords

`permission-matrix` · `casbin-seed` · `capability` · `rbac` · `deputy` · `deny-override`

---

## Scoring

| Criterion | Status |
|-----------|--------|
| `00-overview.md` present in module | ✅ |
| AI Confidence assigned | ✅ |
| Ambiguity assigned | ✅ |
| Keywords present | ✅ |
| Scoring table present | ✅ |

---

## Purpose

Authoritative role x capability matrix for the Backup/Restore/Snapshot
module. Every row in this document seeds exactly one `p` row in
`casbin_rules` (see [`02-casbin-integration.md`](./02-casbin-integration.md))
and every endpoint spec (`11..15`) cites this file when declaring its
`403 Rbac.Denied` conditions. The matrix is the single source of truth;
if a capability is not listed here, no role has it.

---

## Capability Catalogue

Closed set. Adding or removing a capability requires a version bump of
this file and a matching change to
[`02-casbin-integration.md`](./02-casbin-integration.md) seeds.

| Capability             | HTTP Binding                                             | Description                                          |
|------------------------|----------------------------------------------------------|------------------------------------------------------|
| `Backup.Export`        | `POST /Api/V1/Admin/Backup/Export`                       | Start an export job, download archive when ready.    |
| `Backup.Export.Read`   | `GET /Api/V1/Admin/Backup/Export/{jobId}`                | Read export job status / progress.                   |
| `Backup.Import`        | `POST /Api/V1/Admin/Backup/Import`                       | Upload archive, run pre-flight, apply.               |
| `Backup.Import.Read`   | `GET /Api/V1/Admin/Backup/Import/{jobId}`                | Read import job status / diff report.                |
| `Snapshot.Create`      | `POST /Api/V1/Admin/Snapshot`                            | Take an on-demand snapshot.                          |
| `Snapshot.Read`        | `GET /Api/V1/Admin/Snapshot`, `GET .../{id}`             | List snapshots, view metadata.                       |
| `Snapshot.Delete`      | `DELETE /Api/V1/Admin/Snapshot/{id}`                     | Delete a snapshot (respects pin).                    |
| `Snapshot.Pin`         | `POST /Api/V1/Admin/Snapshot/{id}/Pin`                   | Pin/unpin against retention sweep.                   |
| `Snapshot.Restore`     | `POST /Api/V1/Admin/Snapshot/{id}/Restore`               | Restore full or selective scope from snapshot.       |
| `Role.Manage`          | `POST/PATCH/DELETE /Api/V1/Admin/Roles*`                 | Grant/revoke roles, edit Casbin policy rows.         |
| `Role.Read`            | `GET /Api/V1/Admin/Roles*`                               | List roles, view effective permissions per user.     |
| `System.Configure`     | `PATCH /Api/V1/Admin/System/Config`                      | Edit retention windows, encryption epochs, quotas.   |
| `System.Read`          | `GET /Api/V1/Admin/System/Config`, `GET .../Health`      | Read system config and health.                       |
| `Audit.Read`           | `GET /Api/V1/Admin/Audit*`                               | Read the immutable audit trail.                      |

Every path above is normative; the `CasbinPepMiddleware` matches the
literal request path via `keyMatch2` per
[`02-casbin-integration.md`](./02-casbin-integration.md) §Model.

---

## Role x Capability Matrix

Legend: `A` = allow, `D` = explicit deny (deny-overrides per
`policy_effect`), blank = no policy row (implicit deny).

| Capability             | super_admin | admin | operator | auditor | user | deputy |
|------------------------|:-----------:|:-----:|:--------:|:-------:|:----:|:------:|
| `Backup.Export`        | A           | A     | A        |         |      | A      |
| `Backup.Export.Read`   | A           | A     | A        | A       |      | A      |
| `Backup.Import`        | A           | A     |          |         |      | **D**  |
| `Backup.Import.Read`   | A           | A     | A        | A       |      | A      |
| `Snapshot.Create`      | A           | A     | A        |         |      | A      |
| `Snapshot.Read`        | A           | A     | A        | A       |      | A      |
| `Snapshot.Delete`      | A           | A     |          |         |      |        |
| `Snapshot.Pin`         | A           | A     | A        |         |      |        |
| `Snapshot.Restore`     | A           | A     |          |         |      | **D**  |
| `Role.Manage`          | A           |       |          |         |      |        |
| `Role.Read`            | A           | A     |          | A       |      |        |
| `System.Configure`     | A           |       |          |         |      |        |
| `System.Read`          | A           | A     | A        | A       |      |        |
| `Audit.Read`           | A           | A     |          | A       |      |        |

Notes:

1. `user` is the default role assigned to every registered account
   (per [`01-actors-and-roles.md`](./01-actors-and-roles.md)); it holds
   no Backup/Restore/Snapshot capabilities. Blank cells mean no `p` row
   is seeded, which the deny-overrides effect resolves to deny.
2. `deputy` inherits the allow rows from its delegating role via `g,
   deputy, <parent_role>` at grant time, but `Backup.Import` and
   `Snapshot.Restore` carry explicit `deny` rows (`D`) so the
   deny-overrides effect blocks them even when the parent role allows.
   This encodes `INV-BR-ACT-4` from
   [`01-actors-and-roles.md`](./01-actors-and-roles.md).
3. Only `super_admin` holds `Role.Manage` and `System.Configure`. This
   preserves the single-super-admin bootstrap invariant and prevents
   admins from escalating privileges through the PAP.
4. `auditor` is read-only across the module. It never sees write
   capabilities and its `Audit.Read` allow is what makes independent
   compliance review possible without granting `admin`.
5. `Snapshot.Delete` is restricted to `super_admin` and `admin`;
   operators can create and pin but not destroy, matching the least
   privilege posture for on-call staff.

---

## Casbin Seed Rows

Every allow cell in the matrix `MUST` seed exactly one `p` row; every
`D` cell `MUST` seed one `p` row with `eft = deny`. The seed migration
in `<spec-placeholder file="25-migration-and-rollout.md" />` `MUST`
generate the rows below verbatim (formatting is normative for the
consistency-report diff at step 30).

```csv
# super_admin: full module access via wildcard
p, super_admin, /Api/V1/Admin/*,                          .*,             allow

# admin
p, admin,       /Api/V1/Admin/Backup/Export,              POST,           allow
p, admin,       /Api/V1/Admin/Backup/Export/*,            GET,            allow
p, admin,       /Api/V1/Admin/Backup/Import,              POST,           allow
p, admin,       /Api/V1/Admin/Backup/Import/*,            GET,            allow
p, admin,       /Api/V1/Admin/Snapshot,                   (GET|POST),     allow
p, admin,       /Api/V1/Admin/Snapshot/*,                 (GET|DELETE),   allow
p, admin,       /Api/V1/Admin/Snapshot/*/Pin,             POST,           allow
p, admin,       /Api/V1/Admin/Snapshot/*/Restore,         POST,           allow
p, admin,       /Api/V1/Admin/Roles*,                     GET,            allow
p, admin,       /Api/V1/Admin/System/Config,              GET,            allow
p, admin,       /Api/V1/Admin/System/Health,              GET,            allow
p, admin,       /Api/V1/Admin/Audit*,                     GET,            allow

# operator
p, operator,    /Api/V1/Admin/Backup/Export,              POST,           allow
p, operator,    /Api/V1/Admin/Backup/Export/*,            GET,            allow
p, operator,    /Api/V1/Admin/Backup/Import/*,            GET,            allow
p, operator,    /Api/V1/Admin/Snapshot,                   (GET|POST),     allow
p, operator,    /Api/V1/Admin/Snapshot/*,                 GET,            allow
p, operator,    /Api/V1/Admin/Snapshot/*/Pin,             POST,           allow
p, operator,    /Api/V1/Admin/System/Config,              GET,            allow
p, operator,    /Api/V1/Admin/System/Health,              GET,            allow

# auditor (read-only)
p, auditor,     /Api/V1/Admin/Backup/Export/*,            GET,            allow
p, auditor,     /Api/V1/Admin/Backup/Import/*,            GET,            allow
p, auditor,     /Api/V1/Admin/Snapshot,                   GET,            allow
p, auditor,     /Api/V1/Admin/Snapshot/*,                 GET,            allow
p, auditor,     /Api/V1/Admin/Roles*,                     GET,            allow
p, auditor,     /Api/V1/Admin/System/Config,              GET,            allow
p, auditor,     /Api/V1/Admin/System/Health,              GET,            allow
p, auditor,     /Api/V1/Admin/Audit*,                     GET,            allow

# deputy explicit deny (deny-overrides)
p, deputy,      /Api/V1/Admin/Backup/Import,              .*,             deny
p, deputy,      /Api/V1/Admin/Snapshot/*/Restore,         .*,             deny
```

`g, deputy, <parent_role>` rows are written at delegation time by the
Role Transfer flow in
[`01-actors-and-roles.md`](./01-actors-and-roles.md) §Deputy; they are
not seeded here because deputy assignments are per-user and bounded to
24 hours.

FE capability mirrors (advisory only per
[`02-casbin-integration.md`](./02-casbin-integration.md) §PEP Placement):

```csv
p, super_admin, Capability:*,                             .*,             allow
p, admin,       Capability:Backup.Export,                 .*,             allow
p, admin,       Capability:Backup.Import,                 .*,             allow
p, admin,       Capability:Snapshot.*,                    .*,             allow
p, admin,       Capability:System.Read,                   .*,             allow
p, admin,       Capability:Audit.Read,                    .*,             allow
p, operator,    Capability:Backup.Export,                 .*,             allow
p, operator,    Capability:Snapshot.Create,               .*,             allow
p, operator,    Capability:Snapshot.Pin,                  .*,             allow
p, auditor,     Capability:Snapshot.Read,                 .*,             allow
p, auditor,     Capability:Audit.Read,                    .*,             allow
p, auditor,     Capability:Role.Read,                     .*,             allow
p, deputy,      Capability:Backup.Import,                 .*,             deny
p, deputy,      Capability:Snapshot.Restore,              .*,             deny
```

---

## Denial Contract

When the enforcer returns false, the PEP `MUST` throw
`LaraException::forbidden('Rbac.Denied', ...)` which renders the
canonical envelope from `spec/03-error-manage/` with:

- `Attributes.Error.ErrorCode = "Rbac.Denied"`
- `Attributes.Error.Message = "Access denied."` (no capability leak)
- `Attributes.ErrorId = <uuid4>`
- HTTP status `403`
- `lara-diag` WARNING entry with `sub`, `obj`, `act`, `RequestId`, and
  `matched_policy: null` (matcher log schema per
  [`02-casbin-integration.md`](./02-casbin-integration.md) §Observability).

The response `MUST NOT` disclose which capability was missing; the FE
Global Error Modal displays a generic denial and the operator
correlates via `ErrorId` in `lara-diag`.

---

## Invariants

- `INV-BR-PM-1`: every allow/deny cell in the matrix corresponds to
  exactly one seed row in `casbin_rules`; the step 30 consistency
  report diff is empty.
- `INV-BR-PM-2`: `Role.Manage` and `System.Configure` are held only by
  `super_admin`; a policy row granting either to another role fails
  the consistency report.
- `INV-BR-PM-3`: `deputy` deny rows for `Backup.Import` and
  `Snapshot.Restore` are always present in `casbin_rules`, regardless
  of whether any deputy grouping row exists at the moment.
- `INV-BR-PM-4`: `user` holds zero rows in this module.
- `INV-BR-PM-5`: adding a new capability requires updating (a) the
  Capability Catalogue table above, (b) the Role x Capability matrix,
  (c) the Casbin Seed Rows CSV, (d) the endpoint spec that introduces
  it, and (e) the migration in step 26. Missing any of these fails
  the step 30 report.

---

## Cross-References

- Parent: [`00-overview.md`](./00-overview.md) (endpoint inventory the
  paths in this file are drawn from).
- Sibling: [`01-actors-and-roles.md`](./01-actors-and-roles.md) (role
  names, deputy invariant `INV-BR-ACT-4`).
- Sibling: [`02-casbin-integration.md`](./02-casbin-integration.md)
  (model, adapter, PEP placement, log schema).
- Downstream: `<spec-placeholder file="04-invariants.md" />`
  (promotes `INV-BR-PM-1..5` into the module-level invariant list).
- Downstream: `<spec-placeholder file="11-endpoint-export.md" />`,
  `<spec-placeholder file="12-endpoint-import.md" />`,
  `<spec-placeholder file="13-endpoint-snapshot.md" />`,
  `<spec-placeholder file="14-endpoint-restore.md" />` (each cites the
  row here that gates its 403).
- Downstream: `<spec-placeholder file="21-fe-roles-and-casbin-ui.md" />`
  (renders the matrix as an effective-permissions table per user).
- Downstream: `<spec-placeholder file="25-migration-and-rollout.md" />`
  (ships the seed CSV verbatim in the initial migration).
- Error contract: `spec/03-error-manage/` (`Rbac.Denied` envelope).
