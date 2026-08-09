# Casbin Integration

**Version:** 1.0.0
**Updated:** 2026-07-20
**AI Confidence:** Draft
**Ambiguity:** Low

---

## Keywords

`casbin` · `rbac` · `abac` · `pep` · `pdp` · `policy-adapter` · `has_role` · `enforcer`

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

Normative spec for how Casbin acts as the sole Policy Decision Point (PDP)
for the Backup/Restore/Snapshot module and, by extension, for every
`/Api/V1/Admin/*` capability declared in
[`00-overview.md`](./00-overview.md) and every role transition declared
in [`01-actors-and-roles.md`](./01-actors-and-roles.md). Readers should
be able to (a) reproduce the `model.conf` byte-for-byte, (b) know
exactly which adapter table holds policy rows, (c) place the Policy
Enforcement Point (PEP) at the correct middleware seam, and (d) migrate
the existing `public.has_role(uuid, app_role)` SECURITY DEFINER function
into a Casbin-authoritative flow without breaking the DB-side RLS
policies that already depend on it.

---

## Normative Terms

`MUST`, `MUST NOT`, `SHOULD`, `MAY` follow RFC 2119.

- **PEP** (Policy Enforcement Point): the middleware/gate that calls the enforcer.
- **PDP** (Policy Decision Point): the Casbin enforcer instance.
- **PAP** (Policy Administration Point): the Super Admin UI defined in
  `<spec-placeholder file="21-fe-roles-and-casbin-ui.md" />`.
- **PIP** (Policy Information Point): the DB adapter table `casbin_rules`
  plus the `user_roles` table already established in
  [`01-actors-and-roles.md`](./01-actors-and-roles.md).

---

## Model

The enforcer `MUST` load exactly this `model.conf`. Whitespace and
section order are normative; the consistency report at step 30 diffs
this block against the shipped file.

```ini
[request_definition]
r = sub, obj, act

[policy_definition]
p = sub, obj, act, eft

[role_definition]
g = _, _

[policy_effect]
e = some(where (p.eft == allow)) && !some(where (p.eft == deny))

[matchers]
m = g(r.sub, p.sub) && keyMatch2(r.obj, p.obj) && regexMatch(r.act, p.act)
```

Rationale for each choice:

- `sub, obj, act` triple mirrors the capability names introduced in
  `00-overview.md` (`Backup.Export`, `Snapshot.Restore`, ...); no `dom`
  (domain) yet because this module is single-tenant per
  `00-overview.md` §Non-Goals.
- `p = sub, obj, act, eft` allows explicit `deny` rows so
  `Backup.Import` and `Snapshot.Restore` can be denied for `deputy`
  even when the parent role grants them (see
  [`01-actors-and-roles.md`](./01-actors-and-roles.md) §Deputy).
- `g` is a two-argument grouping (user -> role) sufficient for the
  five-role catalogue; a three-argument `g` would be re-introduced only
  if multi-tenancy lands (out of scope, tracked in
  `<spec-placeholder file="25-migration-and-rollout.md" />`).
- `keyMatch2` on `obj` enables wildcard capability names like
  `Backup.*` in policy rows without materialising every child action.
- `regexMatch` on `act` is preserved for future HTTP-verb bindings but
  today policies `MUST` use literal action strings from
  `<spec-placeholder file="03-permission-matrix.md" />`.
- The effect line implements deny-overrides which is required by
  `INV-BR-ACT-4` (no delegated Import/Restore) from
  [`01-actors-and-roles.md`](./01-actors-and-roles.md).

The enforcer `MUST NOT` be configured with role-based `matchers` that
short-circuit `g()`; the grouping call must run for every request so
that Deputy inheritance is evaluated.

---

## Policy Shape

Every policy row `MUST` conform to one of these two shapes; nothing else
is allowed to enter the adapter table:

```csv
# Policy rows
p, super_admin, /Api/V1/Admin/*,           .*,        allow
p, admin,      /Api/V1/Admin/Backup/Export, POST,     allow
p, admin,      /Api/V1/Admin/Snapshot,      (GET|POST), allow
p, deputy,     /Api/V1/Admin/Backup/Import, .*,       deny
p, deputy,     /Api/V1/Admin/Snapshot/*/Restore, .*,  deny

# Grouping rows (user_id -> role name)
g, 3d9f0c1a-...-uuid, super_admin
g, 8e42b1c7-...-uuid, admin
```

