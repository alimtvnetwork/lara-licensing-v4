# Archive Format

**Version:** 1.0.0
**Updated:** 2026-07-20
**AI Confidence:** Draft
**Ambiguity:** Low

---

## Keywords

`archive` · `tar` · `zstd` · `chunk` · `merkle` · `streaming` · `worker-runtime` · `content-hash`

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

Pin the byte-level container that carries the manifest
([`07-manifest-schema.md`](./07-manifest-schema.md)) plus every
scope-class body (`SC-A..H`) from
[`05-scope-catalog.md`](./05-scope-catalog.md). This is the
serialization contract every Export writer and every Import reader
must produce/consume identically; a mismatch is `BackupCorrupt`
before any DB transaction opens (`INV-BR-MS-1`).

The format is optimised for streaming on the Cloudflare Worker
runtime (see `<useful-context>` server-runtime): no arbitrary
filesystem, no `child_process`, only `stream` + `crypto` + `zlib`
plus a WASM zstd codec. Nothing is buffered fully in memory.

---

## Container Choice: `tar` + per-chunk `zstd`

`tar` (POSIX ustar, no PAX extensions) as the outer container; each
tar entry body is either raw JSON (manifest) or a zstd-compressed
JSONL / binary chunk. **Not** a single `.tar.zst` stream: per-entry
compression lets readers seek to any chunk without decompressing
predecessors, which is required for resumable Restore (`SC-H`
per-object boundary) and Range-served downloads.

Rationale in one paragraph: a whole-archive `.tar.zst` would force
sequential decompression, defeating `SC-H` per-object streaming and
breaking resumable Restore; a zip container would need central-
directory rewrites that are hostile to streaming writers; raw tar
with per-entry zstd is O(1) memory for writers, O(1) memory per
chunk for readers, and every entry is content-addressed by
SHA-256 over its compressed bytes so the Merkle tree is
constructible in one pass.

---

## Entry Order (normative)

The tar stream MUST contain entries in exactly this order. Readers
enforce order; out-of-order entries fail with `BackupCorrupt` and
JSON pointer `chunkIndex.chunks[<n>].path`.

```
manifest.json                              (uncompressed, always first)
scope/schema.jsonl.zst                     (SC-A)
scope/closed-sets.jsonl.zst                (SC-B)
scope/features.jsonl.zst                   (SC-C)
scope/licenses.jsonl.zst                   (SC-D)
scope/rbac.jsonl.zst                       (SC-E)
scope/domain/<table>.jsonl.zst             (SC-F, alphabetical by table name)
scope/secrets-envelope.bin.zst             (SC-G, sealed blob)
scope/files/index.jsonl.zst                (SC-H index: {sha256, bucket, path, bytes})
scope/files/<sha256[0:2]>/<sha256>.bin.zst (SC-H bodies, sharded by first byte)
merkle.json                                (uncompressed, always last)
```

- `manifest.json` first so a reader validates the manifest against
  [`07-manifest-schema.md`](./07-manifest-schema.md) before spending
  any budget on chunk bodies. Uncompressed by rule so validation is
  possible even if the zstd codec is unavailable at boot.
- `merkle.json` last so writers can finalise the Merkle root only
  after every chunk's SHA-256 is known. It duplicates
  `manifest.chunkIndex.merkleRoot`; readers cross-check both and
  reject on mismatch.
- Per-table `SC-F` entries are alphabetical so archive comparison
  is deterministic and `contentHash` reproduces on rewrite.

---

## Chunk Format

Every chunk (any `.zst` entry) is framed identically:

```
+------------------+------------------------+------------------+
| zstd frame       | ... zstd frame N       | trailer (32 B)   |
+------------------+------------------------+------------------+
```

- Uncompressed payload for `.jsonl.zst`: newline-delimited JSON,
  UTF-8, one row per line, LF terminators, no trailing blank line.
- Uncompressed payload for `.bin.zst`: raw binary body of a single
  `SC-H` object (pre-encryption is out; per
  [`09-encryption-and-keys.md`](<spec-placeholder file="09-encryption-and-keys.md" />)
  the AES-GCM ciphertext is what gets zstd'd).
- `chunkSize` from `manifest.chunkIndex.chunkSize` (default 8 MiB,
  min 1 MiB, max 64 MiB) bounds the uncompressed payload per zstd
  frame; large `SC-H` bodies span multiple frames within the same
  tar entry, letting readers stream frame-by-frame.
- Trailer (32 bytes): SHA-256 of the compressed bytes of the entry
  (identical to `manifest.chunkIndex.chunks[].sha256`). Present in
  the trailer so a truncated stream fails fast without a manifest
  round-trip.

---

## Merkle Tree

Construction is a binary tree over `manifest.chunkIndex.chunks`
in tar-entry order:

- Leaves: SHA-256 of each chunk's compressed bytes (equal to the
  trailer and to `chunks[].sha256`).
- Internal nodes: `SHA-256(left || right)`.
- Odd leaf at any level: duplicated (Bitcoin-style).
- Root: `manifest.contentHash` == `manifest.chunkIndex.merkleRoot`
  == top-level entry in `merkle.json`.

