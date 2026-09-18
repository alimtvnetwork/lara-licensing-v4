# Manifest Schema

**Version:** 1.0.0
**Updated:** 2026-07-20
**AI Confidence:** Draft
**Ambiguity:** Low

---

## Keywords

`manifest` · `json-schema` · `archive` · `content-hash` · `merkle` · `chunks` · `encryption` · `scope` · `excluded`

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

Pin the JSON Schema (draft-2020-12) for the archive manifest that
every Export and Snapshot writes as the first entry of the archive
stream and every Import and Restore reads first to validate before
touching any downstream class body.

The manifest is the machine-checkable enforcement point for
`INV-BR-SC-1` (one manifest slot per `SC-A..H`), `INV-BR-SC-3`
(restore ordering), and `INV-BR-EX-1` (closure of `public.*`).
A manifest that fails this schema **MUST** cause the archive to
be rejected with `BackupCorrupt` per
[`16-error-taxonomy.md`](<spec-placeholder file="16-error-taxonomy.md" />)
before any DB transaction opens.

---

## Manifest Location

- Archive tar+zstd stream (see [`08-archive-format.md`](<spec-placeholder file="08-archive-format.md" />)): first entry, path `manifest.json`, uncompressed by convention so a reader can validate without decompressing the body.
- Snapshot pointer record: stored inline in `public.snapshots.manifest` (JSONB), identical shape.
- Wire representation: UTF-8 JSON, no BOM, sorted keys, LF line endings; `contentHash` is computed over the canonical (sorted-key, LF-terminated) bytes.

---

## Top-Level Fields

| Field         | Type    | Required | Notes                                                                          |
|---------------|---------|----------|--------------------------------------------------------------------------------|
| `version`     | string  | yes      | Manifest schema version (SemVer). This document pins `1.0.0`.                  |
| `archiveKind` | enum    | yes      | `"export"` or `"snapshot"`. Binds Snapshot vs Export delta from `05-scope-catalog.md`. |
| `archiveId`   | string  | yes      | UUIDv7; primary key for `public.snapshots` or Export job.                      |
| `createdAt`   | string  | yes      | RFC 3339 UTC, second precision.                                                |
| `appVersion`  | string  | yes      | SemVer of the app that produced the archive (matches root `package.json`).     |
| `schemaHash`  | string  | yes      | SHA-256 (hex, lowercase) over concatenated migration file bodies, `SC-A`.      |
| `contentHash` | string  | yes      | SHA-256 (hex) Merkle root over all class content hashes; ties archive body.    |
| `chunkIndex`  | object  | yes      | See `chunkIndex` shape below; binds `08-archive-format.md` chunk layout.       |
| `encryption`  | object  | yes      | See `encryption` shape below; binds `09-encryption-and-keys.md`.               |
| `scope`       | object  | yes      | Exactly one entry per class `SC-A..H`; missing key is a validation error.      |
| `excluded`    | array   | yes      | Every table listed in `EX-A..E` of `06-scope-exclusions.md`, no additions.     |
| `producedBy`  | object  | yes      | Actor context: `userId` (UUID), `role` (closed set), `requestId`.              |
| `signature`   | object  | no       | Optional detached signature block, populated when `LARA_ARCHIVE_SIGNING=true`. |

---

## `scope` Shape (per `SC-A..H`)

The `scope` object has exactly eight keys. Each key maps to a slot
object with a `contentHash` (SHA-256 hex over that class's canonical
bytes) plus class-specific fields:

| Key                | Class  | Slot shape (extra fields beyond `contentHash`)                                          |
|--------------------|--------|-----------------------------------------------------------------------------------------|
| `schema`           | `SC-A` | `{ migrations: string[] }` (ordered list of migration filenames).                       |
| `closedSets`       | `SC-B` | `{ setCount: integer, valueCount: integer }`.                                           |
| `features`         | `SC-C` | `{ featureCount: integer, defaultCount: integer }`.                                     |
| `licenses`         | `SC-D` | `{ licenseCount: integer, epochCount: integer, featureLinkCount: integer }`.            |
| `rbac`             | `SC-E` | `{ userRoleCount: integer, casbinRuleCount: integer, bootstrapPresent: boolean }`.      |
| `domain`           | `SC-F` | `{ tables: [{ name: string, rowCount: integer, contentHash: string }] }`.               |
| `secretsEnvelope`  | `SC-G` | `{ algorithm: "hkdf-sha256", epoch: integer, kid: string }`.                            |
| `files`            | `SC-H` | `{ objectCount: integer, totalBytes: integer, index: string }` (`index` is chunk name). |