Rules:

1. `sub` in a `p` row `MUST` be a role name from the closed set
   `{super_admin, admin, operator, auditor, user, deputy}` declared in
   [`01-actors-and-roles.md`](./01-actors-and-roles.md). A `sub` that
   is a raw `user_id` is rejected at write time.
2. `obj` `MUST` start with `/Api/V1/` (server-authoritative path) or
   with the literal prefix `Capability:` (for FE-only permission checks
   like `Capability:Backup.Export`); no other prefixes are accepted.
3. `act` `MUST` be a regex over HTTP methods (`GET`, `POST`, `PUT`,
   `DELETE`, `PATCH`) or the literal `.*`. FE capability policies
   `SHOULD` use `.*`.
4. `eft` is `allow` or `deny`; no other value is accepted.
5. `g` rows bind exactly one `user_id` (UUID v4) to exactly one role
   name; a user with multiple roles has multiple `g` rows.
6. The single `g, <first_user_id>, super_admin` row `MUST` be inserted
   inside the same DB transaction that flips `system_bootstrap` per
   [`01-actors-and-roles.md`](./01-actors-and-roles.md) §Registration.

---

## Adapter

The project `MUST` use the Laravel/PostgreSQL DB adapter
(`lauthz-org/laravel-authz` on the PHP side, Casbin.js in-memory
adapter on the FE for capability hints only; the FE adapter is never
authoritative).

Adapter table (normative DDL sketch; final migration lives in
`<spec-placeholder file="25-migration-and-rollout.md" />`):

```sql
CREATE TABLE public.casbin_rules (
  id     BIGSERIAL PRIMARY KEY,
  ptype  VARCHAR(8)   NOT NULL,          -- 'p' or 'g'
  v0     VARCHAR(256) NOT NULL,          -- sub / user_id
  v1     VARCHAR(256) NOT NULL,          -- obj / role
  v2     VARCHAR(256),                    -- act
  v3     VARCHAR(16),                     -- eft
  v4     VARCHAR(256),                    -- reserved
  v5     VARCHAR(256),                    -- reserved
  updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE UNIQUE INDEX casbin_rules_unique
  ON public.casbin_rules (ptype, v0, v1, COALESCE(v2,''), COALESCE(v3,''));

GRANT SELECT ON public.casbin_rules TO authenticated;
GRANT ALL    ON public.casbin_rules TO service_role;

ALTER TABLE public.casbin_rules ENABLE ROW LEVEL SECURITY;

CREATE POLICY "Casbin rules readable by authenticated"
  ON public.casbin_rules FOR SELECT TO authenticated USING (true);
-- Writes gated at the API layer under super_admin only; no INSERT/UPDATE/DELETE
-- policies for `authenticated` on purpose.
```

Notes:

- The unique index enforces at-rest deduplication so the PAP is idempotent.
- `anon` is not granted access; policy visibility requires a session.
- Only `service_role` writes; the Super Admin API endpoint runs under
  `service_role` after the PEP has verified `super_admin`.

---

## PEP Placement

The enforcer `MUST` be invoked exactly once per API request, in this
order (all four steps are normative):

1. **`RequestIdMiddleware`** binds `X-Request-Id` (already in place per
   `spec/03-error-manage/`).
2. **`SanctumAuthMiddleware`** resolves `auth()->user()->id` from the
   bearer token.
3. **`CasbinPepMiddleware`** (new) `MUST`:
   - resolve `sub = auth()->id()`,
   - resolve `obj = $request->path()` prefixed with `/`,
   - resolve `act = strtoupper($request->method())`,
   - call `$enforcer->enforce($sub, $obj, $act)`,
   - on `false`, throw `LaraException::forbidden('Rbac.Denied', [...])`
     which renders the canonical envelope defined in
     `spec/03-error-manage/`,
   - on `true`, continue.
4. **Route handler** executes.

The PEP `MUST NOT` be a controller trait, a policy class, or a route
attribute; those seams execute after model binding and would leak IDoR
signals through 404-vs-403 timing.

Frontend PEP: the router guard defined in
`<spec-placeholder file="17-fe-routes.md" />` `MUST` call
`enforcerFe.enforce(userId, 'Capability:<name>', '.*')` for menu
visibility only; server-side re-check is authoritative.

