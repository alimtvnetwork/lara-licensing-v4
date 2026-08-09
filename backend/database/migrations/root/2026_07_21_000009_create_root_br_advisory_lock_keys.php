<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Plan 14 step 3c. Root DB `BrAdvisoryLockKeys` closed-set registry
 * (migration 9 per spec/26-backup-restore/25-migration-and-rollout.md).
 *
 * Normative sources:
 *  - spec/26-backup-restore/16-idempotency-and-locks.md v1.0.0
 *    §"Lock Registry" (closed set of six named locks with kind, scope,
 *    timeout, held-during, acquirer) and §"Acquisition Order" (rank
 *    1..6, kek.rotate outermost, backup_jobs.row innermost).
 *  - INV-BR-IL-3 (registry is closed; adding a lock requires spec edit
 *    + version bump + new rank).
 *  - INV-BR-IL-4 (all advisory locks are transaction-scoped;
 *    `pg_advisory_xact_lock`, NEVER `pg_advisory_lock`).
 *  - INV-BR-IL-5 (ascending-rank acquisition order).
 *
 * Purpose: give Casbin PDP + the Plan 14 worker/preflight code paths a
 * single-row-per-lock catalogue to look up the numeric advisory-lock
 * key (SHA-256-derived) and the ordering rank at request time. The
 * key column stores the derived `bigint` used by
 * `pg_advisory_xact_lock(bigint)`; the SQL derivation is
 * `hashtext(<LockName template>)` per spec 23 §"Hash-Chain" precedent
 * (audit trigger already uses the same hash for `audit.chain:<shardId>`).
 *
 * Closed sets:
 *  - `BrLockName`: 6 members from spec 16 §"Lock Registry".
 *  - `BrLockKind`: `pg_advisory_xact_lock`, `row_for_update_skip_locked`.
 *  - `BrLockScope`: `global, shard, label, object, row`.
 *
 * Reversibility: fully reversible (DROP table + enums). No production
 * rows depend on this catalogue for correctness; it is a metadata
 * lookup consumed by static-analysis checks and runtime observability
 * only.
 */