Missing any of the eight keys is a validation error. Extra keys in
`scope` are a validation error (closed set enforced by
`additionalProperties: false`).

---

## `chunkIndex` Shape

Binds the archive body layout from
[`08-archive-format.md`](<spec-placeholder file="08-archive-format.md" />):

```
{
  "algorithm": "zstd",
  "level": 19,
  "chunkSize": 8388608,
  "chunks": [
    { "path": "scope/schema.jsonl.zst",     "sha256": "..." , "bytes": 12345 },
    { "path": "scope/domain/<table>.jsonl.zst", "sha256": "...", "bytes": 234567 },
    { "path": "scope/files/<sha256>.bin",    "sha256": "...", "bytes": 890123 }
  ],
  "merkleRoot": "..."
}
```

`merkleRoot` must equal the top-level `contentHash`; a mismatch is
`BackupCorrupt`.

---

## `encryption` Shape

Binds [`09-encryption-and-keys.md`](<spec-placeholder file="09-encryption-and-keys.md" />):

```
{
  "algorithm": "aes-256-gcm",
  "kdf": "hkdf-sha256",
  "epoch": 7,
  "kid": "epoch-7-2026-07",
  "nonceBytes": 12,
  "salt": "<base64url, 32 bytes>",
  "envelope": {
    "sealedDek": "<base64url>",
    "aad": "<base64url of manifest.archiveId + '.' + manifest.version>"
  }
}
```

Restore re-seals `envelope.sealedDek` under the current epoch key
before any `SC-H` body is decrypted, satisfying `INV-BR-C`
(forward secrecy) and `INV-BR-SC-4`.

---

## `excluded` Shape

An array of strings; each string must match one of the table names
enumerated in `06-scope-exclusions.md` `EX-A..E`. The step 30
consistency report diffs this array against that source; deviation
is a `BackupCorrupt` at Import.

---

## JSON Schema (draft-2020-12)

