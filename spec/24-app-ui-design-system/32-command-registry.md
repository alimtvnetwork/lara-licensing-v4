# Command Registry

**Version:** 1.0.0
**Status:** Normative for LaraLicensingV1 UI. Fills the deferred registry referenced by [`31-search-and-command-palette.md`](./31-search-and-command-palette.md) §5.
**Owner:** Single source of truth for every command invocable from the Command Palette OR wired to a Button / Menu / Sheet trigger anywhere in the app. If a command does not appear in §6 or §7, it MUST NOT ship.
**Related:** [`13-navigation-ia.md`](./13-navigation-ia.md), [`17-component-button.md`](./17-component-button.md), [`21-component-dialog.md`](./21-component-dialog.md), [`22-component-menu-popover.md`](./22-component-menu-popover.md), [`26-iconography-and-assets.md`](./26-iconography-and-assets.md), [`27-content-voice.md`](./27-content-voice.md), [`28-a11y-conformance.md`](./28-a11y-conformance.md), [`31-search-and-command-palette.md`](./31-search-and-command-palette.md), [`../21-app/40-permissions.md`](../21-app/40-permissions.md), [`../21-app/26-route-dto-index.md`](../21-app/26-route-dto-index.md), [`../21-app/22-log-line-contract.md`](../21-app/22-log-line-contract.md), [`../21-app/29-idempotency-lifecycle.md`](../21-app/29-idempotency-lifecycle.md).

---

## 1. Purpose and non-purpose

The registry is the closed set of user-invocable actions in v1. Two guarantees:

1. **Permission parity.** Every `PermissionKey` in this file is a strict subset of the canonical keys declared in [`../21-app/40-permissions.md`](../21-app/40-permissions.md). Drift is caught by future `linter-scripts/check-command-permission-parity.py`.
2. **Route parity.** Every `OperationId` in this file matches an entry in [`../21-app/26-route-dto-index.md`](../21-app/26-route-dto-index.md).

The registry is NOT:
- A router. Navigation targets live in [`13-navigation-ia.md`](./13-navigation-ia.md).
- A permission catalog. Permissions live in [`../21-app/40-permissions.md`](../21-app/40-permissions.md).
- A UI copy catalog. Labels here are the Palette entry points; per-surface Buttons MAY use tighter labels but MUST use the SAME verb from [`27-content-voice.md`](./27-content-voice.md) §3.

## 2. Command shape

Every command is a row with the following fields. All fields are REQUIRED. Adding a command REQUIRES a same-commit row.

| Field | Type | Rules |
|-------|------|-------|
| `CommandId` | string, `Domain.Action` PascalCase | Globally unique. MUST match the runtime constant. Renames are BANNED (add a new command and deprecate the old for one minor version). |
| `Label` | string | Verb-first per [`27-content-voice.md`](./27-content-voice.md) §3. Ellipsis (`...`) suffix when `Kind` is `Dialog` or `Sheet`. Sentence case, no title case, no uppercase. |
| `Description` | string, one sentence | Palette footer aid, screen-reader-only when Palette row has no visible description. |
| `Kind` | enum `Navigate` \| `Dialog` \| `Sheet` \| `External` | `Navigate` routes to a URL, `Dialog` opens per [`21-component-dialog.md`](./21-component-dialog.md) §4, `Sheet` opens the long-form variant, `External` opens with the `ArrowUpRight` glyph per [`26-iconography-and-assets.md`](./26-iconography-and-assets.md) §5. |
| `Target` | string | For `Navigate`, the route path from [`13-navigation-ia.md`](./13-navigation-ia.md). For `Dialog` / `Sheet`, the runtime `DialogId` (matches the file that owns the Dialog). For `External`, a fully qualified `https://` URL. |
| `Permission` | string | Exact `PermissionKey` from [`../21-app/40-permissions.md`](../21-app/40-permissions.md) §Canonical registry. |
| `OperationId` | string \| null | When the command directly hits one API operation, the exact `operationId` from [`../21-app/26-route-dto-index.md`](../21-app/26-route-dto-index.md). Navigation commands and Dialog openers set `null`. |
| `Destructive` | boolean | If `true`, MUST route through the confirmation Dialog per [`21-component-dialog.md`](./21-component-dialog.md) §5. Palette selection NEVER fires the mutation directly. |
| `IdempotencyBinding` | enum `None` \| `OnDialogOpen` \| `OnMutation` | `None` for navigation and reads. `OnDialogOpen` generates an `Idempotency-Key` when the confirmation Dialog opens and reuses it across retries per [`../21-app/29-idempotency-lifecycle.md`](../21-app/29-idempotency-lifecycle.md). `OnMutation` (rare) generates the key at submit time; allowed only for non-destructive mutations without a confirmation step. |
| `Icon` | Lucide name | Concept-to-glyph mapping from [`26-iconography-and-assets.md`](./26-iconography-and-assets.md) §5. Ad hoc icons BANNED. |
| `Group` | enum `Navigation` \| `Commands` | Palette group placement. `Direct lookup` and `Recent` are runtime-derived, NOT registered. |
| `Since` | semver | Version this command shipped in. `0.155.0` for v1 rows. |
| `DeprecatedIn` | semver \| null | Set when a rename or removal is announced. |

