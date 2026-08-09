<?php

declare(strict_types=1);

namespace App\Services\BR;

use App\Exceptions\InternalException;


use App\Domain\BR\BrCryptoConstants as C;
use App\Exceptions\LaraException;
use Illuminate\Support\Facades\Log;

/**
 * Plan 14 step 8. AES-256-GCM + HKDF-SHA-256 primitives for BR
 * archives. Every method maps to one clause of spec 26 §09
 * ("Encryption and Keys") v1.0.0.
 *
 *  - `newDek()` / `newSalt()`: CSPRNG bytes (spec §09 "Key Hierarchy":
 *    32-byte salt per archive, ephemeral 32-byte DEK).
 *  - `deriveDekContent()` / `deriveAadSecret()`: HKDF-SHA-256 with
 *    labelled info strings (INV-BR-EK-2).
 *  - `frameNonce()`: deterministic 12-byte `u32:chunkOrdinal ||
 *    u64:frameOrdinal` big-endian (INV-BR-EK-3, spec §09 "Nonce
 *    Discipline").
 *  - `manifestAad()` / `frameAad()`: HMAC-SHA-256 truncated to 16 bytes
 *    (spec §09 "AAD Binding", INV-BR-EK-4).
 *  - `sealDek()` / `unsealDek()`: AES-256-GCM wrap of the 32-byte DEK,
 *    layout `nonce(12) || ct(32) || tag(16) = 60 bytes` (spec §09
 *    "Sealed DEK").
 *  - `encryptFrame()` / `decryptFrame()`: AES-256-GCM per zstd frame;
 *    failures surface `BackupCorrupt` with the JSON pointer required
 *    by spec §09 "Failure Modes".
 *
 * All function bodies capped at 15 lines. Zero magic literals: every
 * label, length, and pointer lives in BrCryptoConstants.
 */
final class BrCryptoService
{
    public function newDek(): string
    {
        return random_bytes(C::DEK_LEN);
    }

    public function newSalt(): string
    {
        return random_bytes(C::SALT_LEN);
    }

    public function deriveDekContent(string $kek, string $salt): string
    {
        $this->assertKek($kek);

        return hash_hkdf(C::HASH_ALGO, $kek, C::DEK_LEN, C::HKDF_INFO_DEK, $salt);
    }

    public function deriveAadSecret(string $kek, string $salt): string
    {
        $this->assertKek($kek);

        return hash_hkdf(C::HASH_ALGO, $kek, C::AAD_SECRET_LEN, C::HKDF_INFO_AAD, $salt);
    }

    public function frameNonce(int $chunkOrdinal, int $frameOrdinal): string
    {
        if ($chunkOrdinal < 0 || $frameOrdinal < 0) {
            throw $this->corrupt(C::PTR_CHUNK_ORDINAL . $chunkOrdinal, C::RULE_NONCE_REUSE, 'negative ordinal');
        }

        return pack('N', $chunkOrdinal) . pack('J', $frameOrdinal);
    }

    public function manifestAad(string $archiveId, string $manifestVersion, string $aadSecret): string
    {
        $msg = $archiveId . C::AAD_SEPARATOR . $manifestVersion;
        $full = hash_hmac(C::HASH_ALGO, $msg, $aadSecret, true);

        return substr($full, 0, C::AAD_TRUNCATE_LEN);
    }

    public function frameAad(
        string $archiveId,
        string $manifestVersion,
        string $chunkPath,
        int $chunkOrdinal,
        int $frameOrdinal,
        string $aadSecret
    ): string {
        $sep = C::AAD_SEPARATOR;
        $msg = $archiveId . $sep . $manifestVersion . $sep . $chunkPath
             . $sep . (string) $chunkOrdinal . $sep . (string) $frameOrdinal;

        return substr(hash_hmac(C::HASH_ALGO, $msg, $aadSecret, true), 0, C::AAD_TRUNCATE_LEN);
    }

