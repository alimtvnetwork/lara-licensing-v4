<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Plan 06 step 12. Root DB `Prefixes` registry.
 *
 * Per spec/23-app-db/01-schema.md §Prefixes: uppercase alphanumeric,
 * globally UNIQUE, reseller-scoped via `ResellerId` FK. Prefix values
 * feed license-key and serial minting on shards; the registry lives in
 * Root so the uniqueness domain is cross-tenant per
 * spec/23-app-db/10-reseller-shard-split-db.md §Root DB tables. Format
 * is enforced by a CHECK regex matching AC-PFX-001 in
 * spec/21-app/24-vocabulary-normalization.md.
 */
return new class extends Migration
{
    private const CONN = 'root';

    public function up(): void
    {
        DB::connection(self::CONN)->statement('
            CREATE TABLE IF NOT EXISTS "Prefixes" (
                "PrefixId"    BIGSERIAL PRIMARY KEY,
                "ResellerId"  BIGINT NOT NULL
                    REFERENCES "Resellers"("ResellerId") ON DELETE RESTRICT,
                "PrefixValue" VARCHAR(12) NOT NULL UNIQUE
                    CHECK ("PrefixValue" ~ \'^[A-Z0-9]{2,12}$\'),
                "IsActive"    BOOLEAN NOT NULL DEFAULT TRUE,
                "CreatedAt"   TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                "UpdatedAt"   TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )
        ');
        DB::connection(self::CONN)->statement('CREATE INDEX IF NOT EXISTS "IX_Prefixes_ResellerId" ON "Prefixes" ("ResellerId")');
        DB::connection(self::CONN)->statement('CREATE INDEX IF NOT EXISTS "IX_Prefixes_IsActive"   ON "Prefixes" ("IsActive")');
    }

    public function down(): void
    {
        DB::connection(self::CONN)->statement('DROP TABLE IF EXISTS "Prefixes"');
    }
};
