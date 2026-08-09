# License Lifecycle Rules and State Transitions

**Version:** 1.0.0
**Status:** Normative for LaraLicensingV1.
**Related:** [`05-license-categories.md`](./05-license-categories.md), [`06-license-variations.md`](./06-license-variations.md), [`07-serial-generation.md`](./07-serial-generation.md), [`10-endpoints.md`](./10-endpoints.md), [`11-api-contracts/02-license-contracts.md`](./11-api-contracts/02-license-contracts.md), [`13-audit-logging.md`](./13-audit-logging.md), [`19-user-management.md`](./19-user-management.md), [`../23-app-db/01-schema.md`](../23-app-db/01-schema.md).

This file fixes the closed set of lifecycle states for a `License`, a `Serial`, and a `MachineBinding`, the allowed transitions between them, the actor who may trigger each transition, and the effect on downstream verification.

---

## 1. License States

The DB stores primitives (`IsActive`, `ExpiresAt`, `DeletedAt`). This file names the derived state used by contracts and audit rows. States are computed, not stored, to keep migrations trivial.

| State | Predicate | Verify outcome |
|-------|-----------|----------------|
| `Draft` | Row exists, `IsActive = 0`, `IssuedAt IS NULL`. | `LicenseNotIssued` (403). |
| `Active` | `IsActive = 1` AND (`ExpiresAt IS NULL` OR `ExpiresAt > Now`) AND `DeletedAt IS NULL`. | Admits verification subject to serial and binding state. |
| `Expired` | `IsActive = 1` AND `ExpiresAt <= Now`. | `LicenseExpired` (403). |
| `Suspended` | `IsActive = 0` AND `IssuedAt IS NOT NULL` AND `DeletedAt IS NULL`. | `LicenseSuspended` (403). |
| `Revoked` | `DeletedAt IS NOT NULL`. | `LicenseRevoked` (403). |

`Revoked` is terminal. `Suspended` is reversible.

### 1.1 Transitions

```text
Draft --Issue--> Active
Active --Suspend--> Suspended
Suspended --Reinstate--> Active
Active --Expire (time)--> Expired
Expired --Renew--> Active
Active|Suspended|Expired --Revoke--> Revoked (terminal)
```

Any other transition is rejected with `LicenseTransitionInvalid` (409).

### 1.2 Transition rules

Every "Actor" column below is an authoritative `has_role` check per [`19-user-management.md`](./19-user-management.md). The server MUST call `has_role(auth.uid(), Role)` before evaluating preconditions; a refusal returns `AuthzRoleDenied` (403) with an audit `RoleCheckDenied` row per [`13-audit-logging.md`](./13-audit-logging.md), NOT a lifecycle error. Reseller row-scope (`Licenses.ResellerId = AuthActor.ResellerId`) is a separate check that returns `AuthzRowScopeDenied` (403). Neither authz code is a `LicenseTransition*` code.

| Transition | Required role (`has_role`) | Row scope | Preconditions | Effect | Audit Action | Rejection code |
|------------|----------------------------|-----------|---------------|--------|--------------|----------------|
| `Issue` | `Admin` OR `Reseller` | Reseller: own | License row is `Draft`. Category and variation resolved. | `IsActive := 1`, `IssuedAt := Now`, `ExpiresAt := computed` (see 1.3). | `LicenseIssued` | `LicenseTransitionInvalid` |
| `Suspend` | `Admin` OR `Reseller` | Reseller: own | State = `Active`. `Reason` required, min 4 chars. | `IsActive := 0`. Existing `Serials` keep `IsRevoked = 0`. | `LicenseSuspended` | `LicenseTransitionInvalid` |
| `Reinstate` | `Admin` OR `Reseller` | Reseller: own | State = `Suspended`. | `IsActive := 1`. Does not extend `ExpiresAt`. | `LicenseReinstated` | `LicenseTransitionInvalid` |
| `Expire` | `System` (scheduled job) | n/a | `ExpiresAt <= Now`. | No column change; state derived. | `LicenseExpired` emitted once per license per day when first observed. | n/a (observation) |
| `Renew` | `Admin` OR `Reseller` | Reseller: own | State = `Active` or `Expired`. Category permits `ExpiresAt`. | `ExpiresAt := max(Now, ExpiresAt) + Delta`, `IsActive := 1`. | `LicenseRenewed` | `LicenseTransitionInvalid` |
| `Revoke` | `Admin` only | Platform-wide | State != `Revoked`. `Reason` required. | `DeletedAt := Now`. Cascade in section 3. | `LicenseRevoked` | `LicenseTransitionInvalid` |

