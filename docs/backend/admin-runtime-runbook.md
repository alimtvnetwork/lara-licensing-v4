# Admin Runtime Toggle - Operator Runbook

**Plan 16 Step 89.** Operator-facing SOP for the admin Runtime page. The
machine-readable contract lives in
[`spec/28-runtime-modes/05-admin-runtime-toggle.md`](../../spec/28-runtime-modes/05-admin-runtime-toggle.md);
this file translates it into a checklist a human on call can execute.

## Who may flip it

- Only `root-admin` (Supabase role check via `public.has_role(auth.uid(), 'root_admin')`).
- `admin` sees the sidebar entry but the server returns 403
  `RUNTIME_CONFIG_FORBIDDEN` on write. Do not grant `root-admin` as a workaround.
- `moderator`, `user`, and unauthenticated callers receive 404 (surface hidden).

## Preconditions before you touch it

1. `AllowRuntimeToggle` is `true` in the current `public/version.json`. If `false`,
   the endpoint returns 423 `RUNTIME_CONFIG_LOCKED`; this state can only be lifted
   by a deploy that rewrites `version.json` (rule W-01, S-03).
2. You have the current `ETag` from `GET /api/admin/runtime-config`. The Admin
   Runtime page fetches this automatically; if you are hitting the endpoint by
   hand, capture it and send it back as `If-Match` (rules C-01, C-02). No
   `If-Match` = 428 `PRECONDITION_REQUIRED`.
3. Target mode's required fields are ready:
   - `preview` requires `PreviewSeed in { default, empty, error }` and
     `ApiBaseUrl: null`.
   - `dev` requires `ApiBaseUrl` on `http://localhost:*` or a `.local` host and
     `PreviewSeed: null`.
   - `production` requires `ApiBaseUrl` on `https://` and `PreviewSeed: null`.
   Cross-field failures return 422 `RUNTIME_CONFIG_MODE_MISMATCH` (rule M-02).

## Cutover checklist (preview -> production)

1. Announce a 5-minute window; the response carries `Attributes.RequiresReload =
   true` and every open tab hard-reloads (rule S-02).
2. Open Admin > Runtime. Confirm the current values match what the runbook
   asserts.
3. Set `Mode = production`, `ApiBaseUrl = https://<prod-host>`, clear
   `PreviewSeed`. Tick the diff-confirmation checkbox (rule U-04).
4. Submit. On success, the audit event `runtime_config.updated` is emitted with
   `Before`, `After`, `ChangedKeys`, `ActorUserId`, `ActorIp`, `RequestId`
   (rules AU-01..AU-04). Verify it appears in the audit log before closing the
   window.
5. Reload each open tab; confirm real backend traffic in the network panel and
   that the preview drawer no longer offers scenarios (drawer is preview/dev-only).

## Rollback (production -> preview)

Non-development hosts refuse this by default:

- The service returns 403 `RUNTIME_CONFIG_FORBIDDEN` with
  `Attributes.Reason = "prod_to_preview_disabled"` unless
  `LARA_ALLOW_PROD_TO_PREVIEW=1` is set on the host (rule S-01). Set the env
  var only during a declared incident, then remove it.
- Even with the env var set, the safer rollback is a deploy that rewrites
  `version.json` from the pipeline. Prefer that path when the outage exceeds 15
  minutes so the audit trail is a deploy record, not a manual toggle.

## Error handling (what the response codes mean)

| HTTP | Code | Operator action |
|------|------|-----------------|
| 401 | `UNAUTHENTICATED` | Log in again; session expired. |
| 403 | `RUNTIME_CONFIG_FORBIDDEN` | You are not `root-admin`, or the safety rail S-01 is engaged. |
| 412 | `RUNTIME_CONFIG_CONFLICT` | Someone else wrote between your read and write. Re-fetch, re-review the diff, resubmit. |
| 422 | `RUNTIME_CONFIG_INVALID_FIELD` | The payload has a key outside the mutable set. Fix the client; do not hand-edit. |
| 422 | `RUNTIME_CONFIG_MODE_MISMATCH` | Required/forbidden field pair broken (e.g. production without `https://`). |
| 423 | `RUNTIME_CONFIG_LOCKED` | `AllowRuntimeToggle` is `false`. Ship a deploy to re-enable. |
| 428 | `PRECONDITION_REQUIRED` | You omitted `If-Match`. Refetch and retry. |
| 500 | `RUNTIME_CONFIG_WRITE_FAILED` | `rename()`/`fsync` failed. Check disk, `.tmp` leftovers auto-cleaned in `finally`. Page ops. |
| 500 | `AUDIT_WRITE_FAILED` | Compensating rewrite already restored `Before`. Investigate audit sink before retrying. |

Every code is registered in `spec/03-error-manage/03-error-code-registry` and
exercised by the closed-set parity linter; a new symptom that does not map to
this table is a bug in the client, not a new operator action.

## Observability

- Every request carries an `X-Request-Id`; include it in every incident note.
- Successful writes: `runtime_config.updated` audit event (Before / After /
  ChangedKeys).
- Denied writes: `runtime_config.denied` info-level event (rule AU-04). Absence
  of denial telemetry during a suspicious spike is itself a signal.
- The client toasts "Config changed, please review" on 412 (rule U-03); a burst
  of these means two operators are editing at once.

## Do NOT

- Do not hand-edit `public/version.json` on a live host. The atomic-write
  discipline (tmp + fsync + rename, rule W-01) exists so half-written files
  never boot the app.
- Do not set `AllowRuntimeToggle = true` through the endpoint. It is one-way
  false-only (rule M-03); re-enable by deploy.
- Do not use `supabaseAdmin` / service role to authorize the mutation. The
  role check runs under RLS with the caller's own token (rule A-03).
- Do not skip the diff confirmation. The Admin Runtime page enforces it
  (U-04); bypassing it means the audit `Before` and your intent do not agree.

## References

- Contract: [`spec/28-runtime-modes/05-admin-runtime-toggle.md`](../../spec/28-runtime-modes/05-admin-runtime-toggle.md)
- Schema: [`spec/28-runtime-modes/01-version-json-schema.md`](../../spec/28-runtime-modes/01-version-json-schema.md)
- Operator guide (scenarios / seeds): [`spec/28-runtime-modes/09-operator-guide.md`](../../spec/28-runtime-modes/09-operator-guide.md)
- Error registry: `spec/03-error-manage/03-error-code-registry`
- Controller: `backend/app/Http/Controllers/Admin/RuntimeConfigController.php`
- UI: `src/routes/_authenticated/admin.runtime.tsx`
