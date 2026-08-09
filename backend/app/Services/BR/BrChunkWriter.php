<?php

declare(strict_types=1);

namespace App\Services\BR;

use App\Exceptions\LaraException;
use App\Exceptions\AuthException;
use App\Exceptions\ValidationException;
use App\Exceptions\RateLimitException;
use App\Exceptions\NotFoundException;
use App\Exceptions\DomainConflictException;
use App\Exceptions\InternalException;
use Illuminate\Support\Facades\Log;

/**
 * Plan 14 step 13. S1 shadow-Export chunk writer.
 *
 * Spec 26 §08 "Entry Order" (normative) pins the tar-entry order and
 * per-entry framing (`zstd frame(s) || 32-byte SHA-256 trailer over
 * the compressed bytes`). In S1 shadow every SC-A..H class body is
 * empty (no rows, no files), so this writer emits the exact seven
 * chunks required by INV-BR-AF-1 (manifest first, merkle last, all
 * scope entries in between) with empty payloads and lets the Merkle
 * roll-up ({@see BrMerkleRoot}) produce a real `contentHash` for the
 * manifest. Domain SC-F chunks are absent (empty `tables[]`) and
 * SC-H bodies are absent (empty `index.jsonl.zst`) per spec §08
 * "Entry Order" (alphabetical `<table>` list, sharded `<sha256>`
 * bodies).
 *
 * Each entry is written via {@see BrArchiveStorage::writeAtomic} so
 * INV-BR-AF-3 (32-byte trailer equals `chunks[].sha256`) survives a
 * crash + retry: a partial scratch file is discarded and the promoted
 * on-disk file always carries `compressed || trailer` atomically.
 *
 * `bytes` in the returned chunk descriptor is the compressed-body
 * byte count (excluding the 32-byte trailer) so `chunks[].sha256`
 * hashes exactly `bytes` bytes; readers pull the trailer as an
 * independent tail check per spec §08 "Chunk Format".
 *
 * 15-line function cap held by splitting into `writeOne` and
 * `chunkDescriptor`.
 */
final class BrChunkWriter
{
    private const TRAILER_BYTES = 32;
    private const LOG_WROTE  = 'br.export.chunk.written';
    private const LOG_DONE   = 'br.export.chunks.finalized';
    private const LOG_FAILED = 'br.export.chunk.failed';


    private const PATH_SCHEMA          = 'scope/schema.jsonl.zst';
    private const PATH_CLOSED_SETS     = 'scope/closed-sets.jsonl.zst';
    private const PATH_FEATURES        = 'scope/features.jsonl.zst';
    private const PATH_LICENSES        = 'scope/licenses.jsonl.zst';
    private const PATH_RBAC            = 'scope/rbac.jsonl.zst';
    private const PATH_SECRETS         = 'scope/secrets-envelope.bin.zst';
    private const PATH_FILES_INDEX     = 'scope/files/index.jsonl.zst';

    /** Canonical S1 shadow entry order (subset of spec §08 "Entry Order"). */
    private const SHADOW_ORDER = [
        self::PATH_SCHEMA, self::PATH_CLOSED_SETS, self::PATH_FEATURES,
        self::PATH_LICENSES, self::PATH_RBAC, self::PATH_SECRETS,
        self::PATH_FILES_INDEX,
    ];

    /** Per-spec §08 domain chunks sit between RBAC and SECRETS (alphabetical). */
    private const DOMAIN_INSERT_AFTER = self::PATH_RBAC;

    public function __construct(private readonly BrZstd $zstd) {}

    /**
     * Write the S1 shadow chunks and return the descriptor list ready
     * to drop into `manifest.chunkIndex.chunks[]`.
     *
     * `$bodies` is a map of `relPath => payload` for scope collectors
     * that have shipped (Step 14+); unlisted fixed paths default to
     * empty payloads. `$domainRelPaths` supplies the SC-F per-table
     * entries (Step 19) inserted between RBAC and SECRETS in
     * alphabetical order (spec 26 §08 INV-BR-AF-5).
     *
     * @param  array<string,string>  $bodies
     * @param  list<string>  $domainRelPaths
     * @return list<array{path:string, sha256:string, bytes:int}>
     */
    public function writeShadowChunks(BrArchiveStorage $storage, string $archiveId, string $requestId, array $bodies = [], array $domainRelPaths = [], ?BrArchiveSealer $sealer = null): array
    {
        $order = $this->composeOrder($domainRelPaths);
        $descriptors = [];
        foreach ($order as $ordinal => $relPath) {
            $payload = $bodies[$relPath] ?? '';
            $descriptors[] = $this->writeOne($storage, $archiveId, $relPath, $payload, $ordinal, $sealer, $requestId);
        }
        Log::info(self::LOG_DONE, ['ArchiveId' => $archiveId, 'Count' => count($descriptors), 'PopulatedPaths' => array_keys($bodies), 'DomainCount' => count($domainRelPaths), 'Sealed' => $sealer !== null, 'RequestId' => $requestId]);

        return $descriptors;
    }

    /**
     * @param  list<string>  $domainRelPaths
     * @return list<string>
     */
    private function composeOrder(array $domainRelPaths): array
    {
        $domain = $domainRelPaths;
        sort($domain, SORT_STRING);
        $out = [];
        foreach (self::SHADOW_ORDER as $path) {
            $out[] = $path;
            if ($path === self::DOMAIN_INSERT_AFTER) {
                foreach ($domain as $d) {
                    $out[] = $d;
                }
            }
        }

        return $out;
    }

