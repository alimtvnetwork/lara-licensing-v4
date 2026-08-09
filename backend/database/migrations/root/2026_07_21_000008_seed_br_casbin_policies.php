<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Plan 14 step 3b. Root DB `CasbinRules` adapter table + BR seed rows
 * (migration 8 per spec/26-backup-restore/25-migration-and-rollout.md).
 *
 * Normative sources:
 *  - spec/26-backup-restore/02-casbin-integration.md v1.0.0 §"Adapter"
 *    (adapter table DDL sketch, unique-index dedupe, grants + RLS),
 *    §"Policy Shape" (sub role names, obj `/Api/V1/*` or `Capability:*`,
 *    act regex, eft {allow,deny}).
 *  - spec/26-backup-restore/03-permission-matrix.md v1.0.0 §"Casbin Seed
 *    Rows" (verbatim BR 14-capability seed CSV) + §"FE capability mirrors".
 *  - spec/26-backup-restore/25-migration-and-rollout.md §"Migration
 *    Order" migration 8 ("idempotent via `ON CONFLICT DO NOTHING` on
 *    the unique index"; reversible).
 *  - INV-BR-PM-1..5.
 *
 * Table name: `CasbinRules` in PascalCase per project memory. The FE
 * enforcer + `lauthz/laravel-authz` adapter integration will bind to
 * this exact name in a later step (Plan 14 step 24 PAP surface); no
 * package binding is created here.
 *
 * Reversibility: the table is created here but MAY be shared with
 * future modules, so `down()` deletes only the BR-owned seed rows
 * enumerated in the closed sets below and does NOT drop the table,
 * per INV-BR-MG-2 spirit ("no destructive rollback of shared state").
 */
