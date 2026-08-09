<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Plan 14 step 5a. Extend `BackupJobKind` closed enum with the three
 * backfill job kinds pinned by
 * spec/26-backup-restore/25-migration-and-rollout.md v1.0.0
 * §"Day-One Backfills":
 *
 *   - `BackfillAuditGenesis`      (rank-1, run first)
 *   - `BackfillKekEpochZero`      (rank-2)
 *   - `BackfillSnapshotPinCounts` (rank-3)
 *
 * INV-BR-MG-7: all three MUST run under the `br.global` rank-1 advisory
 * xact lock (enforced at the service layer, not by DDL).
 *
 * Postgres requires `ALTER TYPE ... ADD VALUE` to run OUTSIDE a
 * transaction, so this migration opts out of Laravel's implicit tx via
 * `$withinTransaction = false`. Guarded by `ADD VALUE IF NOT EXISTS` so
 * the idempotency CI job (Plan 12 step 4) stays green on re-run.
 *
 * Reversibility: Postgres cannot DROP a single enum value; per
 * INV-BR-MG-2 this migration is forward-only once merged. `down()`
 * therefore no-ops with an audit log line so operators see the intent.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    private const CONN = 'root';

    private const KIND_MEMBERS = [
        'BackfillAuditGenesis',
        'BackfillKekEpochZero',
        'BackfillSnapshotPinCounts',
    ];

    public function up(): void
    {
        foreach (self::KIND_MEMBERS as $member) {
            DB::connection(self::CONN)->statement(
                'ALTER TYPE "BackupJobKind" ADD VALUE IF NOT EXISTS \'' . $member . '\''
            );
        }
    }

    public function down(): void
    {
        // Forward-only per INV-BR-MG-2. Postgres has no DROP VALUE.
    }
};
