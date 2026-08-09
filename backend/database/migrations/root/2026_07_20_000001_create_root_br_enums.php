<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Plan 14 step 1a. Root DB Backup/Restore enum types.
 *
 * Normative sources:
 *  - spec/26-backup-restore/15-jobs-and-progress.md v1.0.0 §"Job Row Schema"
 *    (closed set of `state` values: Queued, Running, Succeeded, Failed,
 *    Cancelled) and §"Job Kinds" (Export, Import, Restore, SnapshotCreate,
 *    SnapshotRestore).
 *  - spec/26-backup-restore/25-migration-and-rollout.md v1.0.0 §"Migration
 *    Order" migration 1 (`br_enums`) as the first BR migration, reversible.
 *
 * Placement: root DB. Jobs are global (single primary queue) per spec 15
 * §"Job Row Schema" "jobs are global, not shard-partitioned". Root is
 * therefore the correct home for the enum types the `BackupJobs` table
 * consumes.
 *
 * Naming convention: this project uses PascalCase for DB identifiers per
 * project memory (Core rule) and consistently across `IdempotencyRecords`,
 * `LicenseTiers`, `Licenses`, etc. Spec files 15 and 16 (AI Confidence
 * Draft) use camelCase in prose; the physical schema below is the
 * authoritative form, and the spec text will be reconciled in a later step
 * before those files leave Draft per `INV-BR-OQ-6`.
 *
 * Reversibility: enum type drops are safe here because no BackupJobs row
 * exists at this point in the ladder (migration 2 introduces the table).
 */
return new class extends Migration
{
    private const CONN = 'root';

    private const KIND_MEMBERS = [
        'Export',
        'Import',
        'Restore',
        'SnapshotCreate',
        'SnapshotRestore',
    ];

    private const STATE_MEMBERS = [
        'Queued',
        'Running',
        'Succeeded',
        'Failed',
        'Cancelled',
    ];

    public function up(): void
    {
        $this->createEnumIfMissing('BackupJobKind', self::KIND_MEMBERS);
        $this->createEnumIfMissing('BackupJobState', self::STATE_MEMBERS);
    }

    public function down(): void
    {
        DB::connection(self::CONN)->statement('DROP TYPE IF EXISTS "BackupJobState"');
        DB::connection(self::CONN)->statement('DROP TYPE IF EXISTS "BackupJobKind"');
    }

    private function createEnumIfMissing(string $name, array $members): void
    {
        $quoted = implode(',', array_map(static fn (string $m): string => "'{$m}'", $members));
        DB::connection(self::CONN)->statement(<<<SQL
            DO $$
            BEGIN
                IF NOT EXISTS (SELECT 1 FROM pg_type WHERE typname = '{$name}') THEN
                    CREATE TYPE "{$name}" AS ENUM ({$quoted});
                END IF;
            END
            $$;
        SQL);
    }
};
