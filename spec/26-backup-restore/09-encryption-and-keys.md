# Encryption and Keys

**Version:** 1.0.0
**Updated:** 2026-07-20
**AI Confidence:** Draft
**Ambiguity:** Low

---

## Keywords

`encryption` · `aes-256-gcm` · `hkdf-sha256` · `envelope` · `dek` · `kek` · `epoch` · `kid` · `aad` · `forward-secrecy` · `re-seal` · `nonce`

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

Pin the cryptographic contract that wraps every archive body chunk
produced by [`08-archive-format.md`](./08-archive-format.md) and
populates the `encryption` block reserved in
[`07-manifest-schema.md`](./07-manifest-schema.md) at lines 120-137
and lines 200-221. Without this file the algorithm choice, key
hierarchy, AAD binding, nonce discipline, and re-seal timing are
implicit, so every endpoint (11-14) would re-derive crypto and
`INV-BR-C` (forward secrecy) would have no enforcement point.

The contract is Worker-runtime feasible: WebCrypto (`crypto.subtle`)
supplies AES-GCM-256 and HKDF-SHA-256 natively; no OpenSSL binding,
no native module, no `/tmp` scratch space.

---

## Algorithm Choice

| Layer                | Primitive         | Rationale                                                                 |
|----------------------|-------------------|---------------------------------------------------------------------------|
| Content encryption   | AES-256-GCM       | AEAD, hardware-accelerated in workerd/V8, 12-byte nonce, 16-byte tag.     |
| Key derivation       | HKDF-SHA-256      | Salted extract + labelled expand, deterministic sub-keys from one KEK.    |
| Key wrapping         | AES-256-GCM       | The DEK is sealed with the epoch KEK using AES-GCM, not AES-KW, to avoid an extra primitive on the Worker runtime. |
| Integrity of ciphertext | GCM tag        | Complements the SHA-256 trailer over compressed bytes from `INV-BR-AF-3`. |

Non-choices, explicitly rejected:

- No AES-CBC, no AES-CTR (no built-in integrity).
- No ChaCha20-Poly1305 (fine cryptographically but AES-GCM has hardware acceleration on workerd and matches every downstream language stdlib without an extra dep).
- No RSA (asymmetric wrap not needed; every Restore is server-local and has KEK access).
- No PBKDF2 (KEKs come from `SecretsProvider`, never from passwords).

---

## Key Hierarchy

```
Root KEK (per epoch, held by SecretsProvider, never touches disk in cleartext)
    |
    +-- HKDF-SHA-256(salt = manifest.encryption.salt, info = "lara/backup/v1/dek")
    |         |
    |         +--> DEK-content (32 B): encrypts every zstd frame in every SC-* chunk
    |
    +-- HKDF-SHA-256(salt = manifest.encryption.salt, info = "lara/backup/v1/aad")
    |         |
    |         +--> AAD-secret (32 B): HMAC key for per-chunk AAD (below)
    |
    +-- AES-256-GCM wrap of DEK-content
              |
              +--> manifest.encryption.envelope.sealedDek
```

- The Root KEK is identified by `manifest.encryption.epoch` (monotonic integer) and `manifest.encryption.kid` (opaque string, e.g. `epoch-7-2026-07`).
- `salt` is 32 random bytes per archive (`base64url`, 43 chars) so two archives produced at the same epoch derive independent DEKs.
- DEK-content is ephemeral: generated once per archive, sealed into the manifest, and zeroised from memory after the archive is finalised.

---

## Nonce Discipline

Per-frame nonces are constructed deterministically to avoid any RNG dependency inside the streaming hot path:

```
nonce (12 B) = chunkOrdinal (u32, big-endian) || frameOrdinal (u64, big-endian)
```

- `chunkOrdinal` is the zero-based index into `manifest.chunkIndex.chunks[]`.
- `frameOrdinal` is the zero-based zstd-frame index inside that chunk.
- Uniqueness proof: within one DEK-content, `(chunkOrdinal, frameOrdinal)` pairs are unique because `chunks[]` order is normative (`INV-BR-AF-5`) and frames within a chunk are strictly appended.
- No random nonces: eliminates entropy dependency during streaming and makes archives byte-reproducible for fixture tests (`24-testing-matrix.md`).

---

## AAD Binding

Every AES-GCM frame's Associated Additional Data binds the frame to its manifest and chunk:

```
aad = HMAC-SHA-256(AAD-secret,
    manifest.archiveId || "|" ||
    manifest.version   || "|" ||
    chunks[i].path     || "|" ||
    chunkOrdinal.toString(10) || "|" ||
    frameOrdinal.toString(10))   // truncated to first 16 bytes
```

- A ciphertext frame copied to a different archive, different chunk path, or different frame position fails GCM verification and surfaces `BackupCorrupt`.
- `manifest.encryption.envelope.aad` stores the manifest-level AAD used to seal the DEK: `HMAC-SHA-256(AAD-secret, manifest.archiveId || "|" || manifest.version)` (base64url, 43 chars). This is the value already reserved in `07-manifest-schema.md` line 134.

---

## Sealed DEK

The sealed DEK is stored in `manifest.encryption.envelope.sealedDek` (base64url) with layout:

```
sealedDek = nonce (12 B) || ciphertext (32 B) || tag (16 B)
```

