<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Plan 06 step 15. Shard DB `Licenses` core table.
 *
 * Runs on the reseller shard connection bound by
 * `App\Db\ShardResolver::bind()`. Per spec/23-app-db/10-reseller-shard-split-db.md
 * §App-tier tables, license rows live in the reseller's shard, not Root.
 *
 * The `Version` column is the strong-ETag source per
 * spec/21-app/11-api-contracts/09-concurrency-control.md v1.0.0: every
 * mutation MUST increment `Version` and every GET response MUST emit
 * `ETag: "<Version>"`. `EtagMiddleware` (Plan 06 step 9) enforces the
 * `If-Match` handshake against this value on PUT/PATCH/DELETE.
 *
 * Closed-set columns are enforced with CHECK constraints. `TierName` and
 * `EnvironmentName` are denormalized copies of the closed-set catalog
 * values so shards do not need to join Root at read time; write-side
 * validation is done by the app layer against `config/lara.php`.
 *
 * `LicenseKey` uniqueness is shard-scoped; cross-shard uniqueness of the
 * prefix + serial namespace is guaranteed by the Root `Prefixes`
 * registry (Plan 06 step 12, migration `root_0003`).
 */
return new class extends Migration
{
    private const CONN = 'shard';

    public function up(): void
    {
        DB::connection(self::CONN)->statement('
            CREATE TABLE IF NOT EXISTS "Licenses" (
                "LicenseId"        BIGSERIAL PRIMARY KEY,
                "LicenseKey"       VARCHAR(64)  NOT NULL UNIQUE,
                "PrefixValue"      VARCHAR(12)  NOT NULL
                    CHECK ("PrefixValue" ~ \'^[A-Z0-9]{2,12}$\'),
                "ResellerId"       BIGINT       NULL,
                "IssuedByUserId"   BIGINT       NOT NULL,
                "TierName"         VARCHAR(16)  NOT NULL
                    CHECK ("TierName" IN (\'Tier1\',\'Tier2\',\'Tier3\',\'Unlimited\')),
                "EnvironmentName"  VARCHAR(16)  NOT NULL
                    CHECK ("EnvironmentName" IN (\'Production\',\'Staging\',\'Development\')),
                "ProductVersion"   VARCHAR(16)  NOT NULL DEFAULT \'V1\',
                "Status"           VARCHAR(16)  NOT NULL DEFAULT \'Active\'
                    CHECK ("Status" IN (\'Active\',\'Suspended\',\'Revoked\',\'Expired\')),
                "IssuedAt"         TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
                "ExpiresAt"        TIMESTAMPTZ  NULL,
                "Version"          INTEGER      NOT NULL DEFAULT 1
                    CHECK ("Version" > 0),
                "CreatedAt"        TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
                "UpdatedAt"        TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
                "DeletedAt"        TIMESTAMPTZ  NULL
            )
        ');
        DB::connection(self::CONN)->statement('CREATE INDEX IF NOT EXISTS "IX_Licenses_ResellerId_IssuedAt"      ON "Licenses" ("ResellerId","IssuedAt")');
        DB::connection(self::CONN)->statement('CREATE INDEX IF NOT EXISTS "IX_Licenses_EnvironmentName_IssuedAt" ON "Licenses" ("EnvironmentName","IssuedAt")');
        DB::connection(self::CONN)->statement('CREATE INDEX IF NOT EXISTS "IX_Licenses_Status"                   ON "Licenses" ("Status")');
        DB::connection(self::CONN)->statement('CREATE INDEX IF NOT EXISTS "IX_Licenses_PrefixValue"              ON "Licenses" ("PrefixValue")');

        // EnvironmentName immutability per AC-ADB-015 (spec 23 §Licenses).
        DB::connection(self::CONN)->statement('
            CREATE OR REPLACE FUNCTION "TrgFn_Licenses_EnvironmentImmutable"() RETURNS TRIGGER AS $$
            BEGIN
                IF OLD."EnvironmentName" <> NEW."EnvironmentName" THEN
                    RAISE EXCEPTION \'LICENSE_ENVIRONMENT_IMMUTABLE\';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
        ');
        DB::connection(self::CONN)->statement('
            CREATE TRIGGER "TrgLicensesEnvironmentImmutable"
            BEFORE UPDATE ON "Licenses"
            FOR EACH ROW EXECUTE FUNCTION "TrgFn_Licenses_EnvironmentImmutable"()
        ');
    }

    public function down(): void
    {
        DB::connection(self::CONN)->statement('DROP TRIGGER IF EXISTS "TrgLicensesEnvironmentImmutable" ON "Licenses"');
        DB::connection(self::CONN)->statement('DROP FUNCTION IF EXISTS "TrgFn_Licenses_EnvironmentImmutable"()');
        DB::connection(self::CONN)->statement('DROP TABLE IF EXISTS "Licenses"');
    }
};
