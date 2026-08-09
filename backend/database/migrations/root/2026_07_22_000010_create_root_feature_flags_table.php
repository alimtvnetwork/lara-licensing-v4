<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Plan 14 step 4. Root DB `FeatureFlags` table + `FeatureFlagValue` enum,
 * seeded with the 7 Backup/Restore flags per
 * spec/26-backup-restore/25-migration-and-rollout.md §"Feature Flags".
 *
 * Normative sources:
 *  - spec/26-backup-restore/25-migration-and-rollout.md v1.0.0 §"Feature
 *    Flags (closed set)": 7 flags, tri-state values `off|shadow|on`,
 *    default `off` in every env.
 *  - INV-BR-MG-3: flags MUST be checked in the controller BEFORE any
 *    lock acquisition, so a disabled feature never mutates state.
 *  - INV-BR-MG-4: `shadow` mode emits observability rows tagged
 *    `mode=shadow` and MUST NOT persist to canonical tables.
 *
 * Purpose: give `App\Services\BR\BrFeatureFlagService` a queryable
 * catalogue of feature flags. This is the first `FeatureFlags` migration
 * in the platform; subsequent modules (Reports, Api, etc.) may seed
 * their own rows via forward-only migrations.
 *
 * Closed sets:
 *  - `FeatureFlagValue`: `off, shadow, on`.
 *  - Initial `FlagId` domain: 7 BR flags (see REGISTRY below).
 *
 * Reversibility: fully reversible. Down drops seeded rows, table, enum.
 */
return new class extends Migration
{
    private const CONN = 'root';

    private const VALUE_MEMBERS = ['off', 'shadow', 'on'];

    /** [FlagId, DefaultValue, Description] per spec 25 §Feature Flags. */
    private const REGISTRY = [
        ['br.export.enabled',             'off', 'Guards POST /api/backup/exports and RA-BR-2.'],
        ['br.import.enabled',             'off', 'Guards POST /api/backup/imports, /restores, RA-BR-3.'],
        ['br.snapshots.enabled',          'off', 'Guards /api/backup/snapshots/* and RA-BR-4/5.'],
        ['br.roles-ui.enabled',           'off', 'Guards FE RA-BR-6. BE Casbin is always on once seeded.'],
        ['br.audit-chain-verify.enabled', 'off', 'Guards daily chain verifier job; ships on from day one.'],
        ['br.audit-pseudonymise.enabled', 'off', 'Guards fn_pseudonymise_actor invocation; legal gated.'],
        ['br.kill-switch',                'off', 'Global BR module halt; 503 ModuleDisabled with Retry-After.'],
    ];

    public function up(): void
    {
        $this->createValueEnum();
        $this->createTable();
        $this->applyGrants();
        $this->enableRls();
        $this->seedRegistry();
    }

    public function down(): void
    {
        DB::connection(self::CONN)->statement('DROP TABLE IF EXISTS "FeatureFlags"');
        DB::connection(self::CONN)->statement('DROP TYPE IF EXISTS "FeatureFlagValue"');
    }

    private function createValueEnum(): void
    {
        $members = implode(',', array_map(static fn (string $m): string => "'{$m}'", self::VALUE_MEMBERS));
        DB::connection(self::CONN)->statement(<<<SQL
            DO $$
            BEGIN
                IF NOT EXISTS (SELECT 1 FROM pg_type WHERE typname = 'FeatureFlagValue') THEN
                    EXECUTE 'CREATE TYPE "FeatureFlagValue" AS ENUM ({$members})';
                END IF;
            END
            $$;
        SQL);
    }

    private function createTable(): void
    {
        DB::connection(self::CONN)->statement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS "FeatureFlags" (
                "FlagId"      TEXT                 PRIMARY KEY,
                "Value"       "FeatureFlagValue"   NOT NULL DEFAULT 'off',
                "Description" TEXT                 NOT NULL,
                "UpdatedAt"   TIMESTAMPTZ          NOT NULL DEFAULT NOW(),
                "UpdatedBy"   UUID                 NULL,
                CONSTRAINT "CkFeatureFlagsIdShape" CHECK ("FlagId" ~ '^[a-z][a-z0-9._-]{2,63}$')
            )
        SQL);
        DB::connection(self::CONN)->statement(
            'CREATE INDEX IF NOT EXISTS "IxFeatureFlagsUpdatedAt" ON "FeatureFlags" ("UpdatedAt" DESC)'
        );
    }

    private function applyGrants(): void
    {
        $this->grantIfRoleExists('authenticated', 'GRANT SELECT ON "FeatureFlags" TO authenticated');
        $this->grantIfRoleExists('service_role',  'GRANT ALL ON "FeatureFlags" TO service_role');
    }

    private function enableRls(): void
    {
        DB::connection(self::CONN)->statement('ALTER TABLE "FeatureFlags" ENABLE ROW LEVEL SECURITY');
        DB::connection(self::CONN)->statement(<<<'SQL'
            DO $$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1 FROM pg_policies
                    WHERE schemaname='public' AND tablename='FeatureFlags'
                      AND policyname='FeatureFlagsReadable'
                ) THEN
                    EXECUTE 'CREATE POLICY "FeatureFlagsReadable" ON "FeatureFlags" FOR SELECT TO authenticated USING (true)';
                END IF;
            END
            $$;
        SQL);
    }

    private function seedRegistry(): void
    {
        foreach (self::REGISTRY as $row) {
            DB::connection(self::CONN)->insert(
                'INSERT INTO "FeatureFlags" ("FlagId","Value","Description")
                 VALUES (?, ?::"FeatureFlagValue", ?)
                 ON CONFLICT ("FlagId") DO NOTHING',
                $row,
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