```json
{
  "$schema": "https://json-schema.org/draft/2020-12/schema",
  "$id": "https://lara.local/schemas/backup-manifest-1.0.0.json",
  "title": "LaraBackupManifest",
  "type": "object",
  "additionalProperties": false,
  "required": [
    "version", "archiveKind", "archiveId", "createdAt", "appVersion",
    "schemaHash", "contentHash", "chunkIndex", "encryption",
    "scope", "excluded", "producedBy"
  ],
  "properties": {
    "version":     { "const": "1.0.0" },
    "archiveKind": { "enum": ["export", "snapshot"] },
    "archiveId":   { "type": "string", "format": "uuid" },
    "createdAt":   { "type": "string", "format": "date-time" },
    "appVersion":  { "type": "string", "pattern": "^\\d+\\.\\d+\\.\\d+$" },
    "schemaHash":  { "type": "string", "pattern": "^[0-9a-f]{64}$" },
    "contentHash": { "type": "string", "pattern": "^[0-9a-f]{64}$" },
    "chunkIndex": {
      "type": "object",
      "additionalProperties": false,
      "required": ["algorithm", "level", "chunkSize", "chunks", "merkleRoot"],
      "properties": {
        "algorithm": { "const": "zstd" },
        "level":     { "type": "integer", "minimum": 1, "maximum": 22 },
        "chunkSize": { "type": "integer", "minimum": 1048576 },
        "chunks": {
          "type": "array",
          "items": {
            "type": "object",
            "additionalProperties": false,
            "required": ["path", "sha256", "bytes"],
            "properties": {
              "path":   { "type": "string", "minLength": 1 },
              "sha256": { "type": "string", "pattern": "^[0-9a-f]{64}$" },
              "bytes":  { "type": "integer", "minimum": 0 }
            }
          }
        },
        "merkleRoot": { "type": "string", "pattern": "^[0-9a-f]{64}$" }
      }
    },
    "encryption": {
      "type": "object",
      "additionalProperties": false,
      "required": ["algorithm", "kdf", "epoch", "kid", "nonceBytes", "salt", "envelope"],
      "properties": {
        "algorithm":  { "const": "aes-256-gcm" },
        "kdf":        { "const": "hkdf-sha256" },
        "epoch":      { "type": "integer", "minimum": 1 },
        "kid":        { "type": "string", "minLength": 1 },
        "nonceBytes": { "const": 12 },
        "salt":       { "type": "string", "minLength": 43 },
        "envelope": {
          "type": "object",
          "additionalProperties": false,
          "required": ["sealedDek", "aad"],
          "properties": {
            "sealedDek": { "type": "string", "minLength": 1 },
            "aad":       { "type": "string", "minLength": 1 }
          }
        }
      }
    },
    "scope": {
      "type": "object",
      "additionalProperties": false,
      "required": [
        "schema", "closedSets", "features", "licenses",
        "rbac", "domain", "secretsEnvelope", "files"
      ],
      "properties": {
        "schema": {
          "type": "object",
          "additionalProperties": false,
          "required": ["contentHash", "migrations"],
          "properties": {
            "contentHash": { "type": "string", "pattern": "^[0-9a-f]{64}$" },
            "migrations":  { "type": "array", "items": { "type": "string" } }
          }
        },
        "closedSets": {
          "type": "object",
          "additionalProperties": false,
          "required": ["contentHash", "setCount", "valueCount"],
          "properties": {
            "contentHash": { "type": "string", "pattern": "^[0-9a-f]{64}$" },
            "setCount":    { "type": "integer", "minimum": 0 },
            "valueCount":  { "type": "integer", "minimum": 0 }
          }
        },
        "features": {
          "type": "object",
          "additionalProperties": false,
          "required": ["contentHash", "featureCount", "defaultCount"],
          "properties": {
            "contentHash":  { "type": "string", "pattern": "^[0-9a-f]{64}$" },
            "featureCount": { "type": "integer", "minimum": 0 },
            "defaultCount": { "type": "integer", "minimum": 0 }
          }
        },
        "licenses": {
          "type": "object",
          "additionalProperties": false,
          "required": ["contentHash", "licenseCount", "epochCount", "featureLinkCount"],
          "properties": {
            "contentHash":      { "type": "string", "pattern": "^[0-9a-f]{64}$" },
            "licenseCount":     { "type": "integer", "minimum": 0 },
            "epochCount":       { "type": "integer", "minimum": 0 },
            "featureLinkCount": { "type": "integer", "minimum": 0 }
          }
        },
        "rbac": {
          "type": "object",
          "additionalProperties": false,
          "required": ["contentHash", "userRoleCount", "casbinRuleCount", "bootstrapPresent"],
          "properties": {
            "contentHash":      { "type": "string", "pattern": "^[0-9a-f]{64}$" },
            "userRoleCount":    { "type": "integer", "minimum": 0 },
            "casbinRuleCount":  { "type": "integer", "minimum": 0 },
            "bootstrapPresent": { "type": "boolean" }
          }
        },
        "domain": {
          "type": "object",
          "additionalProperties": false,
          "required": ["contentHash", "tables"],
          "properties": {
            "contentHash": { "type": "string", "pattern": "^[0-9a-f]{64}$" },
            "tables": {
              "type": "array",
              "items": {
                "type": "object",
                "additionalProperties": false,
                "required": ["name", "rowCount", "contentHash"],
                "properties": {
                  "name":        { "type": "string", "minLength": 1 },
                  "rowCount":    { "type": "integer", "minimum": 0 },
                  "contentHash": { "type": "string", "pattern": "^[0-9a-f]{64}$" }
                }
              }
            }
          }
        },
        "secretsEnvelope": {
          "type": "object",
          "additionalProperties": false,
          "required": ["contentHash", "algorithm", "epoch", "kid"],
          "properties": {
            "contentHash": { "type": "string", "pattern": "^[0-9a-f]{64}$" },
            "algorithm":   { "const": "hkdf-sha256" },
            "epoch":       { "type": "integer", "minimum": 1 },
            "kid":         { "type": "string", "minLength": 1 }
          }
        },
        "files": {
          "type": "object",
          "additionalProperties": false,
          "required": ["contentHash", "objectCount", "totalBytes", "index"],
          "properties": {
            "contentHash": { "type": "string", "pattern": "^[0-9a-f]{64}$" },
            "objectCount": { "type": "integer", "minimum": 0 },
            "totalBytes":  { "type": "integer", "minimum": 0 },
            "index":       { "type": "string", "minLength": 1 }
          }
        }
      }
    },
    "excluded": {
      "type": "array",
      "items": { "type": "string", "minLength": 1 },
      "uniqueItems": true
    },
    "producedBy": {
      "type": "object",
      "additionalProperties": false,
      "required": ["userId", "role", "requestId"],
      "properties": {
        "userId":    { "type": "string", "format": "uuid" },
        "role":      { "enum": ["super_admin", "admin", "operator", "auditor", "user", "deputy"] },
        "requestId": { "type": "string", "minLength": 1 }
      }
    },
    "signature": {
      "type": "object",
      "additionalProperties": false,
      "required": ["algorithm", "kid", "signature"],
      "properties": {
        "algorithm": { "const": "ed25519" },
        "kid":       { "type": "string", "minLength": 1 },
        "signature": { "type": "string", "minLength": 1 }
      }
    }
  }
}
```

