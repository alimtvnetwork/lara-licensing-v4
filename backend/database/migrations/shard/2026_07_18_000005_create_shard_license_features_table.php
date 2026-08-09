<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Plan 06 step 19. Shard DB `LicenseFeatures` (per-license override layer).
 *
 * Normative source: spec/21-app/45-license-features.md v1.0.0 §4
 * (Precedence: `LicenseFeatures` > `TierFeatures`) and
 * spec/23-app-db/01-schema.md §LicenseFeatures (lines 215-232).
 *
 * `LicenseFeatures` lives on the shard because every row references a
 * shard-local `Licenses.LicenseId` via ON DELETE CASCADE (spec 23: "an
 * override is meaningless without its license"). The lookup catalog
 * `Features` and the tier-default layer `TierFeatures` are cross-reseller
 * and stay in Root; here we store only the per-license overrides for
 * this shard's licenses.
 *
 * `FeatureId` is a loose numeric reference to Root `Features.FeatureId`;
 * cross-DB physical FKs are forbidden per split-DB architecture
 * (spec/23-app-db/10-reseller-shard-split-db.md §App-tier).
 *
 * `Value` is stored as JSONB. Type-shape validation
 * (`TrgLicenseFeaturesValueShape`, AC-FEAT-002) requires the
 * `Features.ValueType` column, which lives in Root; the physical shape
 * trigger therefore executes in the application service layer during
 * write, not at the DB layer here. A `CkValueIsJsonObject` CHECK still
 * guarantees `Value` is a scalar JSON value (Boolean/Number/String), not
 * an array or object, matching §3 of spec 45.
 */
return new class extends Migration
{
    private const CONN = 'shard';

    public function up(): void
    {
        DB::connection(self::CONN)->statement('
            CREATE TABLE IF NOT EXISTS "LicenseFeatures" (
                "LicenseFeatureId" BIGSERIAL PRIMARY KEY,
                "LicenseId"        BIGINT NOT NULL
                    REFERENCES "Licenses"("LicenseId") ON DELETE CASCADE,
                "FeatureId"        SMALLINT NOT NULL,
                "Value"            JSONB NOT NULL,
                "CreatedByUserId"  BIGINT NOT NULL,
                "CreatedAt"        TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                "UpdatedAt"        TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                CONSTRAINT "UxLicenseFeatures_License_Feature"
                    UNIQUE ("LicenseId","FeatureId"),
                CONSTRAINT "CkLicenseFeaturesValueScalar"
                    CHECK (jsonb_typeof("Value") IN (\'boolean\',\'number\',\'string\'))
            )
        ');
        DB::connection(self::CONN)->statement('CREATE INDEX IF NOT EXISTS "IX_LicenseFeatures_LicenseId" ON "LicenseFeatures" ("LicenseId")');
        DB::connection(self::CONN)->statement('CREATE INDEX IF NOT EXISTS "IX_LicenseFeatures_FeatureId" ON "LicenseFeatures" ("FeatureId")');
    }

    public function down(): void
    {
        DB::connection(self::CONN)->statement('DROP TABLE IF EXISTS "LicenseFeatures"');
    }
};
