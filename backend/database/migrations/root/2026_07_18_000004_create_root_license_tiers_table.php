<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Plan 06 step 41a. Root DB `LicenseTiers` lookup table.
 *
 * Normative source: spec/21-app/43-license-tiers.md v1.0.0 §2 (closed enum
 * Tier1/Tier2/Tier3/Unlimited, stable ordinals 1..4, reserved 5..15) and
 * spec/23-app-db/01-schema.md §LicenseTiers.
 *
 * Root placement rationale: `LicenseTiers` is a cross-reseller catalog
 * referenced by `ResellerQuotas` (Root) and by `Licenses.LicenseTierId`
 * (per-shard). Physical FK crossing shard->root is forbidden per
 * spec/23-app-db/10-reseller-shard-split-db.md §App-tier; shard-side
 * references are loose numeric references validated by the application
 * against this table. AC-LT-001 is enforced physically by
 * `CkLicenseTiersMemberSet`.
 *
 * `TierOrdinal` is persisted (not derived) so wire payloads and log
 * lines that need a numeric tier can index by column without a case
 * expression, per spec 43 §2 "Ordinals are stable identifiers".
 */
return new class extends Migration
{
    private const CONN = 'root';
    private const MEMBER_TIER1 = 'Tier1';
    private const MEMBER_TIER2 = 'Tier2';
    private const MEMBER_TIER3 = 'Tier3';
    private const MEMBER_UNLIMITED = 'Unlimited';

    public function up(): void
    {
        DB::connection(self::CONN)->statement('
            CREATE TABLE IF NOT EXISTS "LicenseTiers" (
                "LicenseTierId" SMALLSERIAL PRIMARY KEY,
                "TierName"      VARCHAR(16) NOT NULL UNIQUE
                    CONSTRAINT "CkLicenseTiersMemberSet"
                    CHECK ("TierName" IN (\'' . self::MEMBER_TIER1 . '\',\'' . self::MEMBER_TIER2 . '\',\'' . self::MEMBER_TIER3 . '\',\'' . self::MEMBER_UNLIMITED . '\')),
                "TierOrdinal"   SMALLINT NOT NULL UNIQUE
                    CONSTRAINT "CkLicenseTiersOrdinalRange"
                    CHECK ("TierOrdinal" BETWEEN 1 AND 4),
                "CreatedAt"     TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                "UpdatedAt"     TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )
        ');

        // Deterministic seed per spec 43 §2. `ON CONFLICT` keeps re-runs
        // idempotent so an out-of-order migrate:fresh is safe.
        $rows = [
            [1, self::MEMBER_TIER1],
            [2, self::MEMBER_TIER2],
            [3, self::MEMBER_TIER3],
            [4, self::MEMBER_UNLIMITED],
        ];
        foreach ($rows as [$ordinal, $name]) {
            DB::connection(self::CONN)->statement(
                'INSERT INTO "LicenseTiers" ("LicenseTierId","TierName","TierOrdinal") VALUES (?,?,?)
                    ON CONFLICT ("TierName") DO NOTHING',
                [$ordinal, $name, $ordinal],
            );
        }
    }

    public function down(): void
    {
        DB::connection(self::CONN)->statement('DROP TABLE IF EXISTS "LicenseTiers"');
    }
};