---

## Validation Contract

Every Import and Restore MUST run the manifest through this JSON
Schema **before** opening any DB transaction or touching any chunk
body. Validation errors surface as `BackupCorrupt` with
`Attributes.Error.Details.field` pointing at the JSON pointer of the
first failing keyword.

Post-schema validation, the following semantic checks run in order:

1. `contentHash` equals recomputed Merkle root over `chunkIndex.chunks[].sha256` (else `BackupCorrupt`).
2. `chunkIndex.merkleRoot` equals `contentHash`.
3. `scope.domain.tables[].name` is disjoint from `excluded` (else `BackupCorrupt`).
4. `excluded` is a subset of the `EX-A..E` catalogue in `06-scope-exclusions.md` (else `BackupCorrupt`).
5. `appVersion` major equals current app major for Export archives (else `BackupVersionMismatch`); Snapshots additionally require exact `appVersion` match.
6. `producedBy.role` is `super_admin` (else `BackupCorrupt`: only Super Admin may produce archives per `INV-BR-PM-2`).

Every failure logs `lara.exception` with `ErrorId`, `RequestId`, the
JSON pointer, and the failing keyword; `Attributes.Error.Message`
never leaks the offending value.

---

## Invariants (`INV-BR-MS-1..5`)

Promoted into [`04-invariants.md`](./04-invariants.md) on the next
edit of that file.

| ID              | Statement                                                                                                              |
|-----------------|------------------------------------------------------------------------------------------------------------------------|
| `INV-BR-MS-1`   | The manifest validates against the JSON Schema above **before** any DB transaction opens or any chunk body is read.    |
| `INV-BR-MS-2`   | `scope` has exactly eight keys (`SC-A..H` mapping); extra keys and missing keys both fail schema validation.           |
| `INV-BR-MS-3`   | `contentHash` equals `chunkIndex.merkleRoot`; a mismatch is `BackupCorrupt`, never a warning.                          |
| `INV-BR-MS-4`   | `excluded` is a subset of `06-scope-exclusions.md` `EX-A..E`; unknown entries are `BackupCorrupt`.                     |
| `INV-BR-MS-5`   | The manifest's canonical byte form (sorted keys, LF line endings, UTF-8, no BOM) is what `contentHash` covers.         |

---

## Cross-References

- Parent: [`05-scope-catalog.md`](./05-scope-catalog.md) `INV-BR-SC-1` (one slot per class), [`06-scope-exclusions.md`](./06-scope-exclusions.md) `INV-BR-EX-1` (closure).
- Consumed by: `<spec-placeholder file="08-archive-format.md" />` (embeds manifest as first tar entry), `<spec-placeholder file="09-encryption-and-keys.md" />` (populates `encryption`), `<spec-placeholder file="11-endpoint-export.md" />` / `<spec-placeholder file="12-endpoint-import.md" />` / `<spec-placeholder file="13-endpoint-snapshot.md" />` / `<spec-placeholder file="14-endpoint-restore.md" />` (produce/consume the manifest), `<spec-placeholder file="24-testing-matrix.md" />` (fixture-vs-schema conformance).
- Companion: [`04-invariants.md`](./04-invariants.md) next edit promotes `INV-BR-MS-1..5`.
