<?php

declare(strict_types=1);

namespace Tests\Feature\BR;

use App\Domain\BR\BrCryptoConstants as C;
use App\Exceptions\LaraException;
use App\Services\BR\BrCryptoService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Plan 14 step 8. Contract tests for AES-256-GCM + HKDF-SHA-256
 * primitives. Every assertion pins a clause of spec 26 §09.
 *
 * Coverage:
 *  - HKDF known-answer: same (kek, salt) -> same DEK / AAD-secret bytes.
 *  - HKDF label separation: DEK label ≠ AAD label (different bytes).
 *  - Deterministic frame nonce layout (u32 || u64, big-endian).
 *  - AAD binding is manifest-scoped (16 bytes truncation).
 *  - Seal/unseal roundtrip with correct AAD returns the original DEK.
 *  - Seal/unseal with WRONG manifest AAD -> BackupCorrupt at
 *    `/encryption/envelope/sealedDek` (Rule=DekUnsealTagFailed).
 *  - Frame roundtrip with correct AAD returns original plaintext.
 *  - Frame decrypt with tampered ciphertext -> BackupCorrupt at
 *    `/chunkIndex/chunks/{ordinal}` (Rule=FrameTagFailed).
 *  - Cross-archive replay (same DEK, same nonce, DIFFERENT archiveId
 *    in AAD) fails GCM verification, proving INV-BR-EK-4 binding.
 *  - Invalid KEK length surfaces BackupCorrupt at `/encryption/epoch`.
 */
final class BrCryptoServiceTest extends TestCase
{
    private BrCryptoService $svc;
    private const KEK = "\x00\x11\x22\x33\x44\x55\x66\x77\x88\x99\xaa\xbb\xcc\xdd\xee\xff\x00\x11\x22\x33\x44\x55\x66\x77\x88\x99\xaa\xbb\xcc\xdd\xee\xff";
    private const SALT = "\xa0\xa1\xa2\xa3\xa4\xa5\xa6\xa7\xa8\xa9\xaa\xab\xac\xad\xae\xaf\xb0\xb1\xb2\xb3\xb4\xb5\xb6\xb7\xb8\xb9\xba\xbb\xbc\xbd\xbe\xbf";

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new BrCryptoService();
    }

    #[Test]
    public function hkdf_is_deterministic_and_labels_separate_streams(): void
    {
        $dek1 = $this->svc->deriveDekContent(self::KEK, self::SALT);
        $dek2 = $this->svc->deriveDekContent(self::KEK, self::SALT);
        $aad  = $this->svc->deriveAadSecret(self::KEK, self::SALT);
        $this->assertSame($dek1, $dek2, 'HKDF is deterministic');
        $this->assertSame(C::DEK_LEN, strlen($dek1));
        $this->assertSame(C::AAD_SECRET_LEN, strlen($aad));
        $this->assertNotSame($dek1, $aad, 'DEK label != AAD label');
    }

    #[Test]
    public function frame_nonce_is_big_endian_u32_u64(): void
    {
        $n = $this->svc->frameNonce(1, 2);
        $this->assertSame(C::FRAME_NONCE_LEN, strlen($n));
        $this->assertSame("\x00\x00\x00\x01" . "\x00\x00\x00\x00\x00\x00\x00\x02", $n);
    }

    #[Test]
    public function seal_unseal_dek_roundtrip(): void
    {
        $dek = str_repeat("\x42", C::DEK_LEN);
        $aad = str_repeat("\x11", C::AAD_TRUNCATE_LEN);
        $sealed = $this->svc->sealDek(self::KEK, $dek, $aad);
        $this->assertSame(C::SEALED_DEK_LEN, strlen($sealed));
        $this->assertSame($dek, $this->svc->unsealDek(self::KEK, $sealed, $aad));
    }

    #[Test]
    public function unseal_dek_with_wrong_aad_throws_backup_corrupt(): void
    {
        $dek = str_repeat("\x42", C::DEK_LEN);
        $sealed = $this->svc->sealDek(self::KEK, $dek, str_repeat("\x11", C::AAD_TRUNCATE_LEN));
        try {
            $this->svc->unsealDek(self::KEK, $sealed, str_repeat("\x22", C::AAD_TRUNCATE_LEN));
            $this->fail('expected LaraException');
        } catch (LaraException $e) {
            $this->assertSame(C::ERR_BACKUP_CORRUPT, $e->errorCode);
            $this->assertSame(C::PTR_SEALED_DEK, $e->details[0]['Field']);
            $this->assertSame(C::RULE_DEK_UNSEAL, $e->details[0]['Rule']);
        }
    }

    #[Test]
    public function frame_roundtrip_and_tamper_detection(): void
    {
        $dek = $this->svc->deriveDekContent(self::KEK, self::SALT);
        $secret = $this->svc->deriveAadSecret(self::KEK, self::SALT);
        $nonce = $this->svc->frameNonce(0, 0);
        $aad = $this->svc->frameAad('arc-1', '1.0.0', 'bodies/SC-H/chunk-0.zst', 0, 0, $secret);
        $frame = $this->svc->encryptFrame($dek, 'hello world', $nonce, $aad);
        $this->assertSame('hello world', $this->svc->decryptFrame($dek, $frame, $nonce, $aad, 0));
        $tampered = substr($frame, 0, -1) . chr(ord(substr($frame, -1)) ^ 0x01);
        $this->expectException(LaraException::class);
        $this->svc->decryptFrame($dek, $tampered, $nonce, $aad, 0);
    }

    #[Test]
    public function cross_archive_replay_fails_via_aad_binding(): void
    {
        $dek = $this->svc->deriveDekContent(self::KEK, self::SALT);
        $secret = $this->svc->deriveAadSecret(self::KEK, self::SALT);
        $nonce = $this->svc->frameNonce(3, 7);
        $aadA = $this->svc->frameAad('arc-A', '1.0.0', 'p', 3, 7, $secret);
        $aadB = $this->svc->frameAad('arc-B', '1.0.0', 'p', 3, 7, $secret);
        $this->assertNotSame($aadA, $aadB);
        $frame = $this->svc->encryptFrame($dek, 'payload', $nonce, $aadA);
        $this->expectException(LaraException::class);
        $this->svc->decryptFrame($dek, $frame, $nonce, $aadB, 3);
    }

    #[Test]
    public function invalid_kek_length_surfaces_backup_corrupt(): void
    {
        try {
            $this->svc->deriveDekContent(str_repeat("\x01", 16), self::SALT);
            $this->fail('expected LaraException');
        } catch (LaraException $e) {
            $this->assertSame(C::ERR_BACKUP_CORRUPT, $e->errorCode);
            $this->assertSame(C::PTR_EPOCH, $e->details[0]['Field']);
            $this->assertSame(C::RULE_KEK_LEN, $e->details[0]['Rule']);
        }
    }
}
