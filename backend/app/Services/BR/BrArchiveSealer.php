<?php

declare(strict_types=1);

namespace App\Services\BR;

use App\Exceptions\InternalException;


use App\Domain\BR\BrCryptoConstants as C;
use App\Domain\BR\BrManifestSchema;
use App\Exceptions\LaraException;
use Illuminate\Support\Facades\Log;

/**
 * Plan 14 step 22. Archive-level encryption sealer.
 *
 * Normative source: spec/26-backup-restore/09-encryption-and-keys.md
 * v1.0.0 §"Key Hierarchy", §"Nonce Discipline", §"AAD Binding", and
 * §"Sealed DEK". Every scope chunk body (SC-A..H) is wrapped with
 * AES-256-GCM using the archive DEK; the DEK itself is HKDF-derived
 * from `(activeKek, archiveSalt)` and independently wrapped under the
 * KEK so Restore can unseal via either path (INV-BR-EK-1/EK-2/EK-4).
 *
 * Lifecycle:
 *   1. Worker calls `initialize($archiveId, $manifestVersion)` once
 *      per archive, BEFORE any chunk is written. This resolves the
 *      Active KEK epoch, mints a fresh 32-byte salt, derives the
 *      content DEK and AAD secret, and pre-computes the manifest AAD.
 *   2. For each chunk, `sealChunkBody($relPath, $ordinal, $compressed)`
 *      returns `ct || tag` (spec §08 "Chunk Format": zstd-frame body
 *      followed by GCM tag; the deterministic frame nonce is NOT
 *      embedded because it is derivable from `chunkOrdinal || 0`).
 *      Trailer sha256 in `chunks[].sha256` is over the returned bytes.
 *   3. Manifest builder pulls `manifestEncryption()` to fill the
 *      `encryption` slot with real `salt`, `sealedDek`, `aad`, `epoch`,
 *      `kid`.
 *
 * Strict failure model with no swallowing: `sealChunkBody` before
 * `initialize` raises `BackupCorrupt` rule `SealerNotInitialized`;
 * negative ordinals raise `BackupCorrupt` rule `NonceReuseDetected`
 * via `BrCryptoService`. All error paths log with `RequestId` +
 * archive/ordinal context before rethrow.
 *
 * 15-line function cap held by splitting into `deriveMaterial`,
 * `assertReady`, `logInit`.
 */
final class BrArchiveSealer
{
    private const LOG_INIT = 'br.export.sealer.initialized';
    private const LOG_SEAL = 'br.export.sealer.chunk_sealed';
    private const RULE_NOT_INITIALIZED = 'SealerNotInitialized';
    private const B64URL_PAD = '=';
    private const B64URL_FROM = ['+', '/'];
    private const B64URL_TO   = ['-', '_'];
    private const FRAME_ORDINAL = 0; // one AEAD frame per chunk in S1.

    private ?string $archiveId = null;
    private ?string $manifestVersion = null;
    private ?string $salt = null;
    private ?string $dek = null;
    private ?string $aadSecret = null;
    private ?string $manifestAad = null;
    private ?string $sealedDek = null;
    private ?int $epoch = null;
    private ?string $kid = null;

    public function __construct(
        private readonly BrKekService $keks,
        private readonly BrCryptoService $crypto,
    ) {}

    public function initialize(string $archiveId, string $requestId, string $manifestVersion = BrManifestSchema::VERSION): void
    {
        $epochRow = $this->keks->resolveActive();
        $kek = $this->keks->materialFor($epochRow);
        $salt = $this->crypto->newSalt();
        [$dek, $aadSecret] = $this->deriveMaterial($kek, $salt);
        $this->archiveId = $archiveId;
        $this->manifestVersion = $manifestVersion;
        $this->salt = $salt;
        $this->dek = $dek;
        $this->aadSecret = $aadSecret;
        $this->manifestAad = $this->crypto->manifestAad($archiveId, $manifestVersion, $aadSecret);
        $this->sealedDek = $this->crypto->sealDek($kek, $dek, $this->manifestAad);
        $this->epoch = (int) $epochRow['Epoch'];
        $this->kid = (string) $epochRow['Kid'];
        $this->logInit($requestId);
    }

    public function sealChunkBody(string $relPath, int $chunkOrdinal, string $compressed, string $requestId): string
    {
        $this->assertReady($relPath);
        $nonce = $this->crypto->frameNonce($chunkOrdinal, self::FRAME_ORDINAL);
        $aad = $this->crypto->frameAad((string) $this->archiveId, (string) $this->manifestVersion, $relPath, $chunkOrdinal, self::FRAME_ORDINAL, (string) $this->aadSecret);
        $sealed = $this->crypto->encryptFrame((string) $this->dek, $compressed, $nonce, $aad);
        Log::info(self::LOG_SEAL, ['ArchiveId' => $this->archiveId, 'Path' => $relPath, 'ChunkOrdinal' => $chunkOrdinal, 'PlainBytes' => strlen($compressed), 'SealedBytes' => strlen($sealed), 'RequestId' => $requestId]);

        return $sealed;
    }

    /** @return array{algorithm:string, kdf:string, epoch:int, kid:string, nonceBytes:int, salt:string, envelope:array{sealedDek:string, aad:string}} */
    public function manifestEncryption(): array
    {
        $this->assertReady('/encryption');

        return [
            'algorithm' => C::ALGORITHM,
            'kdf'       => C::KDF,
            'epoch'     => (int) $this->epoch,
            'kid'       => (string) $this->kid,
            'nonceBytes' => C::FRAME_NONCE_LEN,
            'salt'      => $this->b64url((string) $this->salt),
            'envelope'  => [
                'sealedDek' => $this->b64url((string) $this->sealedDek),
                'aad'       => $this->b64url((string) $this->manifestAad),
            ],
        ];
    }

    /** @return array{0:string,1:string} */
    private function deriveMaterial(string $kek, string $salt): array
    {
        $dek = $this->crypto->deriveDekContent($kek, $salt);
        $aadSecret = $this->crypto->deriveAadSecret($kek, $salt);

        return [$dek, $aadSecret];
    }

    private function assertReady(string $pointer): void
    {
        if ($this->dek === null || $this->aadSecret === null || $this->salt === null) {
            throw InternalException::custom(C::ERR_BACKUP_CORRUPT, 'archive sealer not initialized', [
                ['Field' => $pointer, 'Rule' => self::RULE_NOT_INITIALIZED, 'Value' => null],
            ]);
        }
    }

    private function logInit(string $requestId): void
    {
        Log::info(self::LOG_INIT, [
            'ArchiveId' => $this->archiveId, 'Epoch' => $this->epoch, 'Kid' => $this->kid,
            'ManifestVersion' => $this->manifestVersion, 'SaltBytes' => strlen((string) $this->salt),
            'SealedDekBytes' => strlen((string) $this->sealedDek), 'RequestId' => $requestId,
        ]);
    }

    private function b64url(string $raw): string
    {
        return rtrim(str_replace(self::B64URL_FROM, self::B64URL_TO, base64_encode($raw)), self::B64URL_PAD);
    }
}