Authz check order (normative, short-circuits on first failure): (1) `has_role` for the required role, (2) row-scope for `Reseller`, (3) preconditions, (4) effect. Steps 1-2 are pure authz; steps 3-4 are lifecycle. Contract tests MUST assert this order so a `Reseller` calling `Revoke` sees `AuthzRoleDenied` (missing `Admin`), never `LicenseTransitionInvalid`.

### 1.3 `ExpiresAt` computation on `Issue`

Driven by `LicenseCategories.DurationSeconds` (see `spec/23-app-db/01-schema.md` `LicenseCategories`). Closed category set: `Daily`, `Weekly`, `Monthly`, `Yearly`, `Lifetime`, `Dev`, `Key`.

- `Lifetime`, `Key`: `DurationSeconds IS NULL` → `ExpiresAt := NULL`.
- `Daily`, `Weekly`, `Monthly`, `Yearly`, `Dev`: `ExpiresAt := IssuedAt + Category.DurationSeconds seconds`.
- `Renew` on a non-null-duration category extends by `Category.DurationSeconds` from `max(Now, ExpiresAt)`; `Renew` on `Lifetime`/`Key` returns `Conflict` per taxonomy.

Follow-up: `LicenseCategories` currently has no duration column. `spec/23-app-db/01-schema.md` needs a schema addendum before this rule is implementable. Not fixed in this file to keep the change minimum.

---

## 2. Serial States

| State | Predicate | Verify outcome |
|-------|-----------|----------------|
| `Unbound` | Row exists, `IsRevoked = 0`, no matching `MachineBindings` row. | Admits on `POST /verify/final`, triggers binding creation. |
| `Bound` | `IsRevoked = 0`, at least one non-revoked `MachineBindings` row. | Admits verification per binding rules in section 4. |
| `Revoked` | `IsRevoked = 1`. | `SerialRevoked` (403). Terminal. |

### 2.1 Transitions

```text
(create) --> Unbound
Unbound --Bind--> Bound
Bound --UnbindLast--> Unbound
Unbound|Bound --Revoke--> Revoked (terminal)
```

### 2.2 Transition rules

| Transition | Actor | Preconditions | Effect | Audit Action |
|------------|-------|---------------|--------|--------------|
| `Create` | `Admin`, `Reseller` (own license) | Parent `License` state = `Active`. Prefix rules per [`07-serial-generation.md`](./07-serial-generation.md). | Insert `Serials` row. | `SerialIssued` |
| `Bind` | System (verify flow) | `POST /verify/final` admitted. License state = `Active`. Binding count check (section 4). | Insert `MachineBindings` row. | `BindingCreated` |
| `UnbindLast` | `Admin`, `Reseller` (own), `EndUser` (own serial) | Serial has bindings. | Revoke all `MachineBindings` for the serial. | `BindingRevoked` per row |
| `Revoke` | `Admin` only | State != `Revoked`. | `IsRevoked := 1`. Cascade in section 3. | `SerialRevoked` |

License state gates every serial transition. Attempting `Bind` against a non-`Active` license returns the license-level error, not a serial-level error, so the caller sees the true blocker.

---

## 3. Cascade Rules

When a parent transitions, children follow deterministically. Cascades are executed in one DB transaction and audited as separate rows sharing one `RequestId`.

