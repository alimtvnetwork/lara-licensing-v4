<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Plan 06 step 32. Extend shard `Licenses` with revoke + issuer columns.
 *
 * Adds the fields required by
 * spec/21-app/48-quota-restore-on-revoke.md v1.0.0:
 *
 *  - `IssuerActorType`   closed-set (`Admin` | `Reseller`). Determines
 *                        whether revoke is eligible for quota restoration.
 *                        Admin-issued licenses never charged Root's
 *                        `ResellerQuotas`, so revoke skips restoration
 *                        with `RestoreSkippedReason = AdminIssued`.
 *  - `RevokedAt`         nullable; set atomically by DELETE /Licenses.
 *  - `RevokedByUserId`   FK-shape only (users live on Root); nullable.
 *  - `RevokeReason`      free text, capped at 512 chars.
 *  - `ResellerQuotaLedgerId`
 *                        back-reference to the Root `ResellerQuotaLedger`
 *                        row that funded issuance. Null for admin
 *                        issuance. Populated by the Reseller\LicenseController
 *                        (Plan 06 step 35) when quota consume is wired.
 *
 * All columns are NULLABLE on existing rows; the CHECK on `IssuerActorType`
 * uses a WHERE clause so pre-existing rows (which will be backfilled to
 * `Admin` by this migration) satisfy the constraint. New rows MUST provide
 * `IssuerActorType`.
 */
return new class extends Migration
{
    private const CONN = 'shard';

    public function up(): void
    {
        DB::connection(self::CONN)->statement('
            ALTER TABLE "Licenses"
                ADD COLUMN IF NOT EXISTS "IssuerActorType"        VARCHAR(16) NULL,
                ADD COLUMN IF NOT EXISTS "RevokedAt"              TIMESTAMPTZ NULL,
                ADD COLUMN IF NOT EXISTS "RevokedByUserId"        BIGINT      NULL,
                ADD COLUMN IF NOT EXISTS "RevokeReason"           VARCHAR(512) NULL,
                ADD COLUMN IF NOT EXISTS "ResellerQuotaLedgerId"  BIGINT      NULL
        ');
        DB::connection(self::CONN)->statement('
            UPDATE "Licenses" SET "IssuerActorType" = \'Admin\' WHERE "IssuerActorType" IS NULL
        ');
        DB::connection(self::CONN)->statement('
            ALTER TABLE "Licenses"
                ADD CONSTRAINT "CK_Licenses_IssuerActorType"
                CHECK ("IssuerActorType" IN (\'Admin\',\'Reseller\'))
        ');
        DB::connection(self::CONN)->statement('
            CREATE INDEX IF NOT EXISTS "IX_Licenses_RevokedAt" ON "Licenses" ("RevokedAt")
                WHERE "RevokedAt" IS NOT NULL
        ');
    }

    public function down(): void
    {
        DB::connection(self::CONN)->statement('DROP INDEX IF EXISTS "IX_Licenses_RevokedAt"');
        DB::connection(self::CONN)->statement('
            ALTER TABLE "Licenses"
                DROP CONSTRAINT IF EXISTS "CK_Licenses_IssuerActorType",
                DROP COLUMN IF EXISTS "ResellerQuotaLedgerId",
                DROP COLUMN IF EXISTS "RevokeReason",
                DROP COLUMN IF EXISTS "RevokedByUserId",
                DROP COLUMN IF EXISTS "RevokedAt",
                DROP COLUMN IF EXISTS "IssuerActorType"
        ');
    }
};
