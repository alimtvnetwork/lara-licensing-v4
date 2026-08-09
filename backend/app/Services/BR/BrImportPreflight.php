<?php

declare(strict_types=1);

namespace App\Services\BR;

use App\Exceptions\InternalException;


use App\Domain\BR\BrCryptoConstants as C;
use App\Domain\BR\BrManifestSchema;
use App\Exceptions\LaraException;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Plan 14 step 23. Read-only Import preflight.
 *
 * Normative source: spec/26-backup-restore/12-restore-orchestration.md
 * v1.0.0 §"Preflight" + INV-BR-RS-1 ("preflight never mutates any
 * table") + INV-BR-RS-2 ("preflight verifies manifest, trailers,
 * chunk sha, and DEK unseal BEFORE any DB tx opens"). Reads a
 * finalized archive (or a shadow one under the feature flag) that
 * was produced by {@see BrExportWorker} + {@see BrArchiveSealer}
 * and returns a per-scope report.
 *
 * Pipeline (strict order, halts on first failure):
 *   1. Load + canonicalize `manifest.json`, validate via
 *      {@see BrManifestValidator} (spec §07 Validation Contract).
 *   2. Resolve KEK by manifest `encryption.epoch`
 *      ({@see BrKekService::resolveForUnseal}) and unseal the DEK
 *      via {@see BrCryptoService::unsealDek} using the manifest AAD
 *      rebuilt from `archiveId || manifestVersion` under the
 *      HKDF-derived AAD secret.
 *   3. For each `chunkIndex.chunks[]` entry (tar-entry order),
 *      call {@see BrArchiveReader::readChunk} which enforces
 *      trailer, sha256, byte-count, GCM tag, and returns
 *      decompressed plaintext.
 *   4. Report per-scope `contentHash` from the manifest AND the
 *      sha256 of the decompressed body per scope path; any
 *      mismatch surfaces `BackupCorrupt` rule `ScopeContentDrift`
 *      pointing at `/scope/<key>/contentHash`.
 *
 * The preflight NEVER opens a DB transaction, NEVER touches shard
 * connections, and NEVER writes anywhere. It is safe to run on any
 * archive at any time. All failure paths log
 * `br.import.preflight.rejected` with `RequestId`, `ArchiveId`,
 * `Rule` and re-throw. Success logs
 * `br.import.preflight.verified` with per-scope byte counts.
 *
 * 15-line function cap held via helper splits.
 */
final class BrImportPreflight
{
    private const ERR_CORRUPT = C::ERR_BACKUP_CORRUPT;
    private const RULE_DRIFT = 'ScopeContentDrift';
    private const RULE_MANIFEST_UNREADABLE = 'ManifestUnreadable';
    private const RULE_ENVELOPE_SHAPE = 'EncryptionEnvelopeShape';
    private const LOG_OK = 'br.import.preflight.verified';
    private const LOG_FAIL = 'br.import.preflight.rejected';
    private const MANIFEST_REL_PATH = 'manifest.json';
    private const PTR_SCOPE = '/scope/';
    private const PTR_MANIFEST = '/manifest.json';
    private const SCOPE_PATH_MAP = [
        'scope/schema.jsonl.zst'          => 'schema',
        'scope/closed-sets.jsonl.zst'     => 'closedSets',
        'scope/features.jsonl.zst'        => 'features',
        'scope/licenses.jsonl.zst'        => 'licenses',
        'scope/rbac.jsonl.zst'            => 'rbac',
        'scope/secrets-envelope.bin.zst'  => 'secretsEnvelope',
        'scope/files/index.jsonl.zst'     => 'files',
    ];

    public function __construct(
        private readonly BrArchiveStorage $storage,
        private readonly BrManifestValidator $validator,
        private readonly BrArchiveReader $reader,
        private readonly BrKekService $keks,
        private readonly BrCryptoService $crypto,
    ) {}

    /**
     * @return array{
     *   ArchiveId:string, ManifestSha256:string, ChunkCount:int,
     *   EncryptionEpoch:int, EncryptionKid:string,
     *   Chunks: list<array{Path:string, Ordinal:int, SealedBytes:int, PlainBytes:int, Sha256:string, PlainSha256:string}>,
     *   Scopes: array<string, array{ContentHash:string, ActualHash:string, Ok:bool, PlainBytes:int}>
     * }
     */
    public function run(string $archiveId, string $currentAppVersion, string $requestId): array
    {
        try {
            $manifest = $this->loadManifest($archiveId, $requestId);
            $this->validator->validate($manifest, $currentAppVersion, $requestId);
            [$dek, $aadSecret] = $this->unsealDek($archiveId, $manifest, $requestId);
            $report = $this->verifyChunks($archiveId, $manifest, $dek, $aadSecret, $requestId);
            $report['ArchiveId'] = $archiveId;
            $report['ManifestSha256'] = hash('sha256', $this->readManifestBytes($archiveId));
            Log::info(self::LOG_OK, ['ArchiveId' => $archiveId, 'ChunkCount' => $report['ChunkCount'], 'RequestId' => $requestId]);

            return $report;
        } catch (LaraException $e) {
            Log::warning(self::LOG_FAIL, ['ArchiveId' => $archiveId, 'Code' => $e->getMessage(), 'RequestId' => $requestId]);
            throw $e;
        }
    }

    /** @return array<string, mixed> */
    private function loadManifest(string $archiveId, string $requestId): array
    {
        $bytes = $this->readManifestBytes($archiveId);
        try {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($bytes, true, 64, JSON_THROW_ON_ERROR);

            return $decoded;
        } catch (Throwable $e) {
            Log::warning(self::LOG_FAIL, ['ArchiveId' => $archiveId, 'Rule' => self::RULE_MANIFEST_UNREADABLE, 'Reason' => $e->getMessage(), 'RequestId' => $requestId]);
            throw InternalException::custom(self::ERR_CORRUPT, 'manifest.json is not valid JSON', [
                ['Field' => self::PTR_MANIFEST, 'Rule' => self::RULE_MANIFEST_UNREADABLE, 'Value' => null],
            ]);
        }
    }

    private function readManifestBytes(string $archiveId): string
    {
        $abs = $this->storage->path($archiveId) . DIRECTORY_SEPARATOR . self::MANIFEST_REL_PATH;
        if (is_file($abs) === false) {
            throw InternalException::custom(self::ERR_CORRUPT, 'manifest.json missing', [
                ['Field' => self::PTR_MANIFEST, 'Rule' => self::RULE_MANIFEST_UNREADABLE, 'Value' => null],
            ]);
        }

        return (string) file_get_contents($abs);
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return array{0:string,1:string}
     */
    private function unsealDek(string $archiveId, array $manifest, string $requestId): array
    {
        $enc = $manifest['encryption'] ?? [];
        $envelope = is_array($enc) ? ($enc['envelope'] ?? []) : [];
        if (! is_array($enc) || ! is_array($envelope) || ! isset($enc['epoch'], $enc['salt'], $envelope['sealedDek'])) {
            throw InternalException::custom(self::ERR_CORRUPT, 'encryption envelope missing', [
                ['Field' => C::PTR_SEALED_DEK, 'Rule' => self::RULE_ENVELOPE_SHAPE, 'Value' => null],
            ]);
        }
        $epochRow = $this->keks->resolveForUnseal((int) $enc['epoch']);
        $kek = $this->keks->materialFor($epochRow);
        $salt = $this->b64urlDecode((string) $enc['salt']);
        $aadSecret = $this->crypto->deriveAadSecret($kek, $salt);
        $manifestAad = $this->crypto->manifestAad($archiveId, (string) $manifest['version'], $aadSecret);
        $sealedDek = $this->b64urlDecode((string) $envelope['sealedDek']);
        $dek = $this->crypto->unsealDek($kek, $sealedDek, $manifestAad);
        Log::info('br.import.preflight.dek_unsealed', ['ArchiveId' => $archiveId, 'Epoch' => (int) $enc['epoch'], 'Kid' => (string) ($enc['kid'] ?? ''), 'RequestId' => $requestId]);

        return [$dek, $aadSecret];
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return array{ChunkCount:int, EncryptionEpoch:int, EncryptionKid:string, Chunks:list<array<string,mixed>>, Scopes:array<string,array<string,mixed>>}
     */
    private function verifyChunks(string $archiveId, array $manifest, string $dek, string $aadSecret, string $requestId): array
    {
        $chunks = (array) ($manifest['chunkIndex']['chunks'] ?? []);
        $manifestVersion = (string) $manifest['version'];
        $scopeManifest = (array) ($manifest['scope'] ?? []);
        $chunkReport = [];
        $scopeReport = [];
        foreach (array_values($chunks) as $ordinal => $descriptor) {
            /** @var array{path:string, sha256:string, bytes:int} $descriptor */
            $plain = $this->reader->readChunk($archiveId, $descriptor, (int) $ordinal, $dek, $manifestVersion, $aadSecret, $requestId);
            $chunkReport[] = ['Path' => $descriptor['path'], 'Ordinal' => (int) $ordinal, 'SealedBytes' => (int) $descriptor['bytes'], 'PlainBytes' => strlen($plain), 'Sha256' => (string) $descriptor['sha256'], 'PlainSha256' => hash('sha256', $plain)];
            $this->recordScope($descriptor['path'], $plain, $scopeManifest, $scopeReport);
        }
        $enc = (array) $manifest['encryption'];

        return ['ChunkCount' => count($chunkReport), 'EncryptionEpoch' => (int) $enc['epoch'], 'EncryptionKid' => (string) ($enc['kid'] ?? ''), 'Chunks' => $chunkReport, 'Scopes' => $scopeReport];
    }

    /**
     * @param  array<string, mixed>  $scopeManifest
     * @param  array<string, array<string, mixed>>  $scopeReport
     */
    private function recordScope(string $relPath, string $plain, array $scopeManifest, array &$scopeReport): void
    {
        $key = self::SCOPE_PATH_MAP[$relPath] ?? null;
        if ($key === null) {
            return;
        }
        $slot = (array) ($scopeManifest[$key] ?? []);
        $declared = (string) ($slot['contentHash'] ?? '');
        $actual = hash('sha256', $plain);
        $ok = hash_equals($declared, $actual);
        $scopeReport[$key] = ['ContentHash' => $declared, 'ActualHash' => $actual, 'Ok' => $ok, 'PlainBytes' => strlen($plain)];
        $isFailed = !$ok;
        if ($isFailed) {
            throw InternalException::custom(self::ERR_CORRUPT, "scope content hash drift at {$key}", [
                ['Field' => self::PTR_SCOPE . $key . '/contentHash', 'Rule' => self::RULE_DRIFT, 'Value' => $actual],
            ]);
        }
    }

    private function b64urlDecode(string $s): string
    {
        $pad = strlen($s) % 4;
        $s = strtr($s, '-_', '+/') . ($pad ? str_repeat('=', 4 - $pad) : '');
        $raw = base64_decode($s, true);
        if ($raw === false) {
            throw InternalException::custom(self::ERR_CORRUPT, 'b64url decode failed', [
                ['Field' => C::PTR_SEALED_DEK, 'Rule' => self::RULE_ENVELOPE_SHAPE, 'Value' => null],
            ]);
        }

        return $raw;
    }

    public function manifestSchemaVersion(): string
    {
        return BrManifestSchema::VERSION;
    }
}
