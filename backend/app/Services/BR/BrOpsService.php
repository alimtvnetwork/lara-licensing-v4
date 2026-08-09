<?php

declare(strict_types=1);

namespace App\Services\BR;

use App\Exceptions\InternalException;


use App\Exceptions\LaraException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\File;
use Ramsey\Uuid\Uuid;

/**
 * Plan 14 step 6. Read-only Backup/Restore ops surface backing the
 * `br-ops` Artisan command (spec 26 §"Runbooks" and INV-BR-RB-3).
 *
 * Scope for S0 (skeleton): inspection-only helpers over `BackupJobs`,
 * `BackupAuditEvents`, `BrKekEpochs`, and `pg_locks`. Mutating verbs
 * (`jobs:fence`, `audit:pseudonymise`, etc.) are declared in the CLI
 * surface but throw `BrOpsNotYetImplemented` until their backing
 * services ship in later Plan 14 steps. This preserves the closed-set
 * command catalogue the runbooks reference while preventing any
 * destructive write path from being reachable before its invariants
 * are enforceable.
 *
 * Contract:
 *  - Every read runs on the Root connection with an explicit
 *    `statement_timeout` so a hot table cannot hang an operator shell.
 *  - Every call logs `br.ops.<verb>` on entry with the caller-supplied
 *    `RequestId`; failures rethrow `LaraException('BrOpsQueryFailed')`
 *    with the offending verb captured, never silently swallowed.
 *  - No mutation. No advisory locks. Read-committed.
 */
final class BrOpsService
{
    private const CONN = 'root';
    private const STMT_TIMEOUT_MS = 5000;
    private const ERR_QUERY_FAILED = 'BrOpsQueryFailed';
    private const ERR_NOT_IMPL = 'BrOpsNotYetImplemented';
    private const LOG_PREFIX = 'br.ops.';

    /** @return array<int, array<string, mixed>> */
    public function jobsList(?string $state, int $limit, string $requestId): array
    {
        return $this->readMany('jobs:list', $requestId, function () use ($state, $limit) {
            $sql = 'SELECT "Id","Kind","State","RequestId","CreatedAt","StartedAt","FinishedAt"'
                . ' FROM "BackupJobs"'
                . ($state !== null ? ' WHERE "State" = ?' : '')
                . ' ORDER BY "CreatedAt" DESC LIMIT ?';
            $bindings = $state !== null ? [$state, $limit] : [$limit];

            return DB::connection(self::CONN)->select($sql, $bindings);
        });
    }

    /** @return array<string, mixed>|null */
    public function jobsShow(string $jobId, string $requestId): ?array
    {
        $rows = $this->readMany('jobs:show', $requestId, function () use ($jobId) {
            return DB::connection(self::CONN)->select('SELECT * FROM "BackupJobs" WHERE "Id" = ?', [$jobId]);
        });

        return $rows[0] ?? null;
    }

    /** @return array{ShardId:string, RowCount:int, HeadCode:?string, HeadRowHashHex:?string} */
    public function auditVerify(string $shardId, ?string $fromRowId, string $requestId): array
    {
        $rows = $this->readMany('audit:verify', $requestId, function () use ($shardId, $fromRowId) {
            $sql = 'SELECT COUNT(*)::int AS "RowCount",'
                . ' MAX("Code") FILTER (WHERE TRUE) AS "HeadCode",'
                . ' ENCODE(MAX("RowHash"), \'hex\') AS "HeadRowHashHex"'
                . ' FROM "BackupAuditEvents" WHERE "ShardId" = ?'
                . ($fromRowId !== null ? ' AND "RowId" >= ?' : '');
            $bindings = $fromRowId !== null ? [$shardId, $fromRowId] : [$shardId];

            return DB::connection(self::CONN)->select($sql, $bindings);
        });

        return ['ShardId' => $shardId] + (array) $rows[0];
    }

