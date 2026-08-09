# FE Roles and Casbin UI

**Version:** 1.0.0
**Updated:** 2026-07-20
**AI Confidence:** Draft
**Ambiguity:** Low

---

## Keywords

`fe` · `roles` · `casbin` · `policy-editor` · `effective-permissions` · `deputy-deny` · `dry-run` · `lockout-guard` · `state-chart`

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

Pin the FE state chart and endpoint wiring for the Super Admin
Roles page hosted at route anchor `RA-BR-6`
(`admin.roles.tsx`) from
[`17-fe-routes.md`](./17-fe-routes.md). The page consumes the
Casbin PDP from
[`02-casbin-integration.md`](./02-casbin-integration.md) and the
14-capability matrix from
[`03-permission-matrix.md`](./03-permission-matrix.md). Without
this file the FE can drift from `policy.csv` (deny-override for
`deputy` on `Backup.Import` and `Snapshot.Restore` becomes
invisible to the operator), and there is no dry-run preview to
prevent a save that removes the last `Role.Manage` holder (system
lockout).

---

## Route

- Anchor `RA-BR-6`, file `src/routes/_authenticated/admin.roles.tsx`.
- Primary capability guard: `Role.Read` MUST resolve `true` before
  any BR query mounts; on `false` render `<StateForbidden>`.
- Write actions gated additionally by `Role.Manage`; missing this
  capability MUST render the write controls disabled with a
  `<StateForbidden>` tooltip, never hidden silently.

---

## Page Composition

Three panels, all backed by server queries. No client-side
authoritative policy state.

| Panel                        | Purpose                                                       |
|------------------------------|---------------------------------------------------------------|
| Role Catalogue               | List of six seeded roles (`super_admin`, `admin`, `operator`, `auditor`, `user`, `deputy`) from `01-actors-and-roles.md`. Roles are seeded, not user-created; the FE MUST NOT render a `Create Role` control unless `System.Configure` is also held. |
| Policy Matrix Editor         | 14 x 6 grid mirroring `03-permission-matrix.md`. Each cell is one of `{Allow, Deny, Unset}`. Deny cells render with a red border to make deny-override visible.                                             |
| Effective Permissions Preview| Per-user resolver: pick a user, see the concrete allow/deny decision per capability with the winning policy row cited. Read-only. |

---

## State Chart

Seven states.

```
[Loading] --2xx--> [Ready] --edit--> [Dirty] --preview--> [DryRunReady]
    │                                     │                    │
    │                                     │                    ├--save-->  [Saving]  --2xx--> [Ready]
    │                                     │                    │                     --409-->  [ConflictReload]
    │                                     └--discard--> [Ready]
    └--5xx / offline--> [Failed]
```

Legal states: `Loading`, `Ready`, `Dirty`, `DryRunReady`,
`Saving`, `ConflictReload`, `Failed`.

Rules:

1. `Save` is disabled unless the current diff has been submitted
   to the dry-run endpoint AND the dry-run response contains zero
   blocking findings (see Lockout Guard).
2. Any 409 `Policy.VersionMismatch` transitions to
   `ConflictReload` which forces the operator to re-fetch and
   re-apply their edits; the FE MUST NOT auto-merge.
3. Optimistic UI is forbidden; the matrix reflects only
   server-confirmed state.

---

## Endpoint Wiring

| Action                        | Endpoint                                                                     |
|-------------------------------|------------------------------------------------------------------------------|
| Load roles                    | `GET /api/admin/roles`                                                        |
| Load capabilities             | `GET /api/admin/capabilities` (returns the 14-capability closed set)          |
| Load policy matrix            | `GET /api/admin/policies?version=current` (returns rows + `policyVersion`)    |
| Effective permissions preview | `GET /api/admin/policies/effective?userId={uuid}`                             |
| Dry-run diff                  | `POST /api/admin/policies/preview` with `{basedOn: policyVersion, edits}`     |
| Save                          | `POST /api/admin/policies` with `If-Match: <policyVersion>` header            |
| Audit reference               | `GET /api/admin/audit?target=policy&limit=...`                                |