## 3. Aliases and stable ids

- `CommandId` is the STABLE identifier. Renames are BANNED; add a new row with a new `CommandId`, mark the old row `DeprecatedIn`, keep both for exactly one minor version, then remove.
- `Label` MAY change freely between versions (copy improvements) as long as the verb from [`27-content-voice.md`](./27-content-voice.md) §3 is preserved.

## 4. Permission-hidden rule

Per [`31-search-and-command-palette.md`](./31-search-and-command-palette.md) §5.1 and [`28-a11y-conformance.md`](./28-a11y-conformance.md) §4.3, commands the caller lacks `Permission` for are HIDDEN from the Palette result list and their trigger Buttons are HIDDEN from surface UIs. Rendering a disabled Palette row or disabled Button with a "you lack permission" tooltip is BANNED.

Runtime evaluation calls `has_permission(auth.uid(), <Permission>)` per [`../21-app/40-permissions.md`](../21-app/40-permissions.md) §7. The evaluation MUST happen at Palette open AND at every route mount; caching beyond the current route mount is BANNED (permission grants can change mid-session).

## 5. Destructive command contract

A row with `Destructive: true` MUST satisfy all of:

1. `Kind` is `Dialog`.
2. `IdempotencyBinding` is `OnDialogOpen`.
3. The confirmation Dialog implements the phrase-typing gate per [`21-component-dialog.md`](./21-component-dialog.md) §5 (typed phrase MUST match `LicenseId` / `SerialValue` / `UserId` / `ClientId` per row).
4. The mutation logs `<Command>Confirmed` at info AND `<Command>Executed` at info on success, or `<Command>Failed` at warn/error with `ErrorCode` + `RequestId` per [`../21-app/22-log-line-contract.md`](../21-app/22-log-line-contract.md).

Silent destructive execution (no Dialog, no phrase gate, no confirmation log) is BANNED.

## 6. Navigation commands (v1)