- Nonce for the DEK wrap is 12 random bytes (RNG dependency at archive creation time only, not per frame).
- Ciphertext is the 32-byte DEK-content encrypted under the epoch KEK with AAD = `manifest.encryption.envelope.aad`.
- Total sealed size: 60 bytes; base64url-encoded length 80 chars.

Unsealing is the first step of Restore, before any body decryption: unseal DEK-content -> derive AAD-secret via HKDF -> stream chunks.

---

## Re-Seal on Restore (`INV-BR-C`)

Restore MUST re-seal `SC-G` (secrets envelope) and MUST re-seal the archive's `sealedDek` under the current epoch KEK before persisting anything to storage backends. Sequence:

1. Read manifest, validate schema (`INV-BR-MS-1`).
2. If `manifest.encryption.epoch < currentEpoch`: unseal DEK with the historical KEK identified by `kid`, re-seal with the current epoch KEK, patch the runtime manifest copy (the on-disk archive is not rewritten; the re-seal lives on the restored `SC-G` slot only).
3. Restore SC-A through SC-F under the derived DEK-content.
4. Restore SC-G with re-sealed secrets rows (each secret row re-encrypted under the current epoch KEK before insert).
5. Restore SC-H bodies (encrypted at rest under the current epoch KEK; the archive's DEK-content is discarded once every body has been decrypted, re-encrypted, and persisted).

Historical KEKs are read-only after their epoch rolls; they exist to unseal old archives, never to seal new ones.

---

## Failure Modes

Every crypto failure surfaces `BackupCorrupt` with `Attributes.Error.Details` carrying a JSON pointer and no key material:

| Trigger                                            | JSON pointer                                      | Details                          |
|----------------------------------------------------|---------------------------------------------------|----------------------------------|
| Unknown `algorithm` / `kdf`                        | `/encryption/algorithm` or `/encryption/kdf`      | fail before any unseal           |
| Missing epoch KEK for `epoch`/`kid`                | `/encryption/epoch`                               | KEK not resolvable via SecretsProvider |
| GCM tag failure on DEK unseal                      | `/encryption/envelope/sealedDek`                  | fail before touching any chunk   |
| GCM tag failure on any frame                       | `/chunkIndex/chunks/{ordinal}`                    | rollback DB tx, discard SC-H writes |
| AAD mismatch (frame copied across archives)        | `/chunkIndex/chunks/{ordinal}`                    | GCM tag will already fail; AAD is inside the tag input |
| Nonce reuse detected within one DEK-content        | `/chunkIndex/chunks/{ordinal}`                    | fail archive write at seal time  |

`ErrorId`, `RequestId`, and the JSON pointer land in `lara.exception` with `redactor.crypto` stripping every base64 field before log emission.

---

## Invariants (`INV-BR-EK-1..7`)

Promoted into [`04-invariants.md`](./04-invariants.md) on the next edit of that file.

| ID              | Statement                                                                                                                          |
|-----------------|------------------------------------------------------------------------------------------------------------------------------------|
| `INV-BR-EK-1`   | Every archive body frame is encrypted with AES-256-GCM under a per-archive DEK; no cleartext body chunk is ever written to storage.|
| `INV-BR-EK-2`   | DEK-content is derived via HKDF-SHA-256 from the epoch KEK identified by `manifest.encryption.epoch`; the DEK never appears on disk in cleartext. |
| `INV-BR-EK-3`   | Per-frame nonces are deterministic (`chunkOrdinal || frameOrdinal`) and unique within one DEK-content; RNG is used only for the archive `salt` and the DEK-wrap nonce. |
| `INV-BR-EK-4`   | AAD for every frame binds `archiveId`, `manifest.version`, `chunks[i].path`, `chunkOrdinal`, `frameOrdinal`; cross-archive copy fails GCM verification. |
| `INV-BR-EK-5`   | Restore re-seals the DEK and SC-G bodies under the current epoch KEK before any storage-backend write; historical KEKs are read-only. |
| `INV-BR-EK-6`   | Every crypto failure surfaces `BackupCorrupt` with a JSON pointer; key material and ciphertext are redacted from logs by `redactor.crypto`. |
| `INV-BR-EK-7`   | Algorithm and KDF are fixed constants in the manifest (`aes-256-gcm`, `hkdf-sha256`); any other value fails schema validation before unseal. |

---

## Cross-References

- Parent: [`07-manifest-schema.md`](./07-manifest-schema.md) (`encryption` shape at lines 120-137, JSON Schema at lines 200-221), [`08-archive-format.md`](./08-archive-format.md) (per-frame framing wrapped by GCM).
- Consumed by: `<spec-placeholder file="10-secrets-forward-secrecy.md" />` (SC-G re-seal timing, epoch bump policy), `<spec-placeholder file="11-endpoint-export.md" />` / `<spec-placeholder file="12-endpoint-import.md" />` / `<spec-placeholder file="13-endpoint-snapshot.md" />` / `<spec-placeholder file="14-endpoint-restore.md" />` (produce/consume encrypted archives), `<spec-placeholder file="17-audit-and-observability.md" />` (`redactor.crypto` contract), `<spec-placeholder file="24-testing-matrix.md" />` (byte-reproducible fixture tests rely on deterministic nonces).
- Companion: [`04-invariants.md`](./04-invariants.md) next edit promotes `INV-BR-EK-1..7`.
