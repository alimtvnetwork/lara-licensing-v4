<?php

declare(strict_types=1);

namespace App\Services\BR;

use App\Domain\BR\BrArchiveKind;
use App\Domain\BR\BrManifestSchema;
use App\Domain\BR\BrProducedByRole;
use Illuminate\Support\Facades\Log;

/**
 * Plan 14 step 12. Canonical archive manifest builder.
 *
 * Normative source: spec/26-backup-restore/07-manifest-schema.md v1.0.0
 * §"Manifest Location" (canonical UTF-8, sorted keys, LF-terminated) +
 * §"JSON Schema" required fields. This builder produces an in-shadow
 * manifest for the S1 Export path: all class content hashes and the
 * top-level `contentHash` collapse to SHA-256 of the empty byte string
 * (there are no chunk bodies yet). `chunkIndex.merkleRoot` MUST equal
 * `contentHash` to satisfy INV-BR-MS-3; validator asserts the same.
 *
 * The output round-trips `BrManifestValidator::validate` under the
 * current app version, so Step 12 lands a real byte-level artifact
 * (manifest.json inside the sink) that later steps replace field by
 * field as chunk writers, encryption, and scope collectors ship.
 *
 * 15-line function cap enforced by splitting.
 */
final class BrManifestBuilder
{
    private const EMPTY_SHA256 = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';
    private const NULL_UUID  = '00000000-0000-0000-0000-000000000000';
    private const SALT_ZERO_B64URL = 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA';
    private const SEALED_DEK_PLACEHOLDER = 'shadow';
    private const KID_SHADOW = 'epoch-shadow';
    private const MANIFEST_REL_PATH = 'manifest.json';

    /**
     * Build a shadow-mode manifest with an empty chunk list. Retained for
     * back-compat tests only; production shadow writers call
     * {@see buildShadowWithChunks} so `contentHash`/`merkleRoot` reflect
     * real bytes on disk (INV-BR-AF-4, INV-BR-MS-3).
     *
     * @return array<string, mixed>
     */
    public function buildShadow(string $archiveId, string $appVersion, string $userId, string $requestId): array
    {
        return $this->assemble($archiveId, $appVersion, $userId, $requestId, [], self::EMPTY_SHA256);
    }

    /**
     * Build a shadow-mode manifest bound to the real chunk descriptors
     * produced by {@see BrChunkWriter} and the Merkle root produced by
     * {@see BrMerkleRoot}. `contentHash` == `chunkIndex.merkleRoot`
     * satisfies INV-BR-MS-3; the top-level `contentHash` is what
     * `BrManifestValidator::validateContentIntegrity` cross-checks.
     *
     * `$scopeOverrides` lets a shipped collector (Step 14+) replace
     * the default zero-hash / empty-list scope slot with the real
     * hash + row list for its class. Unknown keys are ignored so a
     * partial roll-out of collectors is safe.
     * `$schemaHashOverride`, when non-empty, replaces the top-level
     * `schemaHash` placeholder with the true SC-A hash so the
     * manifest binds to the real schema state at Export time
     * (INV-BR-MS-2).
     *
     * @param  list<array{path:string, sha256:string, bytes:int}>  $chunks
     * @param  array<string, array<string, mixed>>  $scopeOverrides
     * @return array<string, mixed>
     */
    public function buildShadowWithChunks(string $archiveId, string $appVersion, string $userId, string $requestId, array $chunks, string $merkleRootHex, array $scopeOverrides = [], string $schemaHashOverride = '', array $encryptionOverride = []): array
    {
        return $this->assemble($archiveId, $appVersion, $userId, $requestId, $chunks, $merkleRootHex, $scopeOverrides, $schemaHashOverride, $encryptionOverride);
    }

    /**
     * @param  list<array{path:string, sha256:string, bytes:int}>  $chunks
     * @param  array<string, array<string, mixed>>  $scopeOverrides
     * @param  array<string, mixed>  $encryptionOverride
     * @return array<string, mixed>
     */
    private function assemble(string $archiveId, string $appVersion, string $userId, string $requestId, array $chunks, string $merkleRootHex, array $scopeOverrides = [], string $schemaHashOverride = '', array $encryptionOverride = []): array
    {
        $now = gmdate('Y-m-d\TH:i:s\Z');

        return [
            'version'     => BrManifestSchema::VERSION,
            'archiveKind' => BrArchiveKind::Export->value,
            'archiveId'   => $archiveId,
            'createdAt'   => $now,
            'appVersion'  => $appVersion,
            'schemaHash'  => $schemaHashOverride !== '' ? $schemaHashOverride : self::EMPTY_SHA256,
            'contentHash' => $merkleRootHex,
            'chunkIndex'  => $this->chunkIndex($chunks, $merkleRootHex),
            'encryption'  => $encryptionOverride !== [] ? $encryptionOverride : $this->encryption($archiveId),
            'scope'       => $this->scope($scopeOverrides),
            'excluded'    => [],
            'producedBy'  => ['userId' => $userId !== '' ? $userId : self::NULL_UUID, 'role' => BrProducedByRole::SuperAdmin->value, 'requestId' => $requestId],
        ];
    }