| CommandId | Label | Target | Permission | Icon | Since |
|-----------|-------|--------|-----------|------|-------|
| `Nav.Admin.Overview` | Admin overview | `/admin` | `Admin.Overview.Read` | `LayoutDashboard` | 0.155.0 |
| `Nav.Admin.Licenses` | Licenses | `/admin/licenses` | `Licenses.Read` | `KeyRound` | 0.155.0 |
| `Nav.Admin.Serials` | Serials | `/admin/serials` | `Serials.Lookup` | `Barcode` | 0.155.0 |
| `Nav.Admin.Users` | Users | `/admin/users` | `Users.Manage` | `Users` | 0.155.0 |
| `Nav.Admin.Resellers` | Resellers | `/admin/resellers` | `Resellers.Manage` | `Building2` | 0.155.0 |
| `Nav.Admin.Prefixes` | Prefixes | `/admin/prefixes` | `Prefixes.Manage` | `Tag` | 0.155.0 |
| `Nav.Admin.Categories` | License categories | `/admin/categories` | `LicenseCategories.Manage` | `FolderTree` | 0.155.0 |
| `Nav.Admin.Features` | Features | `/admin/features` | `Features.Manage` | `Puzzle` | 0.155.0 |
| `Nav.Admin.Quotas` | Quota requests | `/admin/quotas` | `Quotas.Approve` | `Gauge` | 0.155.0 |
| `Nav.Admin.Audit` | Audit log | `/admin/audit` | `AuditEvents.Read` | `ScrollText` | 0.155.0 |
| `Nav.Admin.RateLimits` | Rate limits | `/admin/abuse` | `RateLimitBuckets.Read` | `ShieldAlert` | 0.155.0 |
| `Nav.Admin.Updates` | App updates | `/admin/updates` | `AppUpdates.Manage` | `PackageOpen` | 0.155.0 |
| `Nav.Reseller.Overview` | Reseller overview | `/reseller/$resellerId` | `Reseller.Overview.Read` | `Store` | 0.155.0 |
| `Nav.Reseller.Licenses` | My licenses | `/reseller/$resellerId/licenses` | `Licenses.Read` | `KeyRound` | 0.155.0 |
| `Nav.Reseller.Packages` | License packages | `/reseller/$resellerId/packages` | `LicensePackages.Manage` | `Package` | 0.155.0 |
| `Nav.Reseller.Quota` | Quota | `/reseller/$resellerId/quota` | `Reseller.Overview.Read` | `Gauge` | 0.155.0 |
| `Nav.Reseller.Activity` | Activity | `/reseller/$resellerId/activity` | `AuditEvents.ReadOwn` | `Activity` | 0.155.0 |
| `Nav.Builder.Overview` | Builder overview | `/builder` | `Builder.Overview.Read` | `Hammer` | 0.155.0 |
| `Nav.Builder.Clients` | OAuth clients | `/builder/clients` | `Clients.Manage` | `AppWindow` | 0.155.0 |
| `Nav.Builder.Keys` | Client keys | `/builder/keys` | `ClientKeys.Manage` | `Key` | 0.155.0 |
| `Nav.Builder.Updates` | Update manifests | `/builder/updates` | `AppUpdates.ReadOwn` | `PackageOpen` | 0.155.0 |
| `Nav.Builder.Activity` | Activity | `/builder/activity` | `AuditEvents.ReadOwn` | `Activity` | 0.155.0 |
| `Nav.EndUser.Products` | My products | `/app/products` | `EndUser.Products.Read` | `Box` | 0.155.0 |
| `Nav.EndUser.Devices` | My devices | `/app/devices` | `EndUser.Devices.Read` | `Laptop` | 0.155.0 |
| `Nav.Account.Profile` | My profile | `/account` | `Users.ReadSelf` | `UserCircle` | 0.155.0 |
| `Nav.Docs.Api` | API documentation | `/docs/api` | `Users.ReadSelf` | `BookOpen` | 0.155.0 |

Route paths MUST match [`13-navigation-ia.md`](./13-navigation-ia.md) exactly. Trailing slashes are BANNED per TanStack Router conventions.

## 7. Action commands (v1)

| CommandId | Label | Kind | Target | Permission | OperationId | Destructive | IdempotencyBinding | Icon | Since |
|-----------|-------|------|--------|-----------|-------------|-------------|--------------------|------|-------|
| `Admin.Licenses.Issue` | Issue license... | Dialog | `AdminIssueLicenseDialog` | `Licenses.Create` | `Admin.Licenses.Create` | false | OnDialogOpen | `Plus` | 0.155.0 |
| `Admin.Licenses.Update` | Edit license... | Dialog | `AdminEditLicenseDialog` | `Licenses.Update` | `Admin.Licenses.Update` | false | OnDialogOpen | `Pencil` | 0.155.0 |
| `Admin.Licenses.Revoke` | Revoke license... | Dialog | `AdminRevokeLicenseDialog` | `Licenses.Revoke` | `Admin.Licenses.Revoke` | true | OnDialogOpen | `Ban` | 0.155.0 |
| `Admin.Serials.Issue` | Issue serial... | Dialog | `AdminIssueSerialDialog` | `Serials.Issue` | `Admin.Serials.Create` | false | OnDialogOpen | `Plus` | 0.155.0 |
| `Admin.Serials.Rebind` | Rebind serial... | Dialog | `AdminRebindSerialDialog` | `Serials.Issue` | `Admin.Serials.Rebind` | true | OnDialogOpen | `Repeat` | 0.155.0 |
| `Admin.Users.Invite` | Invite user... | Dialog | `AdminInviteUserDialog` | `Users.Manage` | `Admin.Users.Invite` | false | OnDialogOpen | `UserPlus` | 0.155.0 |
| `Admin.Users.Disable` | Disable user... | Dialog | `AdminDisableUserDialog` | `Users.Manage` | `Admin.Users.Disable` | true | OnDialogOpen | `UserMinus` | 0.172.0 |
| `Admin.Users.Reactivate` | Reactivate user... | Dialog | `AdminReactivateUserDialog` | `Users.Manage` | `Admin.Users.Reactivate` | false | OnDialogOpen | `UserCheck` | 0.172.0 |

