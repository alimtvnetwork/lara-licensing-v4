<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

/**
 * Plan 06 step 46. Ed25519 detached-signature signer for self-update
 * binaries per spec/21-app/17-self-update-endpoint.md v1.3.0
 * §"Signature verification".
 *
 * Root cause this class exists (one sentence): the client MUST verify a
 * pinned Ed25519 signature over the raw asset bytes before rename-first-
 * deploy (spec MUST-abort row A8, AC-SU-ABORT-003), and until now the
 * server never produced that signature so every conforming CLI would
 * reject every published binary (or operate in checksum-only mode with
 * no upgrade path to signature-required).
 *
 * Contract:
 *  - Reads the raw 64-byte libsodium Ed25519 secret key from
 *    `config('lara.self_update.signing_key_path')`. If the config is
 *    empty or the file is unreadable, `isConfigured()` returns false and
 *    publish silently falls back to checksum-only mode (spec §"Signature
 *    verification": "optional but pinned when enabled"). The mode is a
 *    server-side deployment property, never a per-request flag.
 *  - `signFile()` writes `<StoragePath>.sig` containing exactly 64
 *    signature bytes (libsodium detached signature length) and returns
 *    the tuple {SignatureStoragePath, SignatureSha256} for persistence.
 *  - Any hard error (unreadable key, wrong key length, write failure) is
 *    surfaced as `RuntimeException` so the publish transaction aborts
 *    and the caller sees a 5xx envelope. Never swallowed.
 */
final class AssetSigner
{
    private const SIG_SUFFIX = '.sig';
    private const ED25519_SECRET_KEY_BYTES = 64;

    public function isConfigured(): bool
    {
        $path = (string) config('lara.self_update.signing_key_path', '');

        return $path !== '' && is_readable($path);
    }

    /**
     * @return array{SignatureStoragePath:string,SignatureSha256:string}
     */
    public function signFile(string $assetPath): array
    {
        if (is_readable($assetPath) === false) {
            throw new RuntimeException(sprintf('Asset path is not readable for signing: %s', $assetPath));
        }
        $key = $this->loadKey();
        $bytes = file_get_contents($assetPath);
        if ($bytes === false) {
            throw new RuntimeException(sprintf('Failed to read asset bytes for signing: %s', $assetPath));
        }
        $signature = sodium_crypto_sign_detached($bytes, $key);
        $sigPath = $assetPath . self::SIG_SUFFIX;
        if (file_put_contents($sigPath, $signature, LOCK_EX) === false) {
            throw new RuntimeException(sprintf('Failed to write signature file: %s', $sigPath));
        }

        return [
            'SignatureStoragePath' => $sigPath,
            'SignatureSha256' => hash('sha256', $signature),
        ];
    }

    private function loadKey(): string
    {
        $path = (string) config('lara.self_update.signing_key_path', '');
        if ($path === '' || !is_readable($path)) {
            throw new RuntimeException('Signing key path is not configured or unreadable.');
        }
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new RuntimeException('Failed to read signing key file.');
        }
        $key = trim($raw);
        // Accept either raw 64-byte binary or base64 (pem-less) encoding.
        if (strlen($key) !== self::ED25519_SECRET_KEY_BYTES) {
            $decoded = base64_decode($key, true);
            if ($decoded === false || strlen($decoded) !== self::ED25519_SECRET_KEY_BYTES) {
                throw new RuntimeException('Signing key is not a 64-byte libsodium Ed25519 secret key (raw or base64).');
            }
            $key = $decoded;
        }

        return $key;
    }
}
