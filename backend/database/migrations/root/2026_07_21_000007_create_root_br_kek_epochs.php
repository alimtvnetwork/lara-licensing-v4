<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Plan 14 step 3a. Root DB `BrKekEpochs` registry (migration 7 per
 * spec/26-backup-restore/25-migration-and-rollout.md).
 *
 * Normative sources:
 *  - spec/26-backup-restore/09-encryption-and-keys.md v1.0.0
 *    §"Key Hierarchy" (root KEK per epoch, held by SecretsProvider,
 *    never touches disk in cleartext), §"Sealed DEK" (KEK identified by
 *    monotonic integer `epoch` + opaque `kid` string), §"Re-Seal"
 *    (historical KEKs are read-only after their epoch rolls; only the
 *    current epoch KEK seals new archives).
 *  - INV-BR-EK-2, INV-BR-EK-5.
 *  - INV-BR-MG-2 (migration 7 is forward-only; no `DROP` on rollback).
 *
 * Scope: metadata registry only. Key material lives in
 * `SecretsProvider` (spec 10) and MUST NOT enter this table; the row
 * carries the opaque `Kid` handle plus lifecycle timestamps.
 *
 * Closed sets:
 *  - `BrKekEpochState`: `Pending, Active, Retired`. At most one row is
 *    `Active` at any time (partial unique index enforces this).
 *
 * Forward-only: the `down()` method REVOKEs the grants and DELETEs
 * only rows that never activated (`State = 'Pending'`); it MUST NOT
 * drop the table, the enum, or any activated/retired epoch row per
 * INV-BR-MG-2.
 */
return new class extends Migration
{
    private const CONN = 'root';

    private const STATE_ENUM = 'BrKekEpochState';

    private const STATE_MEMBERS = ['Pending', 'Active', 'Retired'];

    public function up(): void
    {
        $this->createStateEnum();
        $this->createTable();
        $this->createIndexes();
        $this->applyGrants();
        $this->enableRls();
    }

    public function down(): void
    {
        DB::connection(self::CONN)->statement('DELETE FROM "BrKekEpochs" WHERE "State" = \'Pending\'');
        $this->revokeGrants();
        // INV-BR-MG-2: no DROP of table/enum/activated rows.
    }

    private function createStateEnum(): void
    {
        $members = implode(',', array_map(static fn (string $m): string => "'{$m}'", self::STATE_MEMBERS));
        DB::connection(self::CONN)->statement(<<<SQL
            DO $$
            BEGIN
                IF NOT EXISTS (SELECT 1 FROM pg_type WHERE typname = 'BrKekEpochState') THEN
                    EXECUTE 'CREATE TYPE "BrKekEpochState" AS ENUM ({$members})';
                END IF;
            END
            $$;
        SQL);
    }

    private function createTable(): void
    {
        DB::connection(self::CONN)->statement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS "BrKekEpochs" (
                "Epoch"        INTEGER      PRIMARY KEY,
                "Kid"          TEXT         NOT NULL,
                "State"        "BrKekEpochState" NOT NULL DEFAULT 'Pending',
                "CreatedAt"    TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
                "ActivatedAt"  TIMESTAMPTZ,
                "RetiredAt"    TIMESTAMPTZ,
                "SecretsRef"   TEXT         NOT NULL,
                "Notes"        TEXT,
                CONSTRAINT "CkBrKekEpochsMonotonic" CHECK ("Epoch" >= 0),
                CONSTRAINT "CkBrKekEpochsKidShape"  CHECK ("Kid" ~ '^epoch-[0-9]+-[0-9]{4}-[0-9]{2}$'),
                CONSTRAINT "CkBrKekEpochsTimestamps" CHECK (
                    ("State" <> 'Pending'  OR ("ActivatedAt" IS NULL AND "RetiredAt" IS NULL)) AND
                    ("State" <> 'Active'   OR ("ActivatedAt" IS NOT NULL AND "RetiredAt" IS NULL)) AND
                    ("State" <> 'Retired'  OR ("ActivatedAt" IS NOT NULL AND "RetiredAt" IS NOT NULL))
                )
            )
        SQL);
    }

    private function createIndexes(): void
    {
        DB::connection(self::CONN)->statement(
            'CREATE UNIQUE INDEX IF NOT EXISTS "UxBrKekEpochsKid" ON "BrKekEpochs" ("Kid")'
        );
        DB::connection(self::CONN)->statement(
            'CREATE UNIQUE INDEX IF NOT EXISTS "UxBrKekEpochsSingleActive" ON "BrKekEpochs" (("State" = \'Active\')) WHERE "State" = \'Active\''
        );
        DB::connection(self::CONN)->statement(
            'CREATE INDEX IF NOT EXISTS "IxBrKekEpochsState" ON "BrKekEpochs" ("State", "ActivatedAt")'
        );
    }

    private function applyGrants(): void
    {
        $this->grantIfRoleExists('authenticated',
            'GRANT SELECT ("Epoch","Kid","State","CreatedAt","ActivatedAt","RetiredAt") ON "BrKekEpochs" TO authenticated');
        $this->grantIfRoleExists('service_role', 'GRANT ALL ON "BrKekEpochs" TO service_role');
    }

    private function revokeGrants(): void
    {
        foreach (['authenticated', 'service_role'] as $role) {
            $this->grantIfRoleExists($role, "REVOKE ALL ON \"BrKekEpochs\" FROM {$role}");
        }
    }

    private function enableRls(): void
    {
        DB::connection(self::CONN)->statement('ALTER TABLE "BrKekEpochs" ENABLE ROW LEVEL SECURITY');
        DB::connection(self::CONN)->statement(<<<'SQL'
            DO $$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1 FROM pg_policies
                    WHERE schemaname = 'public' AND tablename = 'BrKekEpochs'
                      AND policyname = 'BrKekEpochsReadable'
                ) THEN
                    EXECUTE 'CREATE POLICY "BrKekEpochsReadable" ON "BrKekEpochs" FOR SELECT TO authenticated USING (true)';
                END IF;
            END
            $$;
        SQL);
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