| `Admin.Roles.Assign` | Assign role... | Dialog | `AdminAssignRoleDialog` | `Roles.Assign` | `Admin.Users.AssignRole` | false | OnDialogOpen | `Shield` | 0.155.0 |
| `Admin.Permissions.Grant` | Grant permission... | Dialog | `AdminGrantPermissionDialog` | `Permissions.Assign` | `Admin.Users.GrantPermission` | false | OnDialogOpen | `ShieldCheck` | 0.155.0 |
| `Admin.Permissions.Revoke` | Revoke permission... | Dialog | `AdminRevokePermissionDialog` | `Permissions.Assign` | `Admin.Users.RevokePermission` | true | OnDialogOpen | `ShieldOff` | 0.155.0 |
| `Admin.Resellers.Create` | Create reseller... | Dialog | `AdminCreateResellerDialog` | `Resellers.Manage` | `Admin.Resellers.Create` | false | OnDialogOpen | `Plus` | 0.155.0 |
| `Admin.Prefixes.Create` | Create prefix... | Dialog | `AdminCreatePrefixDialog` | `Prefixes.Manage` | `Admin.Prefixes.Create` | false | OnDialogOpen | `Plus` | 0.155.0 |
| `Admin.Categories.Create` | Create category... | Dialog | `AdminCreateCategoryDialog` | `LicenseCategories.Manage` | `Admin.Categories.Create` | false | OnDialogOpen | `Plus` | 0.155.0 |
| `Admin.Features.Create` | Create feature... | Dialog | `AdminCreateFeatureDialog` | `Features.Manage` | `Admin.Features.Create` | false | OnDialogOpen | `Plus` | 0.155.0 |
| `Admin.Features.Edit` | Edit feature... | Dialog | `AdminEditFeatureDialog` | `Features.Manage` | `Admin.Features.Update` | false | OnDialogOpen | `Pencil` | 0.172.0 |
| `Admin.Features.Deprecate` | Deprecate feature... | Dialog | `AdminDeprecateFeatureDialog` | `Features.Manage` | `Admin.Features.Deprecate` | true | OnDialogOpen | `Archive` | 0.172.0 |
| `Admin.Features.Reactivate` | Reactivate feature... | Dialog | `AdminReactivateFeatureDialog` | `Features.Manage` | `Admin.Features.Reactivate` | false | OnDialogOpen | `ArchiveRestore` | 0.172.0 |

