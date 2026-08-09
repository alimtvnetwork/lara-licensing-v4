<?php

declare(strict_types=1);

namespace App\Domain\BR;

/**
 * Plan 14 step 10. Per-kind retry policy for BR jobs.
 *
 * Normative source: spec/26-backup-restore/15-jobs-and-progress.md v1.0.0
 * §"Retry Policy". `maxAttempts` MUST match the DB default on
 * `BackupJobs.MaxAttempts` (currently 3) for Export/Snapshot; Restore
 * and retention_sweep are max=1 per INV-BR-JP-5.
 *
 * Backoff seconds are consumed by `BrJobDispatcher::retryable()` which
 * computes `WorkerLeaseUntil = now + BACKOFF[attemptCount - 1]` on
 * transient failure. Attempt indexing is 1-based (first dequeue sets
 * AttemptCount=1); backoff array is 0-based.
 */
final class BrRetryPolicy
{
    /** @var array<string, array{max:int, backoff:list<int>}> */
    private const POLICY = [
        'backup.export'            => ['max' => 3, 'backoff' => [5, 30, 120]],
        'backup.import'            => ['max' => 2, 'backoff' => [10, 60]],
        'snapshot.create'          => ['max' => 3, 'backoff' => [5, 30, 120]],
        'snapshot.retention_sweep' => ['max' => 1, 'backoff' => []],
        'backup.restore'           => ['max' => 1, 'backoff' => []],
        'snapshot.restore'         => ['max' => 1, 'backoff' => []],
    ];

    /** @var array<string, string> BrJobKind -> spec kind slug used by POLICY. */
    private const KIND_SLUG = [
        'Export'          => 'backup.export',
        'Import'          => 'backup.import',
        'SnapshotCreate'  => 'snapshot.create',
        'SnapshotRestore' => 'snapshot.restore',
        'Restore'         => 'backup.restore',
    ];

    public static function maxAttemptsFor(BrJobKind $kind): int
    {
        $slug = self::KIND_SLUG[$kind->value] ?? null;
        if ($slug === null || !isset(self::POLICY[$slug])) {
            return 1;
        }

        return self::POLICY[$slug]['max'];
    }

    /**
     * Backoff seconds for the given kind + 1-based attempt count.
     * Returns null when the attempt is beyond the configured backoff array,
     * which the dispatcher treats as "no more retries; go Failed".
     */
    public static function backoffSecondsFor(BrJobKind $kind, int $attemptCount): ?int
    {
        $slug = self::KIND_SLUG[$kind->value] ?? null;
        if ($slug === null || !isset(self::POLICY[$slug])) {
            return null;
        }
        $backoff = self::POLICY[$slug]['backoff'];
        $index = $attemptCount - 1;

        return $backoff[$index] ?? null;
    }
}
