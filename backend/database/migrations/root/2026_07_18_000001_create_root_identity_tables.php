<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Plan 06 step 11. Root DB identity tables.
 *
 * Creates the identity trio (Roles, Users, UserRoles) on the `root`
 * connection per spec/23-app-db/01-schema.md §Users, Roles, Tenants and
 * spec/23-app-db/10-reseller-shard-split-db.md §Root DB tables. Root holds
 * only SuperAdmin + cross-reseller staff; per-reseller users live in the
 * reseller's shard (that migration ships in Plan 06 steps 14-18).
 *
 * PascalCase identifiers are preserved with double quotes so Postgres does
 * not fold them to lower case. Closed-set enums are enforced with CHECK
 * constraints. UpdatedAt is application-managed; a trigger is intentionally
 * omitted so writes stay explicit and testable.
 */
return new class extends Migration
{
    private const CONN = 'root';

    public function up(): void
    {
        $schema = Schema::connection(self::CONN);

        // Roles: closed set from spec/21-app/config lara.roles.
        // Seeded to {SuperAdmin, Admin, Reseller, AppBuilder, EndUser}
        // by RootRoleSeeder in Plan 06 step 13.
        DB::connection(self::CONN)->statement('
            CREATE TABLE IF NOT EXISTS "Roles" (
                "RoleId"    BIGSERIAL PRIMARY KEY,
                "RoleName"  VARCHAR(32) NOT NULL UNIQUE
                    CHECK ("RoleName" IN (\'SuperAdmin\',\'Admin\',\'Reseller\',\'AppBuilder\',\'EndUser\')),
                "CreatedAt" TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                "UpdatedAt" TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )
        ');

        // Users: Root-scoped only. TenantId (ResellerId) is nullable and
        // points at Resellers, but Resellers is created in the next
        // migration; the FK is added there to preserve migration order.
        DB::connection(self::CONN)->statement('
            CREATE TABLE IF NOT EXISTS "Users" (
                "UserId"       BIGSERIAL PRIMARY KEY,
                "Email"        VARCHAR(255) NOT NULL UNIQUE,
                "EmailLower"   VARCHAR(255) GENERATED ALWAYS AS (LOWER("Email")) STORED,
                "PasswordHash" VARCHAR(255) NOT NULL,
                "TenantId"     BIGINT NULL,
                "IsActive"     BOOLEAN NOT NULL DEFAULT TRUE,
                "CreatedAt"    TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                "UpdatedAt"    TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                "DeletedAt"    TIMESTAMPTZ NULL
            )
        ');
        DB::connection(self::CONN)->statement('CREATE UNIQUE INDEX IF NOT EXISTS "IX_Users_EmailLower" ON "Users" ("EmailLower")');
        DB::connection(self::CONN)->statement('CREATE INDEX IF NOT EXISTS "IX_Users_TenantId" ON "Users" ("TenantId")');

        // UserRoles: composite unique (UserId, RoleId).
        DB::connection(self::CONN)->statement('
            CREATE TABLE IF NOT EXISTS "UserRoles" (
                "UserRoleId" BIGSERIAL PRIMARY KEY,
                "UserId"     BIGINT NOT NULL REFERENCES "Users"("UserId") ON DELETE CASCADE,
                "RoleId"     BIGINT NOT NULL REFERENCES "Roles"("RoleId") ON DELETE RESTRICT,
                "CreatedAt"  TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                UNIQUE ("UserId", "RoleId")
            )
        ');
        DB::connection(self::CONN)->statement('CREATE INDEX IF NOT EXISTS "IX_UserRoles_RoleId" ON "UserRoles" ("RoleId")');
    }

    public function down(): void
    {
        DB::connection(self::CONN)->statement('DROP TABLE IF EXISTS "UserRoles"');
        DB::connection(self::CONN)->statement('DROP TABLE IF EXISTS "Users"');
        DB::connection(self::CONN)->statement('DROP TABLE IF EXISTS "Roles"');
    }
};
