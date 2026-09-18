# 49. Concurrency Conflict UX Contract

**Version:** 1.1.0
**Status:** Normative
**Applies to:** every mutating UI surface that sends `If-Match`
**Related:** `11-api-contracts/09-concurrency-control.md`, `12-error-taxonomy.md` (AC-CONCUR-003)

## 1. Scope

Every UI surface that fires a mutating request carrying `If-Match` MUST implement the recovery pattern below when the server returns `412 PreconditionFailed`. In v1.0 the in-scope surfaces are:

- `LicenseDetailActions` (Save, Revoke) in `src/components/admin/license-detail-actions.tsx`.
- Reserved: feature-map editor (put/delete `LicenseFeatures/{LicenseFeatureId}`).
- Reserved: quota-request approve/reject dialog (when it moves to ETag-guarded rows).
- Reserved: environment update forms.

Non-mutating surfaces and surfaces that do not send `If-Match` (public verify, self-update) are out of scope.

## 2. Detection

A `PreconditionFailed` error is signalled by `LaraApiError.errorCode === ApiErrorCodeType.PreconditionFailed`. Surfaces MUST branch on the enum, never on substring matches against the message or the HTTP status number.

## 3. Recovery affordance

On detection, the surface MUST:

1. Keep every edited field value in local component state. Reloading the loader MUST NOT overwrite the operator's in-progress edits.
2. Render a non-blocking status region (ARIA `role="status"`) that explains: (a) the record changed since load, (b) edits are preserved, (c) the next action is to reload and retry.
3. Expose a single `Reload latest and retry` control that invokes `router.invalidate()` on the current route. When the invalidation resolves, the surface MUST clear the conflict state and the previous error message so the operator can submit again without a page reload.
4. Emit a `toast.error` whose description matches the status region wording. Toast copy MUST NOT include the raw error code or Request Id; those remain in the inline `role="alert"` message so support tickets keep the correlation id.
5. Re-enable the primary submit control only after the reload succeeds. While reload is in flight, all mutating controls MUST be disabled.

## 4. Prohibited behaviors

- Full-page reloads (`window.location.reload()`) are forbidden; they discard operator edits and violate rule 3.1.
- Silent auto-retry with the stale ETag is forbidden; it would loop against a deterministic 412.
- Suppressing the error toast is forbidden; the operator must see that the save/revoke did not persist.
- Reusing generic error copy that omits the "changed since you loaded it" phrase is forbidden; that phrase is the copy anchor used by acceptance tests.

## 5. Acceptance criteria

- **AC-CONFLICT-001.** Given a mutating surface has captured an ETag, when the server returns `412 PreconditionFailed`, then the surface renders exactly one status region with the copy anchor "changed since you loaded it" and exactly one `Reload latest and retry` button.
- **AC-CONFLICT-002.** Given the operator clicks `Reload latest and retry`, when `router.invalidate()` resolves, then the status region is unmounted, the inline error is cleared, edited field values are unchanged, and the primary submit control is re-enabled.
- **AC-CONFLICT-003.** Given a conflict is on screen, when a second mutating attempt is fired without reloading, then the surface MUST NOT send the request; it MUST re-focus the reload control.
- **AC-CONFLICT-004.** Toast description for a conflict MUST contain the substring "Reload latest" and MUST NOT contain the error code identifier `PreconditionFailed` or a Request Id.
- **AC-CONFLICT-005.** No surface may branch on `error.httpStatus === 412` or on `error.message` substrings; branching MUST use `ApiErrorCodeType.PreconditionFailed`.

## 6. Linter hook

`linter-scripts/check-concurrency-conflict-ui.py` (wired into `linter-scripts/run.sh` as `concurrency-conflict-ui`) scans every `.tsx` file under `src/components/**` that imports any ETag-guarded helper from the closed set (`updateLicense`, `deleteLicense`, `putLicenseFeature`, `deleteLicenseFeature`) and asserts:

1. An `ApiErrorCodeType.PreconditionFailed` branch is present.
2. A `router.invalidate()` call is present.
3. The copy anchor "changed since you loaded it" is present.
4. Forbidden patterns are absent: `httpStatus === 412`, message-substring branching on `Precondition`, and `window.location.reload(`.

A component may waive the check by adding the comment `// lint:allow-no-conflict-ui`. Waivers MUST justify why in the same file.