| `Admin.Quotas.Approve` | Approve quota request... | Dialog | `AdminApproveQuotaDialog` | `Quotas.Approve` | `Admin.QuotaRequests.Approve` | false | OnDialogOpen | `Check` | 0.155.0 |
| `Admin.Quotas.Deny` | Deny quota request... | Dialog | `AdminDenyQuotaDialog` | `Quotas.Approve` | `Admin.QuotaRequests.Deny` | true | OnDialogOpen | `X` | 0.155.0 |
| `Admin.Quotas.Adjust` | Adjust reseller quota... | Dialog | `AdminAdjustQuotaDialog` | `Quotas.Adjust` | `Admin.ResellerQuotas.Update` | false | OnDialogOpen | `Sliders` | 0.155.0 |
| `Admin.Updates.Publish` | Publish update... | Sheet | `AdminPublishUpdateSheet` | `AppUpdates.Manage` | `Admin.AppUpdates.Publish` | false | OnDialogOpen | `Upload` | 0.155.0 |
| `Admin.Updates.Retract` | Retract update... | Dialog | `AdminRetractUpdateDialog` | `AppUpdates.Manage` | `Admin.AppUpdates.Retract` | true | OnDialogOpen | `Undo2` | 0.155.0 |
| `Reseller.Licenses.Issue` | Issue license... | Dialog | `ResellerIssueLicenseDialog` | `Licenses.Create` | `Reseller.Licenses.Create` | false | OnDialogOpen | `Plus` | 0.155.0 |
| `Reseller.Quota.Request` | Request more quota... | Dialog | `ResellerRequestQuotaDialog` | `Quotas.Request` | `Reseller.QuotaRequests.Create` | false | OnDialogOpen | `TrendingUp` | 0.155.0 |
| `Reseller.Quota.Cancel` | Cancel quota request... | Dialog | `ResellerCancelQuotaDialog` | `Quotas.Request` | `Reseller.QuotaRequests.Cancel` | true | OnDialogOpen | `X` | 0.155.0 |
| `Reseller.Packages.Create` | Create license package... | Sheet | `ResellerCreatePackageSheet` | `LicensePackages.Manage` | `Reseller.Packages.Create` | false | OnDialogOpen | `Plus` | 0.155.0 |
| `Builder.Clients.Create` | Create OAuth client... | Sheet | `BuilderCreateClientSheet` | `Clients.Manage` | `Builder.Clients.Create` | false | OnDialogOpen | `Plus` | 0.155.0 |
| `Builder.Keys.Rotate` | Rotate client key... | Dialog | `BuilderRotateKeyDialog` | `ClientKeys.Manage` | `Builder.ClientKeys.Rotate` | true | OnDialogOpen | `RefreshCcw` | 0.155.0 |
| `Builder.Keys.Revoke` | Revoke client key... | Dialog | `BuilderRevokeKeyDialog` | `ClientKeys.Manage` | `Builder.ClientKeys.Revoke` | true | OnDialogOpen | `KeyRound` | 0.155.0 |
| `Builder.Clients.RotateSecret` | Rotate client secret... | Dialog | `BuilderRotateSecretDialog` | `ClientKeys.Manage` | `Builder.Clients.RotateSecret` | true | OnDialogOpen | `KeyRound` | 0.172.0 |
| `Builder.Updates.Publish` | Publish update... | Sheet | `BuilderPublishUpdateSheet` | `AppUpdates.Manage` | `Builder.AppUpdates.Publish` | false | OnDialogOpen | `Upload` | 0.155.0 |


Placeholder `OperationId` values above resolve against [`../21-app/26-route-dto-index.md`](../21-app/26-route-dto-index.md); the parity linter (§9) catches drift.

## 8. Telemetry

Every command execution emits one of the following via `logger.info` per [`../21-app/22-log-line-contract.md`](../21-app/22-log-line-contract.md):

- `CommandInvoked` at command trigger (Palette Enter, Button click, Menu item click): `CommandId`, `TriggerKind` (`Palette` / `Button` / `Menu`), `Route`, `RequestId`.
- `CommandConfirmed` at Dialog confirm submit (Destructive only): `CommandId`, `Route`, `IdempotencyKey`, `RequestId`.
- `CommandExecuted` on mutation success: `CommandId`, `Route`, `DurationMs`, `RequestId`.
- `CommandFailed` on mutation failure: `CommandId`, `Route`, `ErrorCode`, `RequestId`.
- `CommandCancelled` on Dialog dismiss without submit: `CommandId`, `Route`, `Reason` (`Escape` / `Cancel` / `Backdrop`).

Field VALUES underlying the command (license identifiers, serial values, emails) are NEVER logged in these lines beyond the caller's own `RequestId`. `IdempotencyKey` is a UUID and safe to log.

### 8.1 Blueprint shorthand for command telemetry

Route blueprints (`33-` through `42-`) use a compact shorthand when documenting per-command telemetry: a backticked token of the form `` `<Verb>Confirmed` ``, `` `<Verb>Executed` ``, or `` `<Verb>Failed` `` (e.g. `LicenseIssueConfirmed`, `QuotaRequestExecuted`, `SerialRebindFailed`). These are NOT new event names. They are aliases for the §8 canonical events `CommandConfirmed` / `CommandExecuted` / `CommandFailed` with `CommandId` bound to the matching §7 row.

Resolution rule (normative):