return new class extends Migration
{
    private const CONN = 'root';

    /** Server-authoritative p rows, format: [sub, obj, act, eft]. */
    private const P_ROWS_SERVER = [
        ['super_admin', '/Api/V1/Admin/*',                        '.*',           'allow'],
        ['admin',       '/Api/V1/Admin/Backup/Export',             'POST',         'allow'],
        ['admin',       '/Api/V1/Admin/Backup/Export/*',           'GET',          'allow'],
        ['admin',       '/Api/V1/Admin/Backup/Import',             'POST',         'allow'],
        ['admin',       '/Api/V1/Admin/Backup/Import/*',           'GET',          'allow'],
        ['admin',       '/Api/V1/Admin/Snapshot',                  '(GET|POST)',   'allow'],
        ['admin',       '/Api/V1/Admin/Snapshot/*',                '(GET|DELETE)', 'allow'],
        ['admin',       '/Api/V1/Admin/Snapshot/*/Pin',            'POST',         'allow'],
        ['admin',       '/Api/V1/Admin/Snapshot/*/Restore',        'POST',         'allow'],
        ['admin',       '/Api/V1/Admin/Roles*',                    'GET',          'allow'],
        ['admin',       '/Api/V1/Admin/System/Config',             'GET',          'allow'],
        ['admin',       '/Api/V1/Admin/System/Health',             'GET',          'allow'],
        ['admin',       '/Api/V1/Admin/Audit*',                    'GET',          'allow'],
        ['operator',    '/Api/V1/Admin/Backup/Export',             'POST',         'allow'],
        ['operator',    '/Api/V1/Admin/Backup/Export/*',           'GET',          'allow'],
        ['operator',    '/Api/V1/Admin/Backup/Import/*',           'GET',          'allow'],
        ['operator',    '/Api/V1/Admin/Snapshot',                  '(GET|POST)',   'allow'],
        ['operator',    '/Api/V1/Admin/Snapshot/*',                'GET',          'allow'],
        ['operator',    '/Api/V1/Admin/Snapshot/*/Pin',            'POST',         'allow'],
        ['operator',    '/Api/V1/Admin/System/Config',             'GET',          'allow'],
        ['operator',    '/Api/V1/Admin/System/Health',             'GET',          'allow'],
        ['auditor',     '/Api/V1/Admin/Backup/Export/*',           'GET',          'allow'],
        ['auditor',     '/Api/V1/Admin/Backup/Import/*',           'GET',          'allow'],
        ['auditor',     '/Api/V1/Admin/Snapshot',                  'GET',          'allow'],
        ['auditor',     '/Api/V1/Admin/Snapshot/*',                'GET',          'allow'],
        ['auditor',     '/Api/V1/Admin/Roles*',                    'GET',          'allow'],
        ['auditor',     '/Api/V1/Admin/System/Config',             'GET',          'allow'],
        ['auditor',     '/Api/V1/Admin/System/Health',             'GET',          'allow'],
        ['auditor',     '/Api/V1/Admin/Audit*',                    'GET',          'allow'],
        ['deputy',      '/Api/V1/Admin/Backup/Import',             '.*',           'deny'],
        ['deputy',      '/Api/V1/Admin/Snapshot/*/Restore',        '.*',           'deny'],
    ];

    /** FE capability mirrors, advisory per spec 02 §PEP Placement. */
    private const P_ROWS_FE = [
        ['super_admin', 'Capability:*',                            '.*', 'allow'],
        ['admin',       'Capability:Backup.Export',                '.*', 'allow'],
        ['admin',       'Capability:Backup.Import',                '.*', 'allow'],
        ['admin',       'Capability:Snapshot.*',                   '.*', 'allow'],
        ['admin',       'Capability:System.Read',                  '.*', 'allow'],
        ['admin',       'Capability:Audit.Read',                   '.*', 'allow'],
        ['operator',    'Capability:Backup.Export',                '.*', 'allow'],
        ['operator',    'Capability:Snapshot.Create',              '.*', 'allow'],
        ['operator',    'Capability:Snapshot.Pin',                 '.*', 'allow'],
        ['auditor',     'Capability:Snapshot.Read',                '.*', 'allow'],
        ['auditor',     'Capability:Audit.Read',                   '.*', 'allow'],
        ['auditor',     'Capability:Role.Read',                    '.*', 'allow'],
        ['deputy',      'Capability:Backup.Import',                '.*', 'deny'],
        ['deputy',      'Capability:Snapshot.Restore',             '.*', 'deny'],
    ];

    public function up(): void
    {
        $this->createTable();
        $this->createIndexes();
        $this->applyGrants();
        $this->enableRls();
        $this->seedRows();
    }

    public function down(): void
    {
        foreach ([...self::P_ROWS_SERVER, ...self::P_ROWS_FE] as $row) {
            DB::connection(self::CONN)->delete(
                'DELETE FROM "CasbinRules" WHERE "Ptype" = ? AND "V0" = ? AND "V1" = ? AND "V2" = ? AND "V3" = ?',
                ['p', $row[0], $row[1], $row[2], $row[3]],
            );
        }
        // Shared table: do NOT drop.
    }

    private function createTable(): void
    {
        DB::connection(self::CONN)->statement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS "CasbinRules" (
                "Id"        BIGSERIAL     PRIMARY KEY,
                "Ptype"     VARCHAR(8)    NOT NULL,
                "V0"        VARCHAR(256)  NOT NULL,
                "V1"        VARCHAR(256)  NOT NULL,
                "V2"        VARCHAR(256),
                "V3"        VARCHAR(16),
                "V4"        VARCHAR(256),
                "V5"        VARCHAR(256),
                "UpdatedAt" TIMESTAMPTZ   NOT NULL DEFAULT NOW(),
                CONSTRAINT "CkCasbinRulesPtype"    CHECK ("Ptype" IN ('p','g')),
                CONSTRAINT "CkCasbinRulesEftAllowed" CHECK ("V3" IS NULL OR "V3" IN ('allow','deny')),
                CONSTRAINT "CkCasbinRulesObjPrefix"  CHECK (
                    "Ptype" <> 'p' OR
                    "V1" LIKE '/Api/V1/%' OR
                    "V1" LIKE 'Capability:%'
                )
            )
        SQL);
    }

    private function createIndexes(): void
    {
        DB::connection(self::CONN)->statement(<<<'SQL'
            CREATE UNIQUE INDEX IF NOT EXISTS "UxCasbinRules"
                ON "CasbinRules" ("Ptype","V0","V1", COALESCE("V2",''), COALESCE("V3",''))
        SQL);
        DB::connection(self::CONN)->statement(
            'CREATE INDEX IF NOT EXISTS "IxCasbinRulesPtypeV0" ON "CasbinRules" ("Ptype","V0")'
        );
    }

    private function applyGrants(): void
    {
        $this->grantIfRoleExists('authenticated', 'GRANT SELECT ON "CasbinRules" TO authenticated');
        $this->grantIfRoleExists('service_role',  'GRANT ALL ON "CasbinRules" TO service_role');
    }

    private function enableRls(): void
    {
        DB::connection(self::CONN)->statement('ALTER TABLE "CasbinRules" ENABLE ROW LEVEL SECURITY');
        DB::connection(self::CONN)->statement(<<<'SQL'
            DO $$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1 FROM pg_policies
                    WHERE schemaname='public' AND tablename='CasbinRules'
                      AND policyname='CasbinRulesReadableByAuthenticated'
                ) THEN
                    EXECUTE 'CREATE POLICY "CasbinRulesReadableByAuthenticated" ON "CasbinRules" FOR SELECT TO authenticated USING (true)';
                END IF;
            END
            $$;
        SQL);
    }

    private function seedRows(): void
    {
        foreach ([...self::P_ROWS_SERVER, ...self::P_ROWS_FE] as $row) {
            DB::connection(self::CONN)->insert(
                'INSERT INTO "CasbinRules" ("Ptype","V0","V1","V2","V3") VALUES (?,?,?,?,?)
                 ON CONFLICT ("Ptype","V0","V1", COALESCE("V2",\'\'), COALESCE("V3",\'\')) DO NOTHING',
                ['p', $row[0], $row[1], $row[2], $row[3]],
            );
        }
    }

    private function grantIfRoleExists(string $role, string $sql): void
    {
        DB::connection(self::CONN)->statement(<<<SQL
            DO $$
            BEGIN
                IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = '{$role}') THEN
                    EXECUTE \$stmt\${$sql}\$stmt\$;
                END IF;
            END
            $$;
        SQL);
    }
};