| Parent transition | Child effect | Audit rows |
|-------------------|--------------|------------|
| `License.Revoke` | All `Serials` for that license: `IsRevoked := 1`. All `MachineBindings` for those serials: revoked. | 1 `LicenseRevoked` + N `SerialRevoked` + M `BindingRevoked`. |
| `License.Suspend` | No child change. Verify blocks at the license layer. | 1 `LicenseSuspended`. |
| `License.Expire` (observed) | No child change. | 1 `LicenseExpired`. |
| `License.Reinstate` | No child change. | 1 `LicenseReinstated`. |
| `License.Renew` | No child change. | 1 `LicenseRenewed`. |
| `Serial.Revoke` | All `MachineBindings` for that serial: revoked. | 1 `SerialRevoked` + M `BindingRevoked`. |

A cascade never crosses licenses. Revoking a serial does not affect sibling serials of the same license.

---

## 4. Binding Enforcement (interaction with `LicenseVariations`)

`LicenseVariations.MachineCount` and `LicenseVariations.UserCount` gate `Bind`.

- `MachineCount IS NULL`: unlimited machines per serial.
- `MachineCount = N`: at most N non-revoked `MachineBindings` per serial. Exceeding returns `BindingLimitExceeded` (409).
- `UserCount IS NULL`: unlimited users per license.
- `UserCount = N`: at most N distinct `EndUserId` across active bindings for the license. Exceeding returns `UserLimitExceeded` (409).
- `IsSingleUse = 1`: `Bind` allowed exactly once per serial; further attempts return `SerialAlreadyBound` (409).

Every rejection here is a lifecycle-guarded outcome, not a rate-limit outcome. Rate limiting (14-rate-limiting.md) runs before this check.

---

## 5. Interaction with Verification

The verify pipeline in [`09-verify-key.md`](./09-verify-key.md) executes checks in this fixed order. First failure short-circuits:

1. Rate limit and abuse rules ([`14-rate-limiting.md`](./14-rate-limiting.md)).
2. Serial resolution: `SerialNotFound` if none.
3. License state: `LicenseRevoked` > `LicenseExpired` > `LicenseSuspended` > `LicenseNotIssued`.
4. Serial state: `SerialRevoked`.
5. Binding capacity: `BindingLimitExceeded`, `UserLimitExceeded`, `SerialAlreadyBound`.
6. Hash and verify-key checks per existing spec.

Order is normative. Any implementation deviating is a bug, not a variant.

---

## 6. Idempotency

- `Issue`, `Renew`, `Suspend`, `Reinstate`, `Revoke` accept `Idempotency-Key` per the API contract envelope. Replays return the original response.
- `Bind` is idempotent on `(SerialId, MachineFingerprintHash)`; a repeat request within a live binding returns the existing binding, not a new row, and does not emit a second `BindingCreated`.

---

## 7. Acceptance Criteria

- AC-LL-001: Every state in sections 1 and 2 has exactly one predicate; predicates are mutually exclusive and total.
- AC-LL-002: Every transition in sections 1.2 and 2.2 has a matching `Action` in [`13-audit-logging.md`](./13-audit-logging.md) and a matching `ErrorCode` for rejection in [`12-error-taxonomy.md`](./12-error-taxonomy.md).
- AC-LL-003: Cascade rules in section 3 execute in a single DB transaction and emit one audit row per affected entity, all sharing the request's `RequestId`.
- AC-LL-004: The verify check order in section 5 is preserved by the implementation; contract tests assert the error precedence.
- AC-LL-005: `Reseller` actors can only transition licenses where `Licenses.ResellerId = AuthActor.ResellerId`; violations return `AuthzRowScopeDenied` (403), not `LicenseTransitionInvalid`.
- AC-LL-006: `Revoked` states (license and serial) are terminal; any transition attempt returns `LicenseTransitionInvalid` or `SerialTransitionInvalid` (409).
- AC-LL-007: Every transition endpoint calls `has_role(auth.uid(), Role)` before preconditions; refusal returns `AuthzRoleDenied` (403) plus a `RoleCheckDenied` audit row. Contract tests assert the authz-before-precondition order from §1.2.
