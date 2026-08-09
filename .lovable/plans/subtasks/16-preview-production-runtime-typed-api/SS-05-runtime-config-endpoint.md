---
Slug: runtime-config-endpoint
Status: pending
Created: 2026-07-20
Parent: 16-preview-production-runtime-typed-api
---

# SS-05: Runtime-config backend endpoint

## Route

`PUT /api/admin/runtime-config` (Casbin: `runtime-config:update`; only root role granted).

## Behavior

- Reads the current `version.json` from repo root (path resolved from `config('app.repo_root')` with a fallback to `base_path('..')`).
- Applies the request body (`Mode`, `ApiBaseUrl`, `PreviewSeed`) atomically:
  1. Write to `version.json.tmp` with `LOCK_EX`.
  2. Validate via a `FormRequest` that mirrors `spec/28-runtime-modes/01-version-json-schema.md`.
  3. `rename()` to `version.json`.
- Emits an audit-log row (`RuntimeConfigChanged`, from -> to, actor).
- Increments `Version` (integer) in the JSON; enforce `If-Match` on the request.
- On mismatch, return 412 with `Attributes.ErrorCode = RuntimeConfigPreconditionFailed`.
- Response is the new full `RuntimeConfig` object and the new ETag.

## Error handling

- Filesystem failure -> throw a domain-specific `LaraException` subclass (`RuntimeConfigWriteFailed`), do NOT catch and swallow. Log with `RequestId`, `ErrorId`, and the underlying errno.
- Concurrent write attempts serialize via the file lock; if lock acquisition fails within 500ms, return 429 with `Retry-After: 1`.

## Rules

- Function bodies capped at 15 lines (coding-guidelines).
- No magic strings for error codes; use the `ErrorCode` enum.
- Test coverage: happy path, If-Match mismatch, lock timeout, invalid mode, invalid apiBaseUrl.
