# version.json Schema

**Version:** 1.0.0
**Updated:** 2026-07-20
**AI Confidence:** Draft
**Ambiguity:** Low

---

## Keywords

`version.json` · `json-schema` · `runtime-mode` · `api-base-url` · `preview-seed` · `semver` · `iso-8601` · `atomic-write`

---

## Scoring

| Criterion | Status |
|-----------|--------|
| `00-overview.md` present in module | ✅ (`spec/28-runtime-modes/00-overview.md`) |
| AI Confidence assigned | ✅ |
| Ambiguity assigned | ✅ |
| Keywords present | ✅ |
| Scoring table present | ✅ |

---

## Purpose

Pin the on-disk contract for repo-root `version.json`. This file is the single source of truth for `version`, `mode`, `apiBaseUrl`, `previewSeed`, `updatedAt`, and `allowRuntimeToggle` (see INV-RM-01..INV-RM-10 in `00-overview.md`). Step 9 creates the file, Step 11 enforces this schema via `linter-scripts/check-version-json.py`, and Step 58 uses the same schema for atomic rewrites in `PUT /api/admin/runtime-config`.

## Location

- Path: repo-root `/version.json` (sibling of `package.json`, `backend/composer.json`, `README.md`).
- Encoding: UTF-8, LF line endings, trailing newline.
- Write discipline: atomic (write to `version.json.tmp` in the same directory, `fsync`, `rename`). Never partial-write.
- Read discipline: client fetches `/version.json` on boot via `src/lib/version-json-loader.ts` (Step 15). Server reads via Laravel `Storage::disk('root')->get('version.json')` inside `RuntimeConfigController` (Step 58).

## JSON Schema (Draft 2020-12)

```json
{
  "$schema": "https://json-schema.org/draft/2020-12/schema",
  "$id": "https://lara.local/schemas/version.json",
  "title": "LaraRuntimeConfig",
  "type": "object",
  "additionalProperties": false,
  "required": [
    "Version",
    "Mode",
    "ApiBaseUrl",
    "PreviewSeed",
    "UpdatedAt",
    "AllowRuntimeToggle"
  ],
  "properties": {
    "Version": {
      "type": "string",
      "pattern": "^(0|[1-9]\\d*)\\.(0|[1-9]\\d*)\\.(0|[1-9]\\d*)$",
      "description": "SemVer MAJOR.MINOR.PATCH. MUST equal package.json.version AND backend/composer.json.version (INV-RM-09)."
    },
    "Mode": {
      "type": "string",
      "enum": ["preview", "dev", "production"],
      "description": "Compile-time default mode; runtime override precedence per spec 02."
    },
    "ApiBaseUrl": {
      "type": ["string", "null"],
      "description": "Required when Mode != 'preview'. MUST be null when Mode == 'preview' (INV-RM-02). Absolute URL only.",
      "format": "uri"
    },
    "PreviewSeed": {
      "type": "string",
      "enum": ["default", "empty", "error"],
      "description": "Selects the seed loader in preview mode; ignored in dev and production but MUST be a valid enum member."
    },
    "UpdatedAt": {
      "type": "string",
      "format": "date-time",
      "pattern": "^\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}:\\d{2}(\\.\\d+)?Z$",
      "description": "ISO-8601 UTC (Zulu). mtime of the last write."
    },
    "AllowRuntimeToggle": {
      "type": "boolean",
      "description": "When false, the admin Runtime page renders read-only and PUT /api/admin/runtime-config returns 403."
    }
  },
  "allOf": [
    {
      "if": { "properties": { "Mode": { "const": "preview" } } },
      "then": { "properties": { "ApiBaseUrl": { "type": "null" } } }
    },
    {
      "if": { "properties": { "Mode": { "enum": ["dev", "production"] } } },
      "then": {
        "properties": {
          "ApiBaseUrl": {
            "type": "string",
            "pattern": "^https://|^http://localhost(:\\d+)?/"
          }
        },
        "required": ["ApiBaseUrl"]
      }
    }
  ]
}
```

## Field Rules