Rules:

1. Save MUST use `If-Match: <policyVersion>` from the last
   successful GET; a version mismatch returns 409
   `Policy.VersionMismatch` and the FE transitions to
   `ConflictReload`.
2. Every mutating request mints a per-submission UUIDv7
   `Idempotency-Key` per
   [`16-idempotency-and-locks.md`](./16-idempotency-and-locks.md).
3. `Retry-After` on 429/503 is honored via `useSubmitLock`; no
   auto-retry.

---

## Deputy Deny Visibility

`policy.csv` seeds explicit deny rows for `deputy`:

```
p, deputy, /Api/V1/Admin/Backup/Import,        .*, deny
p, deputy, /Api/V1/Admin/Snapshot/*/Restore,   .*, deny
```

The matrix editor MUST render these cells with:

- red border,
- lock icon,
- tooltip `roles.matrix.denyOverride.explanation`,
- disabled toggle unless `System.Configure` is held (super_admin
  only in seed).

Removing a seeded deny row is a policy change that MUST flow
through dry-run and produce a `WARN-DEPUTY-DENY-REMOVED` finding.

---

## Lockout Guard (Dry-Run Findings)

The dry-run endpoint returns a closed-set list of findings. The
matrix editor MUST render each one; findings with `severity=block`
disable `Save`.

| Finding code                        | Severity | Trigger                                                                     |
|-------------------------------------|----------|-----------------------------------------------------------------------------|
| `LOCKOUT-ROLE-MANAGE-EMPTY`         | block    | No role would hold `Role.Manage` after save.                                |
| `LOCKOUT-SYSTEM-CONFIGURE-EMPTY`    | block    | No role would hold `System.Configure` after save.                           |
| `LOCKOUT-CURRENT-USER-ROLE-MANAGE`  | block    | The saving user would lose `Role.Manage`. Prevents self-lockout.            |
| `WARN-DEPUTY-DENY-REMOVED`          | warn     | A seeded deputy deny row was removed; requires explicit confirmation ack.   |
| `WARN-AUDITOR-WRITE-GRANTED`        | warn     | An auditor-role user would gain a write capability.                         |
| `INFO-NO-EFFECTIVE-CHANGE`          | info     | Diff resolves to no effective permission change.                            |

Warn-severity findings require the operator to tick a per-finding
acknowledgement checkbox before Save is enabled. Info findings do
not block Save.

---

## Effective Permissions Preview Contract

Given a `userId`, `GET /api/admin/policies/effective` returns:

```json
{
  "userId": "...",
  "resolvedAt": "2026-07-20T12:00:00Z",
  "policyVersion": 42,
  "decisions": [
    {
      "capability": "Backup.Import",
      "effect":     "deny",
      "reason":     "deny-override",
      "citedRule":  "p, deputy, /Api/V1/Admin/Backup/Import, .*, deny",
      "matchedRoles": ["deputy", "admin"]
    }
  ]
}
```

Rules:

1. The FE MUST render `effect`, `reason`, and `citedRule`
   verbatim; no client-side interpretation of role hierarchy.
2. The preview is READ-ONLY and does not participate in dirty
   state; changing the picked user does not enter `Dirty`.
3. When the current draft (`Dirty` or `DryRunReady`) differs from
   the persisted matrix, the preview MUST render a banner
   `roles.preview.usesPersistedPolicy` warning that the preview
   reflects saved state, not the pending draft.

---

## Copy Dictionary

All strings sourced from `src/i18n/backup-roles.json`. Minimum
22 keys:

