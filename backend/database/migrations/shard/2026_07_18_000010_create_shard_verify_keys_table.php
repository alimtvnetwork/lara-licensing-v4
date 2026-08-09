<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Plan 06 step 39 (substrate). Shard DB `VerifyKeys` table.
 *
 * Root cause this migration addresses (one sentence): the three-endpoint
 * verify handshake in spec/21-app/09-verify-key.md and
 * spec/21-app/11-api-contracts/03-verification-contracts.md v1.3.0 requires a
 * server-issued nonce (`VerifyKey`) with single-use, 5-minute expiry,
 * hash-key digest binding, and audit-visible lifecycle transitions
 * (Issued / Consumed / Expired), and none of those columns exist yet.
 *
 * Columns and invariants (spec/21-app/09-verify-key.md §Generation +
 * spec/23-app-db/01-schema.md §VerifyKeys):
 *  - `VerifyKeyId`     BIGSERIAL PK.
 *  - `LicenseId`       FK -> `Licenses`. ON DELETE RESTRICT (audit trail).
 *  - `SerialId`        FK -> `Serials`.  ON DELETE RESTRICT.
 *  - `HashKeyDigest`   CHAR(64) hex, the SHA-256 digest of the HashKey the
 *                      client sent on `Verify/Hash`. `Verify/Final` MUST
 *                      recompute and constant-time-compare (AC-API-VER-001);
 *                      the raw HashKey is NEVER persisted.
 *  - `VerifyKeyValue`  CHAR(32) hex, cryptographic RNG, UNIQUE per shard.
 *  - `IssuedAt`        TIMESTAMPTZ NOT NULL DEFAULT NOW().
 *  - `ExpiresAt`       TIMESTAMPTZ NOT NULL. Application enforces
 *                      `ExpiresAt = IssuedAt + INTERVAL '5 minutes'`
 *                      (spec 09 §Generation). A CHECK is added to prevent
 *                      accidental `ExpiresAt <= IssuedAt` inserts.
 *  - `IsConsumed`      BOOLEAN NOT NULL DEFAULT FALSE. Flips to TRUE inside
 *                      the same transaction as the binding write on
 *                      `Verify/Final` (spec 03 §Transaction boundary); a
 *                      concurrent second consumer receives `VerifyKeyConsumed`
 *                      because the row-level lock has already been taken.
 *  - `ConsumedAt`      TIMESTAMPTZ NULL. Set together with IsConsumed=TRUE.
 *  - `RequestId`       VARCHAR(64) NOT NULL. Correlates ledger + audit rows
 *                      to the caller's `X-Request-Id` (spec 20 §Request-Id).
 *  - `CreatedAt` / `UpdatedAt` TIMESTAMPTZ, DEFAULT NOW().
 *
 * Indexes:
 *  - UNIQUE(VerifyKeyValue) globally per shard: forbids collisions.
 *  - INDEX(SerialId, IsConsumed) supports the "any unconsumed key for this
 *    serial" lookup that `Verify/Hash` performs before minting a new one.
 *  - INDEX(ExpiresAt) supports periodic cleanup of consumed / expired keys.
 *
 * Deliberately deferred to Plan 06 later steps (documented so no ambiguity):
 *  - `MachineBindings` + `UserBindings` shard tables (needed by
 *    `Verify/Final` for `MachineBindingId` / `UserBindingId` response fields).
 *    Until they exist, the controller MUST return those fields as `null`
 *    (spec 03 declares both fields OPTIONAL integers, so null is spec-legal).
 *  - `TierFeatures` root table (Plan 06 step 41) for the `Features` map;
 *    until it exists, the controller MUST NOT ship `Verify/Final` (AC-API-VER-012
 *    forbids an empty or defaulted Features map). Step 41 gates step 39c.
 */
return new class extends Migration
{
    private const CONN = 'shard';

    public function up(): void
    {
        DB::connection(self::CONN)->statement('
            CREATE TABLE IF NOT EXISTS "VerifyKeys" (
                "VerifyKeyId"    BIGSERIAL PRIMARY KEY,
                "LicenseId"      BIGINT NOT NULL
                    REFERENCES "Licenses"("LicenseId") ON DELETE RESTRICT,
                "SerialId"       BIGINT NOT NULL
                    REFERENCES "Serials"("SerialId") ON DELETE RESTRICT,
                "HashKeyDigest"  CHAR(64) NOT NULL
                    CONSTRAINT "CkVerifyKeyHashDigestHex"
                    CHECK ("HashKeyDigest" ~ \'^[a-f0-9]{64}$\'),
                "VerifyKeyValue" CHAR(32) NOT NULL UNIQUE
                    CONSTRAINT "CkVerifyKeyValueHex"
                    CHECK ("VerifyKeyValue" ~ \'^[a-f0-9]{32}$\'),
                "IssuedAt"       TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                "ExpiresAt"      TIMESTAMPTZ NOT NULL
                    CONSTRAINT "CkVerifyKeyExpiresAfterIssued"
                    CHECK ("ExpiresAt" > "IssuedAt"),
                "IsConsumed"     BOOLEAN NOT NULL DEFAULT FALSE,
                "ConsumedAt"     TIMESTAMPTZ NULL
                    CONSTRAINT "CkVerifyKeyConsumedAtConsistency"
                    CHECK (
                        ("IsConsumed" = FALSE AND "ConsumedAt" IS NULL)
                        OR
                        ("IsConsumed" = TRUE  AND "ConsumedAt" IS NOT NULL)
                    ),
                "RequestId"      VARCHAR(64) NOT NULL,
                "CreatedAt"      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                "UpdatedAt"      TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )
        ');
        DB::connection(self::CONN)->statement('CREATE INDEX IF NOT EXISTS "IX_VerifyKeys_SerialConsumed" ON "VerifyKeys" ("SerialId","IsConsumed")');
        DB::connection(self::CONN)->statement('CREATE INDEX IF NOT EXISTS "IX_VerifyKeys_LicenseId"     ON "VerifyKeys" ("LicenseId")');
        DB::connection(self::CONN)->statement('CREATE INDEX IF NOT EXISTS "IX_VerifyKeys_ExpiresAt"     ON "VerifyKeys" ("ExpiresAt")');
    }

    public function down(): void
    {
        DB::connection(self::CONN)->statement('DROP TABLE IF EXISTS "VerifyKeys"');
    }
};