return new class extends Migration
{
    private const CONN = 'root';

    private const KIND_ADVISORY = 'pg_advisory_xact_lock';

    private const KIND_ROW = 'row_for_update_skip_locked';

    /** [LockName, Rank, Kind, Scope, TimeoutMs, HeldDuringNote] */
    private const REGISTRY = [
        ['kek.rotate',                          1, self::KIND_ADVISORY, 'global', 300000, 'Full KEK mint + activate + smoke round-trip.'],
        ['restore.singleton',                   2, self::KIND_ADVISORY, 'global',  60000, 'Entire Restore apply tx.'],
        ['snapshot.create:<shardId>',           3, self::KIND_ADVISORY, 'shard',   30000, 'Snapshot enqueue and apply tx.'],
        ['snapshots.label:<shardId>:<label>',   4, self::KIND_ADVISORY, 'label',    5000, 'Label reservation inside enqueue tx.'],
        ['storage.pin:<sha256>',                5, self::KIND_ADVISORY, 'object',   5000, 'Per-object pin-count bump inside apply tx.'],
        ['backup_jobs.row',                     6, self::KIND_ROW,      'row',         0, 'Duration of the dequeue statement only.'],
    ];

    private const SCOPE_MEMBERS = ['global', 'shard', 'label', 'object', 'row'];

    private const KIND_MEMBERS = [self::KIND_ADVISORY, self::KIND_ROW];

    public function up(): void
    {
        $this->createKindEnum();
        $this->createScopeEnum();
        $this->createTable();
        $this->applyGrants();
        $this->enableRls();
        $this->seedRegistry();
    }

    public function down(): void
    {
        DB::connection(self::CONN)->statement('DROP TABLE IF EXISTS "BrAdvisoryLockKeys"');
        DB::connection(self::CONN)->statement('DROP TYPE IF EXISTS "BrLockScope"');
        DB::connection(self::CONN)->statement('DROP TYPE IF EXISTS "BrLockKind"');
    }

    private function createKindEnum(): void
    {
        $members = implode(',', array_map(static fn (string $m): string => "'{$m}'", self::KIND_MEMBERS));
        DB::connection(self::CONN)->statement(<<<SQL
            DO $$
            BEGIN
                IF NOT EXISTS (SELECT 1 FROM pg_type WHERE typname = 'BrLockKind') THEN
                    EXECUTE 'CREATE TYPE "BrLockKind" AS ENUM ({$members})';
                END IF;
            END
            $$;
        SQL);
    }

    private function createScopeEnum(): void
    {
        $members = implode(',', array_map(static fn (string $m): string => "'{$m}'", self::SCOPE_MEMBERS));
        DB::connection(self::CONN)->statement(<<<SQL
            DO $$
            BEGIN
                IF NOT EXISTS (SELECT 1 FROM pg_type WHERE typname = 'BrLockScope') THEN
                    EXECUTE 'CREATE TYPE "BrLockScope" AS ENUM ({$members})';
                END IF;
            END
            $$;
        SQL);
    }

    private function createTable(): void
    {
        DB::connection(self::CONN)->statement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS "BrAdvisoryLockKeys" (
                "LockName"       TEXT           PRIMARY KEY,
                "Rank"           INTEGER        NOT NULL,
                "Kind"           "BrLockKind"   NOT NULL,
                "Scope"          "BrLockScope"  NOT NULL,
                "TimeoutMs"      INTEGER        NOT NULL,
                "HeldDuringNote" TEXT           NOT NULL,
                "CreatedAt"      TIMESTAMPTZ    NOT NULL DEFAULT NOW(),
                CONSTRAINT "CkBrAdvisoryLockKeysRank"     CHECK ("Rank" BETWEEN 1 AND 6),
                CONSTRAINT "CkBrAdvisoryLockKeysTimeout"  CHECK ("TimeoutMs" >= 0),
                CONSTRAINT "CkBrAdvisoryLockKeysRowKind"  CHECK (
                    ("Kind" = 'row_for_update_skip_locked' AND "Scope" = 'row' AND "TimeoutMs" = 0) OR
                    ("Kind" = 'pg_advisory_xact_lock'      AND "Scope" <> 'row' AND "TimeoutMs" > 0)
                )
            )
        SQL);
        DB::connection(self::CONN)->statement(
            'CREATE UNIQUE INDEX IF NOT EXISTS "UxBrAdvisoryLockKeysRank" ON "BrAdvisoryLockKeys" ("Rank")'
        );
    }

    private function applyGrants(): void
    {
        $this->grantIfRoleExists('authenticated', 'GRANT SELECT ON "BrAdvisoryLockKeys" TO authenticated');
        $this->grantIfRoleExists('service_role',  'GRANT ALL ON "BrAdvisoryLockKeys" TO service_role');
    }

    private function enableRls(): void
    {
        DB::connection(self::CONN)->statement('ALTER TABLE "BrAdvisoryLockKeys" ENABLE ROW LEVEL SECURITY');
        DB::connection(self::CONN)->statement(<<<'SQL'
            DO $$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1 FROM pg_policies
                    WHERE schemaname='public' AND tablename='BrAdvisoryLockKeys'
                      AND policyname='BrAdvisoryLockKeysReadable'
                ) THEN
                    EXECUTE 'CREATE POLICY "BrAdvisoryLockKeysReadable" ON "BrAdvisoryLockKeys" FOR SELECT TO authenticated USING (true)';
                END IF;
            END
            $$;
        SQL);
    }

    private function seedRegistry(): void
    {
        foreach (self::REGISTRY as $row) {
            DB::connection(self::CONN)->insert(
                'INSERT INTO "BrAdvisoryLockKeys" ("LockName","Rank","Kind","Scope","TimeoutMs","HeldDuringNote")
                 VALUES (?,?,?::"BrLockKind",?::"BrLockScope",?,?)
                 ON CONFLICT ("LockName") DO NOTHING',
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