    /** @return array<int, array<string, mixed>> */
    public function kekEpochs(string $requestId): array
    {
        return $this->readMany('kek:epochs', $requestId, function () {
            return DB::connection(self::CONN)->select(
                'SELECT "Epoch","Kid","State","ActivatedAt","RetiredAt","SecretsRef" FROM "BrKekEpochs" ORDER BY "Epoch" ASC'
            );
        });
    }

    /** @return array<int, array<string, mixed>> */
    public function locksHolders(string $requestId): array
    {
        return $this->readMany('locks:holders', $requestId, function () {
            return DB::connection(self::CONN)->select(
                'SELECT locktype, classid, objid, pid, mode, granted FROM pg_locks'
                . " WHERE locktype = 'advisory' ORDER BY pid, classid, objid"
            );
        });
    }

    public function assertNotYetImplemented(string $verb): never
    {
        Log::warning(self::LOG_PREFIX . 'not_yet_implemented', ['Verb' => $verb]);
        throw InternalException::custom(self::ERR_NOT_IMPL, "br-ops verb '{$verb}' has no backing service yet.", [
            ['Field' => 'Verb', 'Rule' => 'NotYetImplemented', 'Value' => $verb],
        ]);
    }

    /**
     * @param  callable():array<int, object>  $query
     * @return array<int, array<string, mixed>>
     */
    private function readMany(string $verb, string $requestId, callable $query): array
    {
        Log::info(self::LOG_PREFIX . $verb, ['RequestId' => $requestId]);
        try {
            DB::connection(self::CONN)->statement('SET LOCAL statement_timeout = ' . self::STMT_TIMEOUT_MS);
            $rows = $query();
        } catch (\Throwable $e) {
            Log::error(self::LOG_PREFIX . 'query_failed', ['Verb' => $verb, 'RequestId' => $requestId, 'Reason' => $e->getMessage()]);
            throw InternalException::custom(self::ERR_QUERY_FAILED, $e->getMessage(), [['Field' => 'Verb', 'Rule' => 'QueryFailed', 'Value' => $verb]]);
        }

        return array_map(static fn ($row) => (array) $row, $rows);
    }

    public function jobsFence(string $jobId, string $requestId): array
    {
        return DB::connection(self::CONN)->transaction(function () use ($jobId, $requestId) {
            DB::connection(self::CONN)->select('SELECT pg_advisory_xact_lock(hashtext(?))', ['br.global']);
            $job = DB::connection(self::CONN)->table('BackupJobs')->where('Id', $jobId)->first();
            $isFailed = !$job;
            if ($isFailed) {
                throw new \InvalidArgumentException("Job not found: {$jobId}");
            }
            if ($job->State !== 'Running') {
                throw new \InvalidArgumentException("Cannot fence job in state: {$job->State}");
            }

            DB::connection(self::CONN)->table('BackupJobs')->where('Id', $jobId)->update([
                'State'      => 'Failed',
                'Reason'     => 'fenced_by_operator',
                'UpdatedAt'  => now(),
                'FinishedAt' => now(),
            ]);

            $this->emitAudit('backup.job.fenced', $job->TenantId ?? 'br.global', $requestId, ['JobId' => $jobId]);
            Log::info(self::LOG_PREFIX . 'jobs:fence', ['JobId' => $jobId, 'RequestId' => $requestId]);

            return ['JobId' => $jobId, 'Status' => 'fenced'];
        });
    }