- **Casing.** PascalCase keys, per project memory Core rule ("PascalCase for DB table/column names and JSON keys"). Client code deserializes into camelCase types in `src/lib/runtime-mode.ts` via an explicit adapter, no automatic key transforms.
- **`Version`.** MUST match `^\d+\.\d+\.\d+$`. Cross-file equality enforced by `linter-scripts/check-version-json.py` (Step 11) plus the existing version-sync gate.
- **`Mode`.** Closed set of three literals. Any other value fails schema. No default: writer MUST set it.
- **`ApiBaseUrl`.** Conditional per `allOf`. In `preview`, MUST be `null` (INV-RM-02: no network egress). In `dev`, MUST match `^http://localhost(:\d+)?/` or `^https://`. In `production`, MUST match `^https://` (localhost forbidden). Trailing slash mandatory to make URL joins deterministic (`${ApiBaseUrl}api/admin/...` never yields `//api`).
- **`PreviewSeed`.** Closed set. Even in `dev`/`production` the field is required and MUST be a valid enum member; readers just ignore it, so a file rewritten by an admin toggle round-trips without loss.
- **`UpdatedAt`.** RFC 3339 with `Z` suffix (not `+00:00`). Regex above rejects offset forms so string comparison sorts chronologically.
- **`AllowRuntimeToggle`.** Boolean, no null.

## Canonical Examples

Preview (initial, Step 9):

```json
{
  "Version": "0.517.0",
  "Mode": "preview",
  "ApiBaseUrl": null,
  "PreviewSeed": "default",
  "UpdatedAt": "2026-07-20T00:00:00Z",
  "AllowRuntimeToggle": true
}
```

Production (post-deploy):

```json
{
  "Version": "0.517.0",
  "Mode": "production",
  "ApiBaseUrl": "https://api.lara.example.com/",
  "PreviewSeed": "default",
  "UpdatedAt": "2026-07-20T14:32:11Z",
  "AllowRuntimeToggle": false
}
```

Dev (localhost Laravel on port 8000):

```json
{
  "Version": "0.517.0",
  "Mode": "dev",
  "ApiBaseUrl": "http://localhost:8000/",
  "PreviewSeed": "default",
  "UpdatedAt": "2026-07-20T09:15:00Z",
  "AllowRuntimeToggle": true
}
```

## Rejected Shapes (linter MUST fail)

- Missing any required key.
- `Mode: "preview"` with a non-null `ApiBaseUrl` (violates INV-RM-02).
- `Mode: "production"` with `ApiBaseUrl` starting `http://` (localhost or otherwise).
- `Version` not matching semver, or not equal to `package.json.version` or `backend/composer.json.version`.
- `UpdatedAt` with `+00:00` offset or missing `Z`.
- Extra keys (`additionalProperties: false`).
- camelCase or snake_case keys (`version`, `api_base_url`).

## Error-Contract Alignment

Any `PUT /api/admin/runtime-config` request that would produce an invalid `version.json` MUST be rejected with a `LaraException` envelope carrying:

- `Attributes.Code`: `RUNTIME_CONFIG_INVALID` (to be registered in `spec/03-error-manage/03-error-code-registry` at Step 58 landing).
- `Attributes.Errors[]`: one entry per failing JSON Schema path (`Mode`, `ApiBaseUrl`, ...).
- `Attributes.RequestId` and `Attributes.ErrorId`: standard correlation, no envelope drift.

The client MUST NOT persist to `localStorage` on 4xx. `useRuntimeMode.setMode()` rolls back on rejection.

## Cross-References

- `spec/28-runtime-modes/00-overview.md`: INV-RM-01..INV-RM-10 pinning the fields formalized here.
- `spec/28-runtime-modes/02-mode-selection-precedence.md` (Step 3, pending): consumes `Mode` and `ApiBaseUrl`.
- `spec/28-runtime-modes/05-admin-runtime-toggle.md` (Step 6, pending): the write path that MUST validate against this schema.
- Plan 16 Steps 9 (create root `version.json`), 11 (`check-version-json.py`), 15 (`version-json-loader.ts`), 58 (`RuntimeConfigController`).
- `spec/03-error-manage/`: envelope shape and error-code registry.
- `spec/04-database-conventions/`: PascalCase JSON keys rule (same convention applied here).
