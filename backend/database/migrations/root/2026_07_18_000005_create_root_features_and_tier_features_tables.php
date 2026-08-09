<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Plan 06 step 41 (substrate). Root DB `Features` catalog and
 * `TierFeatures` tier-default layer.
 *
 * Normative source: spec/21-app/45-license-features.md v1.0.0 §2 (closed
 * FeatureKey registry, typed value domains) and §4 (precedence
 * `LicenseFeatures > TierFeatures`). Both tables live in Root because
 * the catalog and tier defaults are cross-reseller; shard-local
 * per-license overrides already live in `LicenseFeatures` (shard) via
 * migration `2026_07_18_000005` on the shard connection.
 *
 * Cross-DB physical FKs are forbidden per split-DB architecture
 * (spec/23-app-db/10-reseller-shard-split-db.md §App-tier), so shard
 * `LicenseFeatures.FeatureId` remains a loose numeric reference validated
 * by App\Services\FeatureService.
 *
 * `Features` is seeded deterministically from `config('lara.feature_registry')`
 * so the catalog and the write-path validator (Plan step 31) share a
 * single source of truth. Re-runs are idempotent via ON CONFLICT.
 * `TierFeatures` is intentionally empty at seed time: tier defaults are
 * curated by admins through the surfaces built in later steps (§5).
 */
return new class extends Migration
{
    private const CONN = 'root';
    private const VALUE_TYPE_BOOLEAN = 'Boolean';
    private const VALUE_TYPE_NUMBER = 'Number';
    private const VALUE_TYPE_STRING = 'String';

    public function up(): void
    {
        $this->createFeatures();
        $this->createTierFeatures();
        $this->seedFeatures();
    }

    public function down(): void
    {
        DB::connection(self::CONN)->statement('DROP TABLE IF EXISTS "TierFeatures"');
        DB::connection(self::CONN)->statement('DROP TABLE IF EXISTS "Features"');
    }

    private function createFeatures(): void
    {
        $allowed = "'" . self::VALUE_TYPE_BOOLEAN . "','" . self::VALUE_TYPE_NUMBER . "','" . self::VALUE_TYPE_STRING . "'";
        DB::connection(self::CONN)->statement('
            CREATE TABLE IF NOT EXISTS "Features" (
                "FeatureId"   SMALLSERIAL PRIMARY KEY,
                "FeatureKey"  VARCHAR(64) NOT NULL UNIQUE
                    CONSTRAINT "CkFeaturesKeyShape"
                    CHECK ("FeatureKey" ~ \'^[A-Z][A-Za-z0-9]+(\.[A-Z][A-Za-z0-9]+)*$\'),
                "ValueType"   VARCHAR(16) NOT NULL
                    CONSTRAINT "CkFeaturesValueType"
                    CHECK ("ValueType" IN (' . $allowed . ')),
                "CreatedAt"   TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                "UpdatedAt"   TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )
        ');
    }

    private function createTierFeatures(): void
    {
        DB::connection(self::CONN)->statement('
            CREATE TABLE IF NOT EXISTS "TierFeatures" (
                "TierFeatureId"  BIGSERIAL PRIMARY KEY,
                "LicenseTierId"  SMALLINT NOT NULL
                    REFERENCES "LicenseTiers"("LicenseTierId") ON DELETE RESTRICT,
                "FeatureId"      SMALLINT NOT NULL
                    REFERENCES "Features"("FeatureId") ON DELETE RESTRICT,
                "Value"          JSONB NOT NULL,
                "CreatedByUserId" BIGINT NOT NULL,
                "CreatedAt"      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                "UpdatedAt"      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                CONSTRAINT "UxTierFeatures_Tier_Feature"
                    UNIQUE ("LicenseTierId","FeatureId"),
                CONSTRAINT "CkTierFeaturesValueScalar"
                    CHECK (jsonb_typeof("Value") IN (\'boolean\',\'number\',\'string\'))
            )
        ');
        DB::connection(self::CONN)->statement('CREATE INDEX IF NOT EXISTS "IX_TierFeatures_Tier" ON "TierFeatures" ("LicenseTierId")');
        DB::connection(self::CONN)->statement('CREATE INDEX IF NOT EXISTS "IX_TierFeatures_Feature" ON "TierFeatures" ("FeatureId")');
    }

    private function seedFeatures(): void
    {
        $registry = config('lara.feature_registry', []);
        if (!is_array($registry) || $registry === []) {
            return;
        }
        foreach (array_keys($registry) as $key) {
            $valueType = $registry[$key]['ValueType'] ?? null;
            if (!is_string($valueType) || $valueType === '') {
                continue;
            }
            DB::connection(self::CONN)->statement(
                'INSERT INTO "Features" ("FeatureKey","ValueType") VALUES (?,?) ON CONFLICT ("FeatureKey") DO NOTHING',
                [$key, $valueType],
            );
        }
    }
};