    public function archiveQuarantine(string $jobId, string $requestId): array
    {
        $archiveRoot = rtrim(config('lara.br.archive_root', ''), DIRECTORY_SEPARATOR);
        $quarantineRoot = rtrim(config('lara.br.quarantine_root', storage_path('app/br/quarantine')), DIRECTORY_SEPARATOR);

        if ($archiveRoot === '' || $quarantineRoot === '') {
            throw new \RuntimeException("Archive or quarantine root not configured.");
        }

        $source = $archiveRoot . DIRECTORY_SEPARATOR . $jobId;
        $dest = $quarantineRoot . DIRECTORY_SEPARATOR . $jobId;

        if (is_dir($source) === false) {
            throw new \InvalidArgumentException("Archive not found: {$source}");
        }
        if (is_dir($quarantineRoot) === false) {
            @mkdir($quarantineRoot, 0700, true);
        }

        File::moveDirectory($source, $dest, true);
        Log::info(self::LOG_PREFIX . 'archive:quarantine', ['JobId' => $jobId, 'Dest' => $dest, 'RequestId' => $requestId]);

        return ['JobId' => $jobId, 'Dest' => $dest, 'Status' => 'quarantined'];
    }

    public function locksRelease(string $key, string $reason, string $requestId): array
    {
        if ($reason !== 'zombie') {
            throw new \InvalidArgumentException("Only 'zombie' reason is supported for locks:release.");
        }

        return DB::connection(self::CONN)->transaction(function () use ($key, $requestId) {
            DB::connection(self::CONN)->select('SELECT pg_advisory_xact_lock(hashtext(?))', ['br.global']);

            $pids = DB::connection(self::CONN)->select(
                "SELECT pid FROM pg_locks WHERE locktype = 'advisory' AND objid = hashtext(?) AND granted = true",
                [$key]
            );

            $terminated = [];
            foreach ($pids as $row) {
                DB::connection(self::CONN)->select("SELECT pg_terminate_backend(?)", [$row->pid]);
                $terminated[] = $row->pid;
            }

            if (empty($terminated)) {
                throw new \InvalidArgumentException("No locks found for key: {$key}");
            }

            $this->emitAudit('backup.lock.zombie_released', 'br.global', $requestId, ['LockKey' => $key, 'Pids' => $terminated]);
            Log::info(self::LOG_PREFIX . 'locks:release', ['LockKey' => $key, 'TerminatedPids' => $terminated, 'RequestId' => $requestId]);

            return ['LockKey' => $key, 'TerminatedPids' => $terminated, 'Status' => 'released'];
        });
    }

    public function auditPseudonymise(string $userUuid, string $basis, string $requestId): array
    {
        if (config('lara.br.audit_pseudonymise_enabled', false) === false) {
            throw new \RuntimeException("Pseudonymisation is disabled (br.audit-pseudonymise.enabled=false).");
        }

        return DB::connection(self::CONN)->transaction(function () use ($userUuid, $basis, $requestId) {
            DB::connection(self::CONN)->select('SELECT pg_advisory_xact_lock(hashtext(?))', ['br.global']);

            DB::connection(self::CONN)->statement(
                'SELECT "FnPseudonymiseActor"(?::uuid, ?::text)',
                [$userUuid, $basis]
            );

            $this->emitAudit('backup.audit.pseudonymised', 'br.global', $requestId, [
                'UserUuid' => $userUuid,
                'LegalBasis' => $basis,
            ]);

            Log::info(self::LOG_PREFIX . 'audit:pseudonymise', ['UserUuid' => $userUuid, 'RequestId' => $requestId]);

            return ['UserUuid' => $userUuid, 'Status' => 'pseudonymised'];
        });
    }

    private function emitAudit(string $code, string $shardId, string $requestId, array $payload): void
    {
        DB::connection(self::CONN)->table('BackupAuditEvents')->insert([
            'BackupAuditEventId' => Uuid::uuid7()->toString(),
            'OccurredAt'         => now(),
            'Code'               => $code,
            'ActorKind'          => 'operator',
            'RequestId'          => $requestId,
            'Payload'            => json_encode($payload),
            'PrevHash'           => DB::raw("'\\x0000000000000000000000000000000000000000000000000000000000000000'::bytea"),
            'RowHash'            => DB::raw("'\\x0000000000000000000000000000000000000000000000000000000000000000'::bytea"),
            'ShardId'            => $shardId,
            'SchemaVersion'      => 1,
        ]);
    }
}
