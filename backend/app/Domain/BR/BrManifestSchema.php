<?php

declare(strict_types=1);

namespace App\Domain\BR;

/**
 * Plan 14 step 7. Canonical constants for the BR archive manifest.
 *
 * Normative source: spec/26-backup-restore/07-manifest-schema.md v1.0.0
 * (JSON Schema draft-2020-12), §"`excluded` Shape", §"Validation Contract",
 * and §"Invariants INV-BR-MS-1..5".
 *
 * Every literal referenced by the manifest validator lives here so
 * there are no magic strings in the domain layer.
 */
final class BrManifestSchema
{
    public const VERSION = '1.0.0';
    public const SCHEMA_ID = 'https://lara.local/schemas/backup-manifest-1.0.0.json';

    /** Top-level required keys (spec §"Top-Level Fields", `required` list). */
    public const REQUIRED_TOP = [
        'version', 'archiveKind', 'archiveId', 'createdAt', 'appVersion',
        'schemaHash', 'contentHash', 'chunkIndex', 'encryption',
        'scope', 'excluded', 'producedBy',
    ];

    /** Optional top-level keys allowed by `additionalProperties: false`. */
    public const OPTIONAL_TOP = ['signature'];

    /** Exactly the eight `scope` keys per spec §"`scope` Shape" (`SC-A..H`). */
    public const SCOPE_KEYS = [
        'schema', 'closedSets', 'features', 'licenses',
        'rbac', 'domain', 'secretsEnvelope', 'files',
    ];

    /** Regex for lower-hex SHA-256 (`^[0-9a-f]{64}$`). */
    public const HEX_SHA256 = '/^[0-9a-f]{64}$/';

    /** Regex for SemVer `^\d+\.\d+\.\d+$`. */
    public const SEMVER = '/^\d+\.\d+\.\d+$/';

    /**
     * Closed catalogue of `EX-A..E` table names per spec 26 §06
     * §"`EX-A..E`" (rows scanned 2026-07-20). Manifest `excluded[]`
     * entries MUST be a subset of this set (INV-BR-MS-4 companion).
     */
    public const EXCLUDED_CATALOGUE = [
        'public.audit_log',
        'public.cache',
        'public.cache_locks',
        'public.failed_jobs',
        'public.job_batches',
        'public.jobs',
        'public.lara_diag_events',
        'public.telescope_entries',
        'root.auth_sessions',
        'root.idempotency_records',
        'root.impersonation_index',
        'root.password_reset_tokens',
        'root.personal_access_tokens',
        'shard.auth_sessions',
        'shard.licenses',
        'shard.quota_requests',
    ];

    /** Fixed algorithm strings per spec §07 `encryption` shape. */
    public const ENCRYPTION_ALG = 'aes-256-gcm';
    public const ENCRYPTION_KDF = 'hkdf-sha256';
    public const ENCRYPTION_NONCE_BYTES = 12;

    /** Fixed chunk-index algorithm per spec §07 `chunkIndex` shape. */
    public const CHUNK_ALG = 'zstd';
    public const CHUNK_LEVEL_MIN = 1;
    public const CHUNK_LEVEL_MAX = 22;
    public const CHUNK_SIZE_MIN  = 1_048_576;
    /** Pinned writer knobs (spec §"`chunkIndex` Shape" default row). */
    public const CHUNK_LEVEL = 19;
    public const CHUNK_SIZE  = 8_388_608;
}