`roles.title`, `roles.tab.catalogue`, `roles.tab.matrix`,
`roles.tab.preview`, `roles.matrix.legend.allow`,
`roles.matrix.legend.deny`, `roles.matrix.legend.unset`,
`roles.matrix.denyOverride.explanation`,
`roles.matrix.discard.cta`, `roles.matrix.preview.cta`,
`roles.matrix.save.cta`, `roles.matrix.save.disabled.needsDryRun`,
`roles.matrix.save.disabled.blockingFinding`,
`roles.finding.lockout.roleManageEmpty`,
`roles.finding.lockout.systemConfigureEmpty`,
`roles.finding.lockout.currentUserRoleManage`,
`roles.finding.warn.deputyDenyRemoved`,
`roles.finding.warn.auditorWriteGranted`,
`roles.finding.info.noEffectiveChange`,
`roles.preview.pickUser`, `roles.preview.usesPersistedPolicy`,
`roles.conflict.reload`.

Inline literals in TSX are a lint failure.

---

## Surface State Delegation

- `<StateForbidden>` on `Role.Read` denial, or per-control on
  `Role.Manage` / `System.Configure` denial.
- `<StateOffline>` when policy GET fails three times in a row.
- `<StatePending>` for `Saving` and while dry-run is in flight.
- `<StateCard variant="destructive">` for `Failed` and
  `ConflictReload` (with copy `roles.conflict.reload`).

---

## Observability

Every transition MUST emit exactly one `lara-diag` entry with:

- `RequestId` from the triggering response,
- `policyVersion` on load/save/dry-run,
- `action` one of `load|dryRun|save|previewUser|conflictReload|discard`,
- `findingCounts` `{block, warn, info}` on dry-run,
- `ErrorId` + `ErrorCode` on failures.

Do NOT log the diff body or user identifiers beyond the previewed
`userId`; `redactor.pii` applies per
[`spec/03-error-manage/`](../03-error-manage/00-overview.md).

Every successful Save MUST also produce an audit row via the
server (`Audit.Read` panel refresh triggered client-side) with
`before`/`after` policy versions cited.

---

## Invariants

| ID              | Rule |
|-----------------|------|
| INV-BR-FE-RB-1  | `Save` is disabled unless the current diff has passed dry-run with zero `block` findings. |
| INV-BR-FE-RB-2  | `Save` MUST send `If-Match: <policyVersion>`; 409 forces `ConflictReload` with no auto-merge. |
| INV-BR-FE-RB-3  | Seeded deputy deny rows render with red border, lock icon, and deny-override tooltip; removing one produces `WARN-DEPUTY-DENY-REMOVED`. |
| INV-BR-FE-RB-4  | The FE MUST NOT interpret role hierarchy; `effect`, `reason`, and `citedRule` from the effective-preview response are rendered verbatim. |
| INV-BR-FE-RB-5  | Missing `Role.Manage` renders write controls disabled with `<StateForbidden>` tooltip, never hidden. |
| INV-BR-FE-RB-6  | Every mutating request mints a per-submission UUIDv7 `Idempotency-Key`, re-used verbatim on retry of the same attempt. |
| INV-BR-FE-RB-7  | All strings sourced from `src/i18n/backup-roles.json`; inline literals in TSX are a lint failure. |
| INV-BR-FE-RB-8  | Effective-preview banner `roles.preview.usesPersistedPolicy` renders whenever `Dirty` or `DryRunReady` state is active. |

---

## Cross-References

- [`02-casbin-integration.md`](./02-casbin-integration.md): PDP, model, `has_role` bridge.
- [`03-permission-matrix.md`](./03-permission-matrix.md): 14-capability closed set and seeded matrix.
- [`01-actors-and-roles.md`](./01-actors-and-roles.md): six seeded roles including deputy.
- [`16-idempotency-and-locks.md`](./16-idempotency-and-locks.md): key format and lock registry.
- [`17-fe-routes.md`](./17-fe-routes.md): `RA-BR-6` anchor and guard contract.