    public function sealDek(string $kek, string $dek, string $manifestAad): string
    {
        $this->assertKek($kek);
        if (strlen($dek) !== C::DEK_LEN) {
            throw $this->corrupt(C::PTR_SEALED_DEK, C::RULE_SEALED_DEK_LEN, 'dek length');
        }
        $nonce = random_bytes(C::DEK_WRAP_NONCE_LEN);
        $tag = '';
        $ct = openssl_encrypt($dek, C::OPENSSL_CIPHER, $kek, OPENSSL_RAW_DATA, $nonce, $tag, $manifestAad, C::GCM_TAG_LEN);
        if ($ct === false || strlen($tag) !== C::GCM_TAG_LEN) {
            throw $this->corrupt(C::PTR_SEALED_DEK, C::RULE_DEK_UNSEAL, 'seal failed');
        }

        return $nonce . $ct . $tag;
    }

    public function unsealDek(string $kek, string $sealed, string $manifestAad): string
    {
        $this->assertKek($kek);
        if (strlen($sealed) !== C::SEALED_DEK_LEN) {
            throw $this->corrupt(C::PTR_SEALED_DEK, C::RULE_SEALED_DEK_LEN, (string) strlen($sealed));
        }
        [$nonce, $ct, $tag] = $this->splitSealed($sealed);
        $pt = openssl_decrypt($ct, C::OPENSSL_CIPHER, $kek, OPENSSL_RAW_DATA, $nonce, $tag, $manifestAad);
        if ($pt === false || strlen($pt) !== C::DEK_LEN) {
            Log::warning('br.crypto.dek_unseal_failed', ['Rule' => C::RULE_DEK_UNSEAL]);
            throw $this->corrupt(C::PTR_SEALED_DEK, C::RULE_DEK_UNSEAL, 'tag');
        }

        return $pt;
    }

    /** @return array{0:string,1:string,2:string} */
    private function splitSealed(string $sealed): array
    {
        $nonce = substr($sealed, 0, C::DEK_WRAP_NONCE_LEN);
        $ct    = substr($sealed, C::DEK_WRAP_NONCE_LEN, C::DEK_LEN);
        $tag   = substr($sealed, C::DEK_WRAP_NONCE_LEN + C::DEK_LEN, C::GCM_TAG_LEN);

        return [$nonce, $ct, $tag];
    }

    public function encryptFrame(string $dek, string $plaintext, string $nonce, string $aad): string
    {
        $tag = '';
        $ct = openssl_encrypt($plaintext, C::OPENSSL_CIPHER, $dek, OPENSSL_RAW_DATA, $nonce, $tag, $aad, C::GCM_TAG_LEN);
        if ($ct === false || strlen($tag) !== C::GCM_TAG_LEN) {
            throw $this->corrupt(C::PTR_CHUNK_ORDINAL . '?', C::RULE_FRAME_TAG, 'encrypt');
        }

        return $ct . $tag;
    }

    public function decryptFrame(string $dek, string $frame, string $nonce, string $aad, int $chunkOrdinal): string
    {
        if (strlen($frame) < C::GCM_TAG_LEN) {
            throw $this->corrupt(C::PTR_CHUNK_ORDINAL . $chunkOrdinal, C::RULE_FRAME_TAG, 'length');
        }
        $ct  = substr($frame, 0, -C::GCM_TAG_LEN);
        $tag = substr($frame, -C::GCM_TAG_LEN);
        $pt = openssl_decrypt($ct, C::OPENSSL_CIPHER, $dek, OPENSSL_RAW_DATA, $nonce, $tag, $aad);
        if ($pt === false) {
            Log::warning('br.crypto.frame_tag_failed', ['ChunkOrdinal' => $chunkOrdinal]);
            throw $this->corrupt(C::PTR_CHUNK_ORDINAL . $chunkOrdinal, C::RULE_FRAME_TAG, 'tag');
        }

        return $pt;
    }

    private function assertKek(string $kek): void
    {
        if (strlen($kek) !== C::KEK_LEN) {
            throw $this->corrupt(C::PTR_EPOCH, C::RULE_KEK_LEN, (string) strlen($kek));
        }
    }

    private function corrupt(string $pointer, string $rule, string $value): LaraException
    {
        return InternalException::custom(C::ERR_BACKUP_CORRUPT,
            'crypto operation failed',
            [['Field' => $pointer, 'Rule' => $rule, 'Value' => $value]]
        );
    }
}
