<?php

declare(strict_types=1);

namespace App\Console\Commands\BR;

use App\Services\BR\BrExportWorker;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Ramsey\Uuid\Uuid;
use Throwable;

/**
 * Plan 14 step 10. `php artisan lara:br:worker:export`
 *
 * Drains queued `BackupJobs` of `Kind='Export'` up to `--max` rows.
 * Stops early when the dispatcher returns null (no work). Never loops
 * forever; each invocation is bounded so cron/scheduler can retry.
 *
 * Flags:
 *   --max=N        Process at most N jobs this invocation (default 1).
 *   --request-id=  Correlate this worker invocation with a specific
 *                  RequestId in logs; defaults to a UUIDv7.
 *
 * Exit codes:
 *   0  Success (0..N jobs processed).
 *   1  Fatal (uncaught throwable escaped `runOnce`).
 */
final class BrExportWorkerCommand extends Command
{
    protected $signature = 'lara:br:worker:export
        {--max=1 : Maximum number of jobs to process this invocation.}
        {--request-id= : Correlation id logged with every dequeue.}';

    protected $description = 'Drain queued Backup Export jobs (Plan 14 step 10, S1 shadow).';

    public function handle(BrExportWorker $worker): int
    {
        $max = max(1, (int) $this->option('max'));
        $requestId = $this->resolveRequestId();
        $processed = 0;
        $summary = [];
        for ($i = 0; $i < $max; $i++) {
            $outcome = $this->runOne($worker, $requestId, $i);
            if ($outcome === null) {
                break;
            }
            $processed++;
            $summary[] = $outcome;
        }
        $this->line(json_encode(['RequestId' => $requestId, 'Processed' => $processed, 'Outcomes' => $summary], JSON_PRETTY_PRINT));
        Log::info('br.export.worker.invocation_done', ['RequestId' => $requestId, 'Processed' => $processed, 'MaxRequested' => $max]);

        return self::SUCCESS;
    }

    private function resolveRequestId(): string
    {
        $opt = (string) ($this->option('request-id') ?? '');
        if ($opt !== '') {
            return $opt;
        }

        return 'br-export-worker-' . Uuid::uuid7()->toString();
    }

    private function runOne(BrExportWorker $worker, string $requestId, int $index): ?string
    {
        try {
            return $worker->runOnce($requestId);
        } catch (Throwable $e) {
            Log::error('br.export.worker.uncaught', ['RequestId' => $requestId, 'Index' => $index, 'Exception' => $e->getMessage()]);
            $this->error('br.export.worker.uncaught: ' . $e->getMessage());

            return 'Failed';
        }
    }
}
