<?php

declare(strict_types=1);

namespace App\Domain\BR;

/**
 * Plan 14 step 8. Crypto constants pinned to spec 26 §09
 * (Encryption and Keys) v1.0.0. No magic strings anywhere in
 * BrCryptoService / BrKekService: every algorithm name, HKDF label,
 * byte length, and AAD truncation width is declared here so a spec
 * drift surfaces as a config-diff, not a runtime footgun.
 *
 * Cross-refs:
 *  - Algorithm: spec 26 §09 "Algorithm Choice" (AES-256-GCM + HKDF-SHA-256).
 *  - Key hierarchy / HKDF info labels: spec 26 §09 "Key Hierarchy".
 *  - Nonce discipline: spec 26 §09 "Nonce Discipline" (12 B big-endian
 *    `chunkOrdinal:u32 || frameOrdinal:u64`).
 *  - AAD binding + truncation to 16 bytes: spec 26 §09 "AAD Binding".
 *  - Sealed DEK layout (12 || 32 || 16 = 60 bytes): spec 26 §09
 *    "Sealed DEK".
 *  - Invariants: INV-BR-EK-1..7.
 */
final class BrCryptoConstants
{
    // Algorithm identifiers matching manifest.encryption.{algorithm,kdf}.
    public const ALGORITHM     = 'aes-256-gcm';
    public const KDF           = 'hkdf-sha256';
    public const OPENSSL_CIPHER = 'aes-256-gcm';
    public const HASH_ALGO     = 'sha256';

    // HKDF info labels (spec §09 "Key Hierarchy").
    public const HKDF_INFO_DEK = 'lara/backup/v1/dek';
    public const HKDF_INFO_AAD = 'lara/backup/v1/aad';

    // Byte lengths.
    public const KEK_LEN         = 32;
    public const DEK_LEN         = 32;
    public const AAD_SECRET_LEN  = 32;
    public const SALT_LEN        = 32;
    public const FRAME_NONCE_LEN = 12;
    public const DEK_WRAP_NONCE_LEN = 12;
    public const GCM_TAG_LEN     = 16;
    public const AAD_TRUNCATE_LEN = 16;
    public const SEALED_DEK_LEN  = 60; // 12 nonce + 32 ct + 16 tag

    // AAD separator (spec §09 "AAD Binding" pseudocode uses `"|"`).
    public const AAD_SEPARATOR = '|';

    // JSON pointers used in BackupCorrupt details (spec §09 "Failure Modes").
    public const PTR_ALGORITHM       = '/encryption/algorithm';
    public const PTR_KDF             = '/encryption/kdf';
    public const PTR_EPOCH           = '/encryption/epoch';
    public const PTR_SEALED_DEK      = '/encryption/envelope/sealedDek';
    public const PTR_ENVELOPE_AAD    = '/encryption/envelope/aad';
    public const PTR_CHUNK_ORDINAL   = '/chunkIndex/chunks/';

    // Rule identifiers for LaraException.details[].Rule.
    public const RULE_ALGO_UNKNOWN    = 'AlgorithmUnknown';
    public const RULE_KDF_UNKNOWN     = 'KdfUnknown';
    public const RULE_KEK_UNRESOLVED  = 'KekUnresolved';
    public const RULE_KEK_RETIRED     = 'KekEpochRetired';
    public const RULE_DEK_UNSEAL      = 'DekUnsealTagFailed';
    public const RULE_FRAME_TAG       = 'FrameTagFailed';
    public const RULE_NONCE_REUSE     = 'NonceReuseDetected';
    public const RULE_SEALED_DEK_LEN  = 'SealedDekLengthInvalid';
    public const RULE_KEK_LEN         = 'KekLengthInvalid';

    // Error codes (must be registered in config/lara.php).
    public const ERR_BACKUP_CORRUPT       = 'BackupCorrupt';
    public const ERR_KEK_EPOCH_RETIRED    = 'BackupKeyEpochRetired';
}
