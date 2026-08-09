<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Plan 06 step 11. Root DB reseller directory + shard routing + Root
 * audit log.
 *
 * Creates `Resellers`, `ResellerShardRoutes`, and the Root-scoped
 * `AuditLogs` per spec/23-app-db/10-reseller-shard-split-db.md §Root DB
 * tables and §Provisioning Lifecycle. `ShardStatus` is a closed enum
 * enforced by CHECK: {Provisioning, Active, Failed, Quiesced}. Every
 * Reseller has exactly one Route row (UNIQUE on ResellerId).
 *
 * `ResellerSlug` is Root-scope UK per spec/23-app-db/10 §Provisioning
 * step 1; it drives the shard DSN template substitution done by
 * ShardResolver::bind() (Plan 06 step 10). Slug format is enforced by
 * CHECK: `^[a-z][a-z0-9-]{2,63}$`.
 *
 * Users.TenantId FK is closed here in a separate ALTER so the previous
 * migration could create Users before Resellers existed.
 */
return new class extends Migration
{
    private const CONN = 'root';

    public function up(): void
    {
        // Resellers directory (Root scope only; no license data here).
        DB::connection(self::CONN)->statement('
            CREATE TABLE IF NOT EXISTS "Resellers" (
                "ResellerId"    BIGSERIAL PRIMARY KEY,
                "ResellerName"  VARCHAR(128) NOT NULL UNIQUE,
                "ResellerSlug"  VARCHAR(64)  NOT NULL UNIQUE
                    CHECK ("ResellerSlug" ~ \'^[a-z][a-z0-9-]{2,63}$\'),
                "ContactEmail"  VARCHAR(255) NOT NULL,
                "IsActive"      BOOLEAN NOT NULL DEFAULT TRUE,
                "CreatedAt"     TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                "UpdatedAt"     TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                "DeletedAt"     TIMESTAMPTZ NULL
            )
        ');

        // Route table: one Route row per Reseller. ShardStatus is a
        // state machine per spec 23 §Provisioning Lifecycle.
        DB::connection(self::CONN)->statement('
            CREATE TABLE IF NOT EXISTS "ResellerShardRoutes" (
                "ResellerShardRouteId" BIGSERIAL PRIMARY KEY,
                "ResellerId"           BIGINT NOT NULL UNIQUE
                    REFERENCES "Resellers"("ResellerId") ON DELETE RESTRICT,
                "AppDbPath"            VARCHAR(255) NOT NULL,
                "ShardStatus"          VARCHAR(16) NOT NULL DEFAULT \'Provisioning\'
                    CHECK ("ShardStatus" IN (\'Provisioning\',\'Active\',\'Failed\',\'Quiesced\')),
                "SchemaVersion"        VARCHAR(32) NULL,
                "LastMigratedAt"       TIMESTAMPTZ NULL,
                "LastError"            TEXT NULL,
                "CreatedAt"            TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                "UpdatedAt"            TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )
        ');
        DB::connection(self::CONN)->statement('CREATE INDEX IF NOT EXISTS "IX_RSR_ShardStatus" ON "ResellerShardRoutes" ("ShardStatus")');

        // Close the Users.TenantId FK now that Resellers exists.
        DB::connection(self::CONN)->statement('
            ALTER TABLE "Users"
            ADD CONSTRAINT "FK_Users_TenantId_Resellers"
            FOREIGN KEY ("TenantId") REFERENCES "Resellers"("ResellerId") ON DELETE RESTRICT
        ');

        // Root-scoped audit log. Per-reseller audits live in that
        // reseller's shard (spec 23 §App-tier tables). Closed Action
        // catalog is enforced by the application layer, not CHECK,
        // because the catalog is additive-only per spec/21-app/13.
        DB::connection(self::CONN)->statement('
            CREATE TABLE IF NOT EXISTS "AuditLogs" (
                "AuditLogId"  BIGSERIAL PRIMARY KEY,
                "ActorType"   VARCHAR(32)  NOT NULL
                    CHECK ("ActorType" IN (\'User\',\'AppBuilder\',\'System\')),
                "ActorId"     BIGINT NULL,
                "Action"      VARCHAR(64)  NOT NULL,
                "TargetType"  VARCHAR(64)  NOT NULL,
                "TargetId"    BIGINT NULL,
                "RequestId"   VARCHAR(64)  NOT NULL,
                "PayloadJson" JSONB NULL,
                "CreatedAt"   TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )
        ');
        DB::connection(self::CONN)->statement('CREATE INDEX IF NOT EXISTS "IX_Audit_Actor"     ON "AuditLogs" ("ActorType","ActorId")');
        DB::connection(self::CONN)->statement('CREATE INDEX IF NOT EXISTS "IX_Audit_Target"    ON "AuditLogs" ("TargetType","TargetId")');
        DB::connection(self::CONN)->statement('CREATE INDEX IF NOT EXISTS "IX_Audit_CreatedAt" ON "AuditLogs" ("CreatedAt")');
        DB::connection(self::CONN)->statement('CREATE INDEX IF NOT EXISTS "IX_Audit_RequestId" ON "AuditLogs" ("RequestId")');
    }

    public function down(): void
    {
        DB::connection(self::CONN)->statement('DROP TABLE IF EXISTS "AuditLogs"');
        DB::connection(self::CONN)->statement('ALTER TABLE "Users" DROP CONSTRAINT IF EXISTS "FK_Users_TenantId_Resellers"');
        DB::connection(self::CONN)->statement('DROP TABLE IF EXISTS "ResellerShardRoutes"');
        DB::connection(self::CONN)->statement('DROP TABLE IF EXISTS "Resellers"');
    }
};
