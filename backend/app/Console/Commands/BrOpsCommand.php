<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\BR\BrOpsService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Throwable;

/**
 * Plan 14 step 6c. `br-ops` Artisan surface backing spec 26 runbooks.
 *
 * Read-only skeleton for S0. Verbs whose backing services have not yet
 * shipped (jobs:tail, jobs:fence, audit:freeze-pseudo,
 * audit:pseudo-preflight, audit:pseudonymise, archive:header,
 * archive:quarantine, archives:by-epoch) resolve to
 * `BrOpsService::assertNotYetImplemented()` so the command catalogue
 * matches the runbooks verbatim while destructive paths stay unreachable.
 *
 * Usage:
 *   php artisan br-ops jobs:list [--state=Running] [--limit=20]
 *   php artisan br-ops jobs:show <jobId>
 *   php artisan br-ops audit:verify --shard=<shardId> [--from=<rowId>]
 *   php artisan br-ops kek:epochs
 *   php artisan br-ops locks:holders
 */
final class BrOpsCommand extends Command
{
    private const READ_VERBS = ['jobs:list', 'jobs:show', 'audit:verify', 'kek:epochs', 'locks:holders'];
    private const MUTATE_VERBS = ['jobs:fence', 'archive:quarantine', 'locks:release', 'audit:pseudonymise'];
    private const STUB_VERBS = [
        'jobs:tail', 'audit:freeze-pseudo', 'audit:pseudo-preflight',
        'archive:header', 'archives:by-epoch',
    ];

    protected $signature = 'br-ops
        {verb : One of the closed-set BR ops verbs}
        {jobId? : Optional job UUID for jobs:show}
        {--state= : Filter for jobs:list}
        {--limit=20 : Row cap for jobs:list}
        {--shard= : Shard id for audit:verify}
        {--from= : Optional starting RowId for audit:verify}
        {--key= : Lock key for locks:release}
        {--reason= : Reason for locks:release}
        {--user= : User UUID for pseudonymise}
        {--basis= : Legal basis for pseudonymise}';

    protected $description = 'Backup/Restore operator surface (read-only inspectors; mutations stubbed pending Plan 14).';

    public function handle(BrOpsService $ops): int
    {
        $verb = (string) $this->argument('verb');
        $requestId = 'br-ops-' . Str::uuid()->toString();
        try {
            $payload = $this->dispatch($ops, $verb, $requestId);
        } catch (Throwable $e) {
            $this->error('br-ops.failed: ' . $e->getMessage());

            return self::FAILURE;
        }
        $this->line(json_encode(['RequestId' => $requestId, 'Verb' => $verb, 'Result' => $payload], JSON_PRETTY_PRINT));

        return self::SUCCESS;
    }

    /** @return array<string, mixed>|array<int, array<string, mixed>>|null */
    private function dispatch(BrOpsService $ops, string $verb, string $requestId): array|null
    {
        if (in_array($verb, self::STUB_VERBS, true)) {
            $ops->assertNotYetImplemented($verb);
        }
        if (in_array($verb, self::MUTATE_VERBS, true)) {
            return $this->runMutate($ops, $verb, $requestId);
        }
        if (in_array($verb, self::READ_VERBS, true) === false) {
            throw new \InvalidArgumentException("Unknown br-ops verb '{$verb}'.");
        }

        return $this->runRead($ops, $verb, $requestId);
    }

    /** @return array<string, mixed>|array<int, array<string, mixed>>|null */
    private function runRead(BrOpsService $ops, string $verb, string $requestId): array|null
    {
        return match ($verb) {
            'jobs:list'    => $ops->jobsList($this->stringOpt('state'), (int) $this->option('limit'), $requestId),
            'jobs:show'    => $ops->jobsShow($this->requiredArg('jobId'), $requestId),
            'audit:verify' => $ops->auditVerify($this->requiredOpt('shard'), $this->stringOpt('from'), $requestId),
            'kek:epochs'   => $ops->kekEpochs($requestId),
            'locks:holders'=> $ops->locksHolders($requestId),
        };
    }

    /** @return array<string, mixed>|array<int, array<string, mixed>>|null */
    private function runMutate(BrOpsService $ops, string $verb, string $requestId): array|null
    {
        return match ($verb) {
            'jobs:fence'         => $ops->jobsFence($this->requiredArg('jobId'), $requestId),
            'archive:quarantine' => $ops->archiveQuarantine($this->requiredArg('jobId'), $requestId), // using jobId as ingest-id
            'locks:release'      => $ops->locksRelease($this->requiredOpt('key'), $this->requiredOpt('reason'), $requestId),
            'audit:pseudonymise' => $ops->auditPseudonymise($this->requiredOpt('user'), $this->requiredOpt('basis'), $requestId),
        };
    }

    private function stringOpt(string $name): ?string
    {
        $v = $this->option($name);

        return is_string($v) && $v !== '' ? $v : null;
    }

    private function requiredOpt(string $name): string
    {
        $v = $this->stringOpt($name);
        if ($v === null) {
            throw new \InvalidArgumentException("Missing required --{$name} option.");
        }

        return $v;
    }

    private function requiredArg(string $name): string
    {
        $v = $this->argument($name);
        if (!is_string($v) || $v === '') {
            throw new \InvalidArgumentException("Missing required '{$name}' argument.");
        }

        return $v;
    }
}