    /**
     * Compress `payload`, optionally AEAD-seal via `$sealer`, append a
     * 32-byte SHA-256 trailer over the on-disk body, and persist via
     * the atomic sink. `chunks[].sha256` hashes the ENCRYPTED body when
     * a sealer is provided so Restore's trailer check runs against the
     * bytes on disk (spec §08 "Chunk Format" + INV-BR-EK-1).
     *
     * Each stage (`compress`, `seal`, `persist`) is guarded so any
     * throwable surfaces a structured `br.export.chunk.failed` line
     * carrying Ordinal, Path, Stage, PlainSize, and (when known) the
     * post-compression byte count + SHA-256, before being rethrown as
     * a `LaraException` mapped to `BackupStorageFailure`. Without the
     * guard, a zstd or filesystem crash surfaced only as a raw stack
     * trace and worker logs could not tell which chunk failed.
     *
     * @return array{path:string, sha256:string, bytes:int}
     */
    private function writeOne(BrArchiveStorage $storage, string $archiveId, string $relPath, string $payload, int $ordinal, ?BrArchiveSealer $sealer, string $requestId): array
    {
        $plainSize = strlen($payload);
        $compressed = $this->compressStage($archiveId, $relPath, $ordinal, $plainSize, $payload, $requestId);
        $body = $this->sealStage($archiveId, $relPath, $ordinal, $plainSize, $compressed, $sealer, $requestId);
        $sha256 = hash('sha256', $body);
        $trailer = $this->trailerBytes($archiveId, $relPath, $ordinal, $plainSize, $sha256, $requestId);
        $this->persistStage($storage, $archiveId, $relPath, $ordinal, $plainSize, $body . $trailer, $sha256, $requestId);
        Log::info(self::LOG_WROTE, ['ArchiveId' => $archiveId, 'Path' => $relPath, 'Ordinal' => $ordinal, 'Bytes' => strlen($body), 'PlainBytes' => strlen($compressed), 'PlainSize' => $plainSize, 'Sha256' => $sha256, 'Sealed' => $sealer !== null, 'RequestId' => $requestId]);

        return ['path' => $relPath, 'sha256' => $sha256, 'bytes' => strlen($body)];
    }

    private function compressStage(string $archiveId, string $relPath, int $ordinal, int $plainSize, string $payload, string $requestId): string
    {
        try {
            return $this->zstd->compress($payload);
        } catch (\Throwable $e) {
            $this->logFailure($archiveId, $relPath, $ordinal, 'compress', $plainSize, null, null, $e, $requestId);
            throw $this->wrap($relPath, 'ZstdCompressFailed', $e);
        }
    }

    private function sealStage(string $archiveId, string $relPath, int $ordinal, int $plainSize, string $compressed, ?BrArchiveSealer $sealer, string $requestId): string
    {
        if ($sealer === null) {
            return $compressed;
        }
        try {
            return $sealer->sealChunkBody($relPath, $ordinal, $compressed, $requestId);
        } catch (\Throwable $e) {
            $this->logFailure($archiveId, $relPath, $ordinal, 'seal', $plainSize, strlen($compressed), null, $e, $requestId);
            throw $this->wrap($relPath, 'ChunkSealFailed', $e);
        }
    }

    private function trailerBytes(string $archiveId, string $relPath, int $ordinal, int $plainSize, string $sha256, string $requestId): string
    {
        $trailer = (string) hex2bin($sha256);
        if (strlen($trailer) === self::TRAILER_BYTES) {
            return $trailer;
        }
        $this->logFailure($archiveId, $relPath, $ordinal, 'trailer', $plainSize, null, $sha256, null, $requestId);
        throw InternalException::serverError('BackupStorageFailure', 'SHA-256 trailer size invariant broken.', [
            ['Field' => '/chunkIndex/chunks/' . $relPath, 'Rule' => 'TrailerLengthMismatch'],
        ]);
    }

    private function persistStage(BrArchiveStorage $storage, string $archiveId, string $relPath, int $ordinal, int $plainSize, string $bytes, string $sha256, string $requestId): void
    {
        try {
            $storage->writeAtomic($archiveId, $relPath, $bytes, $requestId);
        } catch (\Throwable $e) {
            $this->logFailure($archiveId, $relPath, $ordinal, 'persist', $plainSize, strlen($bytes) - self::TRAILER_BYTES, $sha256, $e, $requestId);
            throw $this->wrap($relPath, 'ChunkPersistFailed', $e);
        }
    }

    private function logFailure(string $archiveId, string $relPath, int $ordinal, string $stage, int $plainSize, ?int $bytes, ?string $sha256, ?\Throwable $e, string $requestId): void
    {
        Log::error(self::LOG_FAILED, [
            'ArchiveId' => $archiveId, 'Path' => $relPath, 'Ordinal' => $ordinal,
            'Stage' => $stage, 'PlainSize' => $plainSize, 'Bytes' => $bytes,
            'Sha256' => $sha256, 'RequestId' => $requestId,
            'ErrorClass' => $e !== null ? get_class($e) : null,
            'ErrorMessage' => $e?->getMessage(),
        ]);
    }

    private function wrap(string $relPath, string $rule, \Throwable $e): LaraException
    {
        if ($e instanceof LaraException) {
            return $e;
        }

        return InternalException::serverError('BackupStorageFailure', 'Chunk write failed: ' . $e->getMessage(), [
            ['Field' => '/chunkIndex/chunks/' . $relPath, 'Rule' => $rule],
        ]);
    }
}