Verification is O(chunks) with O(1) memory: a reader hashes each
chunk as it streams, folds the running Merkle stack, and compares
the final root against the manifest. Any mismatch at the leaf,
internal, or root level surfaces `BackupCorrupt` with
`Attributes.Error.Details.chunkPath` pointing at the failing entry.

---

## Streaming Write Contract

Export writers:

1. Allocate a `Transform` stream chain: source rows -> JSONL encoder -> `crypto.createHash('sha256')` (uncompressed hash, informational only) -> zstd encoder -> `crypto.createHash('sha256')` (compressed hash, canonical) -> tar packer.
2. Emit `manifest.json` first as a placeholder with `contentHash: null` and `chunkIndex.chunks: []`.
3. For each `SC-*` class in the order above, stream rows into a new tar entry, capture the compressed SHA-256 from the second hasher, and push `{ path, sha256, bytes }` into the in-memory `chunks[]`.
4. After the last body entry, fold the Merkle tree, patch the manifest with the final `contentHash`, `chunkIndex.chunks`, and `chunkIndex.merkleRoot`.
5. Rewrite `manifest.json` in-place at the tar stream's byte offset captured in step 2. `merkle.json` is appended as the final entry.

Writers MUST NOT buffer bodies in memory; the only in-memory state is `chunks[]` (bounded, ~120 B per chunk) and the Merkle stack (O(log chunks)).

---

## Streaming Read Contract

Import/Restore readers:

1. Read `manifest.json` (must be the first tar entry). Validate against `07-manifest-schema.md`; on failure emit `BackupCorrupt` and stop before any DB tx.
2. Stream each subsequent entry. Hash compressed bytes; compare against the trailer and against `manifest.chunkIndex.chunks[i].sha256`.
3. Fold the running Merkle stack; compare the final root against `manifest.contentHash` at end-of-stream.
4. Decode zstd frame-by-frame; feed uncompressed rows to the class-specific applier (transactional per class for `SC-A..G`, per-object for `SC-H`).
5. Missing or extra entries relative to `chunkIndex.chunks[]` fail with `BackupCorrupt`.

Readers MUST fail closed: any hash mismatch, missing entry, or Merkle mismatch aborts the Restore with the DB transaction rolled back and `lara.exception` logged with `ErrorId`, `RequestId`, and the failing entry's `path`.

---

## Worker-Runtime Constraints

- Bundled zstd codec: WASM build (e.g. `@bokuweb/zstd-wasm` or equivalent) chosen at step 26 dependency review. No native `zstd` binary, no `child_process`.
- tar: pure-JS packer/parser (streaming). No `tar` CLI.
- Buffers: every stream uses `TransformStream` or Node `stream.Transform`; `Buffer.concat` on class bodies is banned.
- `/tmp` is virtual and per-invocation; writers MUST target the response stream or object storage directly, never a temp file.
- Range requests: object-storage backends serve archive downloads with `Accept-Ranges: bytes`; per-entry compression makes chunk-scoped Range serving well-defined (a client can resume at any tar entry header offset).

---

## Invariants (`INV-BR-AF-1..6`)

Promoted into [`04-invariants.md`](./04-invariants.md) on the next
edit of that file.

| ID              | Statement                                                                                                              |
|-----------------|------------------------------------------------------------------------------------------------------------------------|
| `INV-BR-AF-1`   | The first tar entry is always `manifest.json`, uncompressed; the last is always `merkle.json`, uncompressed.           |
| `INV-BR-AF-2`   | Per-entry zstd only; no whole-archive `.tar.zst`. A reader must be able to seek to any chunk without decompressing predecessors. |
| `INV-BR-AF-3`   | Every `.zst` entry ends with a 32-byte SHA-256 trailer over its compressed bytes, equal to `chunks[].sha256`.          |
| `INV-BR-AF-4`   | `manifest.contentHash` equals the Merkle root of `chunks[].sha256` in tar-entry order (odd leaves duplicated).         |
| `INV-BR-AF-5`   | `SC-F` domain-table entries appear in alphabetical order by table name; deviation is `BackupCorrupt`.                  |
| `INV-BR-AF-6`   | Writers never buffer class bodies in memory; readers never load an entire chunk body before hashing.                   |

---

## Cross-References

- Parent: [`07-manifest-schema.md`](./07-manifest-schema.md) (`chunkIndex`, `contentHash`), [`05-scope-catalog.md`](./05-scope-catalog.md) (class enumeration and restore order).
- Consumed by: `<spec-placeholder file="09-encryption-and-keys.md" />` (per-chunk AES-GCM wraps the zstd frames), `<spec-placeholder file="11-endpoint-export.md" />` / `<spec-placeholder file="12-endpoint-import.md" />` / `<spec-placeholder file="13-endpoint-snapshot.md" />` / `<spec-placeholder file="14-endpoint-restore.md" />` (stream producers/consumers), `<spec-placeholder file="15-jobs-and-progress.md" />` (per-chunk progress ticks), `<spec-placeholder file="24-testing-matrix.md" />` (byte-reproducible fixtures).
- Companion: [`04-invariants.md`](./04-invariants.md) next edit promotes `INV-BR-AF-1..6`.