    /**
     * Emit canonical UTF-8 bytes (recursively sorted keys, compact,
     * trailing LF) so `contentHash` is stable across writers.
     *
     * @param array<string, mixed> $manifest
     */
    public function canonicalize(array $manifest): string
    {
        $sorted = $this->sortKeys($manifest);
        $json = json_encode($sorted, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        return $json . "\n";
    }

    /**
     * Build + canonicalize + write to `manifest.json` in the reserved sink.
     *
     * When `$chunks` is empty this is a Step-12 shadow write (manifest-only
     * artifact). When `$chunks` is populated it is a Step-13 shadow write
     * bound to real body entries and the caller-supplied Merkle root, so
     * `contentHash`/`chunkIndex.merkleRoot` round-trip
     * `BrManifestValidator::validateContentIntegrity` (INV-BR-MS-3).
     * `$scopeOverrides` and `$schemaHashOverride` let Step-14+ collectors
     * bind real per-class hashes into the manifest as they ship.
     *
     * @param  list<array{path:string, sha256:string, bytes:int}>  $chunks
     * @param  array<string, array<string, mixed>>  $scopeOverrides
     * @return array{Bytes:int, Sha256:string}
     */
    public function writeShadowManifest(BrArchiveStorage $storage, string $archiveId, string $appVersion, string $userId, string $requestId, array $chunks = [], string $merkleRootHex = self::EMPTY_SHA256, array $scopeOverrides = [], string $schemaHashOverride = '', array $encryptionOverride = []): array
    {
        $manifest = $this->assemble($archiveId, $appVersion, $userId, $requestId, $chunks, $merkleRootHex, $scopeOverrides, $schemaHashOverride, $encryptionOverride);
        $bytes = $this->canonicalize($manifest);
        $written = $storage->writeAtomic($archiveId, self::MANIFEST_REL_PATH, $bytes, $requestId);
        $sha = hash('sha256', $bytes);
        Log::info('br.export.manifest.written', ['ArchiveId' => $archiveId, 'Bytes' => $written, 'Sha256' => $sha, 'ChunkCount' => count($chunks), 'MerkleRoot' => $merkleRootHex, 'SchemaHash' => $manifest['schemaHash'], 'ScopeOverrideKeys' => array_keys($scopeOverrides), 'EncryptionSealed' => $encryptionOverride !== [], 'RequestId' => $requestId]);

        return ['Bytes' => $written, 'Sha256' => $sha];
    }

    /**
     * @param  list<array{path:string, sha256:string, bytes:int}>  $chunks
     * @return array<string, mixed>
     */
    private function chunkIndex(array $chunks, string $merkleRootHex): array
    {
        return ['algorithm' => BrManifestSchema::CHUNK_ALG, 'level' => BrManifestSchema::CHUNK_LEVEL, 'chunkSize' => BrManifestSchema::CHUNK_SIZE, 'chunks' => $chunks, 'merkleRoot' => $merkleRootHex];
    }


    /** @return array<string, mixed> */
    private function encryption(string $archiveId): array
    {
        $aad = base64_encode($archiveId . '.' . BrManifestSchema::VERSION);

        return ['algorithm' => BrManifestSchema::ENCRYPTION_ALG, 'kdf' => BrManifestSchema::ENCRYPTION_KDF, 'epoch' => 1, 'kid' => self::KID_SHADOW, 'nonceBytes' => BrManifestSchema::ENCRYPTION_NONCE_BYTES, 'salt' => self::SALT_ZERO_B64URL, 'envelope' => ['sealedDek' => self::SEALED_DEK_PLACEHOLDER, 'aad' => $aad]];
    }

    /**
     * @param  array<string, array<string, mixed>>  $overrides
     * @return array<string, mixed>
     */
    private function scope(array $overrides = []): array
    {
        $defaults = $this->scopeDefaults();
        foreach ($overrides as $key => $slot) {
            if (array_key_exists($key, $defaults)) {
                $defaults[$key] = $slot;
            }
        }

        return $defaults;
    }

    /** @return array<string, array<string, mixed>> */
    private function scopeDefaults(): array
    {
        return [
            'schema'          => ['contentHash' => self::EMPTY_SHA256, 'migrations' => []],
            'closedSets'      => ['contentHash' => self::EMPTY_SHA256, 'setCount' => 0, 'valueCount' => 0],
            'features'        => ['contentHash' => self::EMPTY_SHA256, 'featureCount' => 0, 'defaultCount' => 0],
            'licenses'        => ['contentHash' => self::EMPTY_SHA256, 'licenseCount' => 0, 'epochCount' => 0, 'featureLinkCount' => 0],
            'rbac'            => ['contentHash' => self::EMPTY_SHA256, 'userRoleCount' => 0, 'casbinRuleCount' => 0, 'bootstrapPresent' => false],
            'domain'          => ['contentHash' => self::EMPTY_SHA256, 'tables' => []],
            'secretsEnvelope' => ['contentHash' => self::EMPTY_SHA256, 'algorithm' => BrManifestSchema::ENCRYPTION_KDF, 'epoch' => 1, 'kid' => self::KID_SHADOW],
            'files'           => ['contentHash' => self::EMPTY_SHA256, 'objectCount' => 0, 'totalBytes' => 0, 'index' => 'scope/files/index.jsonl.zst'],
        ];
    }

    /**
     * Recursively sort associative arrays by key. Lists (numeric keys)
     * preserve their order so `chunkIndex.chunks` ordering is retained.
     *
     * @param  mixed  $node
     * @return mixed
     */
    private function sortKeys(mixed $node): mixed
    {
        if (is_array($node) === false) {
            return $node;
        }
        if (array_is_list($node)) {
            return array_map(fn ($v) => $this->sortKeys($v), $node);
        }
        ksort($node);
        foreach ($node as $k => $v) {
            $node[$k] = $this->sortKeys($v);
        }

        return $node;
    }
}
