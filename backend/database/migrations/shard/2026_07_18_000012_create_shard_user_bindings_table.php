<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Plan 06 step 39 (substrate, user axis). Shard `UserBindings`.
 *
 * Root cause this migration addresses (one sentence): the `UserCount`
 * cap declared in `spec/21-app/06-license-variations.md` line 26 and
 * the `LicenseUserLimit` (409) error in
 * `spec/21-app/11-api-contracts/03-verification-contracts.md` line 53
 * cannot be enforced at verify time because the substrate table pinned
 * by `spec/23-app-db/01-schema.md` lines 484-494 does not exist yet.
 *
 * Columns and invariants:
 *  - `UserBindingId`   BIGSERIAL PK.
 *  - `LicenseId`       FK -> `Licenses`, ON DELETE RESTRICT.
 *  - `UserIdentifier`  VARCHAR(255) NOT NULL. Email OR hashed IP
 *                      fallback per spec 06 line 26. Stored as-is; the
 *                      hash decision lives client-side / at the
 *                      Verify/Final entry point (never here).
 *  - `FirstSeenAt`     TIMESTAMPTZ NOT NULL DEFAULT NOW().
 *  - `LastSeenAt`      TIMESTAMPTZ NOT NULL DEFAULT NOW().
 *  - `IsReleased`      BOOLEAN NOT NULL DEFAULT FALSE. Matches
 *                      `spec/23-app-db/01-schema.md` line 492 (TINYINT(1)
 *                      in the MySQL-flavored spec, BOOLEAN in Postgres).
 *  - `ReleasedAt`      TIMESTAMPTZ NULL. Set together with
 *                      `IsReleased = TRUE`.
 *  - `CreatedAt` / `UpdatedAt` TIMESTAMPTZ NOT NULL DEFAULT NOW().
 *
 * Indexes:
 *  - UNIQUE `(LicenseId, UserIdentifier) WHERE IsReleased = FALSE`:
 *    partial unique enforces "at most one active binding per user
 *    per license" without blocking historical re-bind after release.
 *  - INDEX `(LicenseId, IsReleased)`: fast count for `UserCount` cap.
 */
return new class extends Migration
{
    private const CONN = 'shard';

    public function up(): void
    {
        DB::connection(self::CONN)->statement('
            CREATE TABLE IF NOT EXISTS "UserBindings" (
                "UserBindingId"   BIGSERIAL PRIMARY KEY,
                "LicenseId"       BIGINT NOT NULL
                    REFERENCES "Licenses"("LicenseId") ON DELETE RESTRICT,
                "UserIdentifier"  VARCHAR(255) NOT NULL
                    CONSTRAINT "CkUserBindingIdentifierNotBlank"
                    CHECK (LENGTH(TRIM("UserIdentifier")) > 0),
                "FirstSeenAt"     TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                "LastSeenAt"      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                "IsReleased"      BOOLEAN NOT NULL DEFAULT FALSE,
                "ReleasedAt"      TIMESTAMPTZ NULL,
                "CreatedAt"       TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                "UpdatedAt"       TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                CONSTRAINT "CkUserBindingReleasedConsistency"
                    CHECK (
                        ("IsReleased" = FALSE AND "ReleasedAt" IS NULL)
                        OR
                        ("IsReleased" = TRUE  AND "ReleasedAt" IS NOT NULL)
                    ),
                CONSTRAINT "CkUserBindingLastSeenAfterFirst"
                    CHECK ("LastSeenAt" >= "FirstSeenAt")
            )
        ');
        DB::connection(self::CONN)->statement(
            'CREATE UNIQUE INDEX IF NOT EXISTS "UX_UserBindings_ActivePerLicense" '
            . 'ON "UserBindings" ("LicenseId","UserIdentifier") '
            . 'WHERE "IsReleased" = FALSE'
        );
        DB::connection(self::CONN)->statement(
            'CREATE INDEX IF NOT EXISTS "IX_UserBindings_LicenseReleased" '
            . 'ON "UserBindings" ("LicenseId","IsReleased")'
        );
    }

    public function down(): void
    {
        DB::connection(self::CONN)->statement('DROP TABLE IF EXISTS "UserBindings"');
    }
};
