<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\BR\BrBackfillKind;
use App\Services\BR\BrBackfillService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Throwable;

/**
 * Plan 14 step 5c. Operator-facing entry point for the three day-one
 * Backup/Restore backfills. Runs under the `br.global` rank-1 advisory
 * xact lock via `BrBackfillService`. Idempotent per spec 25
 * §"Day-One Backfills" (each row `Idempotent = Yes`).
 *
 * Usage:
 *   php artisan br:backfill                              # run all three
 *   php artisan br:backfill --kind=BackfillAuditGenesis  # run just one
 *
 * Exit codes: 0 success, 1 failure (message + stack surfaced to stderr,
 * never swallowed).
 */
final class BrBackfillCommand extends Command
{
    protected $signature = 'br:backfill {--kind= : Optional single kind (BackupJobKind value)}';

    protected $description = 'Run BR day-one backfills (audit genesis, KEK epoch zero, snapshot pin counts).';

    public function handle(BrBackfillService $service): int
    {
        $requestId = 'br-backfill-' . Str::uuid()->toString();
        $kindOpt = $this->option('kind');
        try {
            $summary = $kindOpt ? $this->runOne($service, $kindOpt, $requestId) : $service->runAll($requestId);
        } catch (Throwable $e) {
            $this->error('br.backfill.failed: ' . $e->getMessage());

            return self::FAILURE;
        }
        $this->line(json_encode(['RequestId' => $requestId, 'Summary' => $summary], JSON_PRETTY_PRINT));

        return self::SUCCESS;
    }

    /** @return array<string, mixed> */
    private function runOne(BrBackfillService $service, string $kindValue, string $requestId): array
    {
        $kind = BrBackfillKind::tryFrom($kindValue);
        if ($kind === null) {
            throw new \InvalidArgumentException("Unknown backfill kind '{$kindValue}'.");
        }

        return [$kind->value => $service->run($kind, $requestId)];
    }
}
