# Frontend Routes

**Version:** 1.0.0
**Updated:** 2026-07-20
**AI Confidence:** Draft
**Ambiguity:** Low

---

## Keywords

`fe-routes` · `capability-guard` · `casbin-pep` · `authenticated-layout` · `admin-backup` · `admin-snapshots` · `admin-roles` · `route-anchor`

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

Endpoints 11..16 pin the server surface, job model, and lock registry.
No file yet declares the FE routes that call them, the Casbin capability
each route requires, or which authenticated layout hosts them. This file
is the single source that later FE flow specs (18..21) cite as their
route anchors, and it is the contract the FE Policy Enforcement Point
(PEP) uses to gate rendering.

## Non-goals

- Component-level state charts (owned by 18..21).
- Copy dictionary (owned by 18..21).
- Server route definitions (owned by 11..16).

---

## Route Anchors

All routes live under the authenticated layout `src/routes/_authenticated/`
using flat dot-separated file naming. Every route in this file is
Super-Admin gated at minimum. The FE PEP MUST call `useCapability(cap)`
and render `StateForbidden` (see `spec/24-*`) when the check returns
false. The PEP MUST NOT rely on server 403 alone; the guard exists so
the route shell never mounts the endpoint-consuming query.

### RA-BR-1: Backup landing

- **Path:** `/admin/backup`
- **File:** `src/routes/_authenticated/admin.backup.tsx`
- **Capability:** `Backup.View`
- **Endpoints consumed:** `GET /api/admin/backup/jobs` (list via `15-jobs-and-progress.md`).
- **Purpose:** Entry point listing recent Export/Import/Restore jobs with links to RA-BR-2 and RA-BR-3.

### RA-BR-2: Backup export

- **Path:** `/admin/backup/export`
- **File:** `src/routes/_authenticated/admin.backup.export.tsx`
- **Capability:** `Backup.Export`
- **Endpoints consumed:** `POST /api/admin/backup/exports` (`11-endpoint-export.md`), `GET /api/admin/backup/jobs/{jobId}` (`15-jobs-and-progress.md`), SSE `/api/admin/backup/jobs/{jobId}/events`.
- **Purpose:** Host of the export state chart authored in step 19.

### RA-BR-3: Backup import

- **Path:** `/admin/backup/import`
- **File:** `src/routes/_authenticated/admin.backup.import.tsx`
- **Capability:** `Backup.Import`
- **Endpoints consumed:** `POST /api/admin/backup/imports` (`12-endpoint-import.md`), `GET /api/admin/backup/jobs/{jobId}`, SSE `/api/admin/backup/jobs/{jobId}/events`.
- **Purpose:** Host of the import upload -> preflight -> apply chart authored in step 20.

### RA-BR-4: Snapshots list

- **Path:** `/admin/snapshots`
- **File:** `src/routes/_authenticated/admin.snapshots.tsx`
- **Capability:** `Snapshot.View`
- **Endpoints consumed:** `GET /api/admin/snapshots` (`13-endpoint-snapshot.md`).
- **Purpose:** Host of the snapshot list, create, pin, and delete flows authored in step 21.

### RA-BR-5: Snapshot detail

- **Path:** `/admin/snapshots/$snapshotId`
- **File:** `src/routes/_authenticated/admin.snapshots.$snapshotId.tsx`
- **Capability:** `Snapshot.View`
- **Endpoints consumed:** `GET /api/admin/snapshots/{id}`, `POST /api/admin/backup/restores` (`14-endpoint-restore.md`) with `kind=snapshot`.
- **Purpose:** Snapshot detail with restore CTA (secondary capability `Restore.Apply`).

### RA-BR-6: Roles administration

- **Path:** `/admin/roles`
- **File:** `src/routes/_authenticated/admin.roles.tsx`
- **Capability:** `Role.Manage`
- **Endpoints consumed:** Casbin policy CRUD (owned by `02-casbin-integration.md`).
- **Purpose:** Host of the roles and effective-permissions UI authored in step 22.

---

## Capability Guard Contract

1. Every route file above MUST call the shared `useCapability(cap)` hook
   from `src/lib/capabilities.ts` inside the route component before any
   `useSuspenseQuery` call bound to a BR endpoint.
2. When `useCapability` returns `false`, the component MUST render
   `<StateForbidden capability={cap} />` and MUST NOT dispatch the
   endpoint query. This prevents server 403 spam and preserves
   `RequestId` budget for real traffic.
3. `useCapability` MUST be backed by the same Casbin policy set the
   backend PEP evaluates (`02-casbin-integration.md`), fetched once per
   session via `GET /api/me/capabilities` and cached in the router
   context. Stale reads are acceptable up to 60s; mutations that change
   the caller's own capabilities MUST invalidate the cache immediately.
4. The `_authenticated` layout enforces authentication only. Capability
   enforcement is per-route, never in the layout, because different
   BR routes require different capabilities.

## Navigation

The admin sidebar (owned by `spec/24-*`) MUST hide entries whose
capability check is false. Hidden entries MUST NOT be reachable via
`useNavigate`; components that call `navigate({ to: '/admin/backup' })`
without the capability MUST short-circuit to `/admin` and log a
`lara-diag` warning with `RequestId`, per `spec/03-error-manage/`.

## Deep-link Handling

Direct navigation to a gated route by an unauthorized user MUST:

1. Match the route (TanStack matches by path, not by capability).
2. Render `StateForbidden` inside the `_authenticated` shell.
3. Log one `lara-diag` entry at level `warn` with `RequestId`,
   `capability`, and the caller's `UserId`.
4. NOT redirect. Redirect obscures the failure and breaks
   `INV-BR-A` audit expectations.

## Invariants

- **INV-BR-FE-1:** Every BR FE route above declares exactly one primary
  capability guard, evaluated before any BR endpoint query mounts.
- **INV-BR-FE-2:** The FE capability set is derived from the same
  Casbin policy the backend PEP uses; drift is a CI failure.
- **INV-BR-FE-3:** A failed capability check renders `StateForbidden`
  and logs one `lara-diag` warn entry with `RequestId`; it never
  redirects and never issues the gated endpoint request.
- **INV-BR-FE-4:** Sidebar visibility mirrors route capability;
  hidden entries are also unreachable via programmatic navigation.
- **INV-BR-FE-5:** Route file paths in this document are the sole
  anchors that FE flow specs 18..21 may cite; adding or renaming a
  route requires a version bump here and in the citing file.

---

## References

- `spec/26-backup-restore/02-casbin-integration.md`
- `spec/26-backup-restore/03-permission-matrix.md`
- `spec/26-backup-restore/11-endpoint-export.md`
- `spec/26-backup-restore/12-endpoint-import.md`
- `spec/26-backup-restore/13-endpoint-snapshot.md`
- `spec/26-backup-restore/14-endpoint-restore.md`
- `spec/26-backup-restore/15-jobs-and-progress.md`
- `spec/03-error-manage/`
- `spec/24-*` (route shell state components)
