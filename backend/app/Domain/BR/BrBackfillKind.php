<?php

declare(strict_types=1);

namespace App\Domain\BR;

/**
 * Closed-set of BR day-one backfill job kinds.
 *
 * Normative source:
 *   spec/26-backup-restore/25-migration-and-rollout.md v1.0.0
 *   §"Day-One Backfills" (table with `Order` column: 1..3).
 *
 * Values MUST match the `BackupJobKind` enum members added by migration
 * 2026_07_23_000011. No magic strings: consumers reference
 * `BrBackfillKind::AuditGenesis->value` when writing to `BackupJobs.Kind`.
 */
enum BrBackfillKind: string
{
    case AuditGenesis      = 'BackfillAuditGenesis';
    case KekEpochZero      = 'BackfillKekEpochZero';
    case SnapshotPinCounts = 'BackfillSnapshotPinCounts';

    /** Rank per spec 25 §"Day-One Backfills" `Order` column (1..3). */
    public function rank(): int
    {
        return match ($this) {
            self::AuditGenesis      => 1,
            self::KekEpochZero      => 2,
            self::SnapshotPinCounts => 3,
        };
    }

    /**
     * Ordered list by rank so the runner can iterate without re-sorting.
     * @return array<int, self>
     */
    public static function ordered(): array
    {
        return [self::AuditGenesis, self::KekEpochZero, self::SnapshotPinCounts];
    }
}