---

## Migration from `has_role()`

The existing `public.has_role(_user_id uuid, _role app_role)` SECURITY
DEFINER function (memory
`mem://features/error-contract.md`, project rules
`user-roles`) `MUST` remain in place because DB-side RLS policies on
`user_roles`, `licenses`, and future BR tables reference it. Casbin
does not replace it; Casbin sits above it.

Migration invariants:

- `MIG-CAS-1`: every `INSERT INTO user_roles(user_id, role)` `MUST`
  emit a matching `g, <user_id>, <role>` row inside the same
  transaction. A trigger `trg_user_roles_to_casbin` is the reference
  implementation; the PAP HTTP endpoint is the alternative.
- `MIG-CAS-2`: every `DELETE FROM user_roles` `MUST` delete the
  matching `g` row in the same transaction.
- `MIG-CAS-3`: the `99-consistency-report.md` (step 30) diff between
  `SELECT user_id, role FROM user_roles` and
  `SELECT v0, v1 FROM casbin_rules WHERE ptype='g'` `MUST` be empty.
- `MIG-CAS-4`: `has_role()` continues to be the sole source of truth
  for RLS `USING` clauses. Casbin is the sole source of truth for HTTP
  authorisation. Neither may call the other at runtime.
- `MIG-CAS-5`: on drift detection (MIG-CAS-3 fails), the enforcer
  emits `RoleSyncPending` (defined in
  [`01-actors-and-roles.md`](./01-actors-and-roles.md) §Audit) and the
  admin UI surfaces a red banner via the Global Error Modal contract
  (`spec/03-error-manage/`).

---

## Observability

Every enforcer call `MUST` log to `lara-diag` at DEBUG level with:

```json
{
  "channel": "lara-diag",
  "event": "casbin.enforce",
  "RequestId": "<uuid>",
  "sub": "<user_id>",
  "obj": "<path>",
  "act": "<verb>",
  "allowed": true,
  "matched_policy": "p, admin, /Api/V1/Admin/Backup/Export, POST, allow"
}
```

Denied decisions `MUST` additionally emit at WARNING with the same
shape and `allowed: false`, and the `Rbac.Denied` envelope `MUST`
include the same `RequestId` per `spec/03-error-manage/`.

---

## Invariants

- `INV-BR-CAS-1`: exactly one enforcer instance per request; no
  per-controller re-instantiation.
- `INV-BR-CAS-2`: the model is loaded once at boot and cached; policy
  rows are reloaded on write via the adapter's watcher.
- `INV-BR-CAS-3`: no policy row references a raw `user_id` as `sub`;
  user bindings live only in `g` rows.
- `INV-BR-CAS-4`: deny-overrides is preserved by the `policy_effect`
  line and `MUST NOT` be overridden per-route.
- `INV-BR-CAS-5`: `casbin_rules` and `user_roles` stay in sync per
  MIG-CAS-1..3; drift is a P1 incident.
- `INV-BR-CAS-6`: FE enforcer output is advisory; every mutation
  endpoint re-checks server-side.

---

## Cross-References

- Parent: [`00-overview.md`](./00-overview.md) (glossary, endpoint inventory).
- Sibling: [`01-actors-and-roles.md`](./01-actors-and-roles.md) (role catalogue, bootstrap, deputy).
- Downstream: `<spec-placeholder file="03-permission-matrix.md" />`
  (authoritative role x action rows that seed `casbin_rules`).
- Downstream: `<spec-placeholder file="04-invariants.md" />` (promotes
  `INV-BR-CAS-1..6` alongside `INV-BR-A..F`).
- Downstream: `<spec-placeholder file="17-fe-routes.md" />` and
  `<spec-placeholder file="21-fe-roles-and-casbin-ui.md" />` (FE PEP
  and PAP surfaces).
- Downstream: `<spec-placeholder file="22-observability.md" />`
  (formalises the `casbin.enforce` log event schema).
- Downstream: `<spec-placeholder file="25-migration-and-rollout.md" />`
  (ships the `casbin_rules` migration and the `trg_user_roles_to_casbin`
  trigger).
- Error contract: `spec/03-error-manage/` (`Rbac.Denied` envelope,
  `RequestId`/`ErrorId` correlation).