- `<Verb>Confirmed` resolves to `CommandConfirmed` with `CommandId = <§7 row whose OperationId or Action matches <Verb>>`.
- `<Verb>Executed` resolves to `CommandExecuted` with the same `CommandId`.
- `<Verb>Failed` resolves to `CommandFailed` with the same `CommandId` plus `ErrorCode` from [`../21-app/12-error-taxonomy.md`](../21-app/12-error-taxonomy.md).

The runtime MUST emit the canonical event name (`CommandConfirmed` / `CommandExecuted` / `CommandFailed`) and the `CommandId` property. Emitting the shorthand token as a literal event name is BANNED because it fragments the event stream and defeats aggregation on `CommandId`. Linter `.lovable/coding-guidelines/check-command-telemetry.py` scans blueprints 33..42 for the shorthand and verifies each `<Verb>` resolves to at least one `CommandId` in §7 (prefix or contains match on the verb).

End-user routes (blueprint `41-`) do NOT expose a Command Palette per `31-search-and-command-palette.md` §2, so their shorthand tokens (e.g. `SerialVerifyConfirmed`, `ProductReverifyExecuted`) resolve instead to the analytics event families in [`58-analytics-event-catalog.md`](./58-analytics-event-catalog.md) §4.4 (`<Family>.Started` / `<Family>.Resolved`) with `Source: EndUserUi`. The linter accepts this fallback: if a `<Verb>` does not match a §7 `CommandId`, it MUST match a §4.4 family name (leaf or last-two-segment concatenation) before the token is considered resolved. Backticked ErrorCode tokens (`AuthFailed`, taxonomy codes from `../21-app/12-error-taxonomy.md`) are NOT shorthand and MUST be ignored by the linter when the same line references `ErrorCode`, `error message`, or the taxonomy file.




## 9. Verification

- `python3 linter-scripts/check-forbidden-strings.py` exits 0.
- `python3 linter-scripts/check-spec-cross-links.py` exits 0.
- Future `linter-scripts/check-command-permission-parity.py` asserts every §6/§7 `Permission` is a canonical key in [`../21-app/40-permissions.md`](../21-app/40-permissions.md) and every §7 `OperationId` matches an entry in [`../21-app/26-route-dto-index.md`](../21-app/26-route-dto-index.md).
- Future `tests/command-registry.test.ts` asserts every runtime command constant matches exactly one row here, and every `Destructive: true` row is `Kind: Dialog` with `IdempotencyBinding: OnDialogOpen`.

## 10. Anti-patterns

1. Commands wired in feature code without a §6/§7 row.
2. Renaming a `CommandId` in place instead of add-new / deprecate-old.
3. Disabled Palette rows or Buttons for permission-lacking commands (MUST be hidden).
4. Destructive command with `Kind: Navigate` (destructive routing is not confirmed).
5. Destructive command with `IdempotencyBinding: OnMutation` (retries mint fresh keys, breaking idempotency).
6. Palette selection firing the mutation directly for a `Destructive: true` row.
7. Icon outside the [`26-iconography-and-assets.md`](./26-iconography-and-assets.md) §5 map.
8. Trailing-slash `Target` on a `Navigate` command.
9. Logging field VALUES (license identifiers, serial values, emails) in `CommandInvoked` / `CommandExecuted`.
10. Caching `has_permission()` beyond the current route mount.

## 11. Acceptance criteria

- AC-CMD-001: Every runtime `CommandId` constant is present in §6 or §7 exactly once.
- AC-CMD-002: Every §6/§7 `Permission` is a canonical key in [`../21-app/40-permissions.md`](../21-app/40-permissions.md) (parity linter).
- AC-CMD-003: Every §7 `OperationId` matches an entry in [`../21-app/26-route-dto-index.md`](../21-app/26-route-dto-index.md) (parity linter).
- AC-CMD-004: Every `Destructive: true` row is `Kind: Dialog` and `IdempotencyBinding: OnDialogOpen`.
- AC-CMD-005: Permission-lacking commands are absent from the Palette and their Buttons are absent from surface UIs (test: `tests/palette-permission-filter.test.tsx`, future).
- AC-CMD-006: `CommandInvoked` / `CommandConfirmed` / `CommandExecuted` / `CommandFailed` / `CommandCancelled` fire per §8; `tests/command-telemetry.test.ts` (future) asserts exactly-once semantics per user action.
- AC-CMD-007: Renaming a `CommandId` fails CI (parity linter treats the old id as missing).
