<?php

declare(strict_types=1);

namespace App\Services\BR;

use App\Exceptions\InternalException;


use App\Domain\BR\BrCryptoConstants as C;
use App\Domain\BR\BrManifestSchema;
use App\Exceptions\LaraException;
use Illuminate\Support\Facades\Log;

/**
 * Plan 14 step 23. Read-only archive chunk reader used by Import
 * preflight ({@see BrImportPreflight}).
 *
 * Normative source: spec/26-backup-restore/08-archive-format.md
 * v1.0.0 §"Chunk Format" (`compressed || 32-byte SHA-256 trailer`) +
 * §09 "Nonce Discipline" / "AAD Binding". Every returned frame goes
 * through THREE checks in strict order so a corrupt archive never
 * reaches Restore's mutation stage (INV-BR-AF-3, INV-BR-EK-1):
 *
 *   1. On-disk trailer equals SHA-256 of the body bytes.
 *   2. Body SHA-256 equals the manifest `chunks[].sha256` value.
 *   3. When a sealed archive is opened, the body is AEAD-decrypted
 *      via `BrCryptoService::decryptFrame` against the manifest AAD
 *      binding; failure surfaces `BackupCorrupt` with the offending
 *      chunk ordinal so operators see WHERE the tag failed.
 *
 * The reader NEVER mutates any table, opens any DB transaction, or
 * touches storage outside the passed archive id. All failure paths
 * log `br.import.reader.rejected` with `RequestId`, `Path`, `Rule`
 * and re-throw. 15-line function cap held via helper splits.
 */
final class BrArchiveReader
{
    private const TRAILER_BYTES = 32;
    private const ERR_CORRUPT = C::ERR_BACKUP_CORRUPT;
    private const RULE_MISSING = 'ChunkFileMissing';
    private const RULE_TOO_SHORT = 'ChunkFileTooShort';
    private const RULE_TRAILER = 'ChunkTrailerMismatch';
    private const RULE_SHA = 'ChunkSha256Mismatch';
    private const RULE_BYTES = 'ChunkByteCountMismatch';
    private const PTR = '/chunkIndex/chunks/';
    private const LOG_OK = 'br.import.reader.verified';
    private const LOG_FAIL = 'br.import.reader.rejected';

    public function __construct(
        private readonly BrArchiveStorage $storage,
        private readonly BrZstd $zstd,
        private readonly BrCryptoService $crypto,
    ) {}

    /**
     * Verify the on-disk chunk descriptor and return decompressed
     * plaintext bytes. When `$dek` is null the chunk is treated as
     * plaintext-compressed (unsealed archives are only produced by
     * pre-encryption shadow writes and are gated by feature flag).
     *
     * @param  array{path:string, sha256:string, bytes:int}  $descriptor
     */
    public function readChunk(string $archiveId, array $descriptor, int $ordinal, ?string $dek, ?string $manifestVersion, ?string $aadSecret, string $requestId): string
    {
        $relPath = $descriptor['path'];
        $onDisk = $this->loadRaw($archiveId, $relPath, $requestId);
        [$body, $trailer] = $this->split($relPath, $onDisk);
        $this->verifyTrailer($relPath, $body, $trailer);
        $this->verifySha($relPath, $body, (string) $descriptor['sha256']);
        $this->verifyBytes($relPath, $body, (int) $descriptor['bytes']);
        $compressed = $dek !== null ? $this->decrypt($archiveId, $relPath, $ordinal, $body, $dek, (string) $manifestVersion, (string) $aadSecret) : $body;
        $plain = $this->zstd->decompress($compressed);
        Log::info(self::LOG_OK, ['ArchiveId' => $archiveId, 'Path' => $relPath, 'Ordinal' => $ordinal, 'Bytes' => strlen($body), 'PlainBytes' => strlen($plain), 'Sealed' => $dek !== null, 'RequestId' => $requestId]);

        return $plain;
    }

    private function loadRaw(string $archiveId, string $relPath, string $requestId): string
    {
        $abs = $this->storage->path($archiveId) . DIRECTORY_SEPARATOR . $relPath;
        if (is_file($abs) === false) {
            $this->reject($relPath, self::RULE_MISSING, $requestId);
        }
        $raw = (string) @file_get_contents($abs);
        if (strlen($raw) < self::TRAILER_BYTES + 1) {
            $this->reject($relPath, self::RULE_TOO_SHORT, $requestId);
        }

        return $raw;
    }

    /** @return array{0:string,1:string} */
    private function split(string $relPath, string $raw): array
    {
        $body = substr($raw, 0, -self::TRAILER_BYTES);
        $trailer = substr($raw, -self::TRAILER_BYTES);

        return [$body, $trailer];
    }

    private function verifyTrailer(string $relPath, string $body, string $trailer): void
    {
        $expected = hash('sha256', $body, true);
        if (hash_equals($expected, $trailer) === false) {
            $this->reject($relPath, self::RULE_TRAILER, '');
        }
    }

    private function verifySha(string $relPath, string $body, string $manifestSha): void
    {
        $actual = hash('sha256', $body);
        if (hash_equals($manifestSha, $actual) === false) {
            $this->reject($relPath, self::RULE_SHA, '');
        }
    }

    private function verifyBytes(string $relPath, string $body, int $expected): void
    {
        if (strlen($body) !== $expected) {
            $this->reject($relPath, self::RULE_BYTES, '');
        }
    }

    private function decrypt(string $archiveId, string $relPath, int $ordinal, string $body, string $dek, string $manifestVersion, string $aadSecret): string
    {
        $nonce = $this->crypto->frameNonce($ordinal, 0);
        $aad = $this->crypto->frameAad($archiveId, $manifestVersion, $relPath, $ordinal, 0, $aadSecret);

        return $this->crypto->decryptFrame($dek, $body, $nonce, $aad, $ordinal);
    }

    private function reject(string $relPath, string $rule, string $requestId): never
    {
        Log::warning(self::LOG_FAIL, ['Path' => $relPath, 'Rule' => $rule, 'RequestId' => $requestId]);
        throw InternalException::custom(self::ERR_CORRUPT, "archive chunk rejected: {$rule}", [
            ['Field' => self::PTR . $relPath, 'Rule' => $rule, 'Value' => null],
        ]);
    }

    /** Public helper so preflight can pin the expected manifest version. */
    public function manifestVersion(): string
    {
        return BrManifestSchema::VERSION;
    }
}
