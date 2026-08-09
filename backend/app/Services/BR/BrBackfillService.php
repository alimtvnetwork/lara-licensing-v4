<?php

declare(strict_types=1);

namespace App\Services\BR;

use App\Exceptions\InternalException;


use App\Domain\BR\BrBackfillKind;
use App\Exceptions\LaraException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Ramsey\Uuid\Uuid;
use Throwable;

/**
 * Plan 14 step 5b. Day-one Backup/Restore backfill runner.
 *
 * Normative sources:
 *  - spec/26-backup-restore/25-migration-and-rollout.md v1.0.0
 *    §"Day-One Backfills" (three job kinds, rank-1..3, idempotent).
 *  - INV-BR-MG-7: backfill jobs run under `br.global` rank-1 advisory
 *    xact lock and MUST emit start + end audit rows.
 *  - INV-BR-MG-8: BR audit catalogue owns `backup.audit.chain_genesis`
 *    (owned by spec 25, emitted here).
 *  - INV-BR-AU-1..7: audit rows are append-only; the DB trigger
 *    `TrBackupAuditEventsHashChain` overwrites PrevHash/RowHash under a
 *    per-shard advisory lock, so this service inserts zero-filled
 *    placeholders and lets the trigger derive the real hashes.
 *  - INV-BR-IL-4: `pg_advisory_xact_lock` only, never
 *    `pg_advisory_lock`. INV-BR-IL-5: ascending-rank order (rank-1 first).
 *
 * Contract:
 *  - `run(kind)` opens a Root transaction, acquires the `br.global`
 *    rank-1 advisory xact lock, emits `backup.job.started`, performs
 *    the idempotent effect, emits the kind-specific end code (rank-1
 *    also emits `backup.audit.chain_genesis` for shards missing a
 *    genesis row), and commits.
 *  - `runAll()` iterates `BrBackfillKind::ordered()` (rank ascending).
 *  - All function bodies capped at 15 lines. Errors bubble as
 *    `LaraException` and are logged with a stable code before rethrow.
 */
final class BrBackfillService
{
    private const CONN = 'root';
    private const LOCK_NAME = 'br.global';
    private const AUDIT_TABLE = 'BackupAuditEvents';
    private const ZERO_HASH = "\\x0000000000000000000000000000000000000000000000000000000000000000";
    private const CODE_START = 'backup.job.started';
    private const CODE_COMPLETED = 'backup.job.completed';
    private const CODE_GENESIS = 'backup.audit.chain_genesis';
    private const ERR_BACKFILL_FAILED = 'BrBackfillFailed';

    /** @return array<string, array{shardsSeeded?:int, epochsRegistered?:int, snapshotsReset?:int}> */
    public function runAll(string $requestId): array
    {
        $summary = [];
        foreach (BrBackfillKind::ordered() as $kind) {
            $summary[$kind->value] = $this->run($kind, $requestId);
        }

        return $summary;
    }

    /** @return array{shardsSeeded?:int, epochsRegistered?:int, snapshotsReset?:int} */
    public function run(BrBackfillKind $kind, string $requestId): array
    {
        return DB::connection(self::CONN)->transaction(function () use ($kind, $requestId) {
            $this->acquireGlobalLock();
            $this->emitAudit(self::CODE_START, $kind, $requestId, ['Rank' => $kind->rank()]);
            try {
                $result = $this->dispatch($kind, $requestId);
            } catch (Throwable $e) {
                Log::error('br.backfill.failed', ['Kind' => $kind->value, 'RequestId' => $requestId, 'Reason' => $e->getMessage()]);
                throw InternalException::custom(self::ERR_BACKFILL_FAILED, $e->getMessage(), [['Field' => 'Kind', 'Rule' => 'Failed', 'Value' => $kind->value]]);
            }
            $this->emitAudit(self::CODE_COMPLETED, $kind, $requestId, $result);
            Log::info('br.backfill.completed', ['Kind' => $kind->value, 'RequestId' => $requestId, 'Result' => $result]);

            return $result;
        });
    }

    /** @return array{shardsSeeded?:int, epochsRegistered?:int, snapshotsReset?:int} */
    private function dispatch(BrBackfillKind $kind, string $requestId): array
    {
        return match ($kind) {
            BrBackfillKind::AuditGenesis      => ['shardsSeeded' => $this->backfillAuditGenesis($requestId)],
            BrBackfillKind::KekEpochZero      => ['epochsRegistered' => $this->backfillKekEpochZero()],
            BrBackfillKind::SnapshotPinCounts => ['snapshotsReset' => $this->backfillSnapshotPinCounts()],
        };
    }

    private function acquireGlobalLock(): void
    {
        // INV-BR-IL-4: xact-scoped only. Key derived via hashtext() so the
        // same string maps to the same bigint on every backend.
        DB::connection(self::CONN)->select(
            'SELECT pg_advisory_xact_lock(hashtext(?)) AS Acquired',
            [self::LOCK_NAME]
        );
    }

    private function backfillAuditGenesis(string $requestId): int
    {
        // At S0 there are no live shards yet; seed a single module-wide
        // anchor row keyed by `br.global` so downstream chain-verify has
        // a genesis to walk. Additional per-shard genesis rows are
        // emitted lazily by the audit trigger on first real event.
        $exists = DB::connection(self::CONN)->table(self::AUDIT_TABLE)
            ->where('ShardId', self::LOCK_NAME)
            ->where('Code', self::CODE_GENESIS)
            ->exists();
        if ($exists) {
            return 0;
        }
        $this->insertGenesisAudit(self::LOCK_NAME, $requestId);

        return 1;
    }

    private function insertGenesisAudit(string $shardId, string $requestId): void
    {
        DB::connection(self::CONN)->table(self::AUDIT_TABLE)->insert([
            'BackupAuditEventId' => Uuid::uuid7()->toString(),
            'OccurredAt'         => now(),
            'Code'               => self::CODE_GENESIS,
            'ActorKind'          => 'worker',
            'RequestId'          => $requestId,
            'Payload'            => json_encode(['Reason' => 'day_one_backfill', 'ShardId' => $shardId]),
            'PrevHash'           => DB::raw("'" . self::ZERO_HASH . "'::bytea"),
            'RowHash'            => DB::raw("'" . self::ZERO_HASH . "'::bytea"),
            'ShardId'            => $shardId,
            'SchemaVersion'      => 1,
        ]);
    }

    private function backfillKekEpochZero(): int
    {
        $existing = DB::connection(self::CONN)->table('BrKekEpochs')->where('Epoch', 0)->exists();
        if ($existing) {
            return 0;
        }
        DB::connection(self::CONN)->table('BrKekEpochs')->insert([
            'Epoch'       => 0,
            'Kid'         => 'epoch-0-2026-07',
            'State'       => 'Active',
            'ActivatedAt' => now(),
            'SecretsRef'  => 'br/kek/epoch-0',
        ]);

        return 1;
    }

    private function backfillSnapshotPinCounts(): int
    {
        // At S0 there are zero pin references; reconcile to 0 idempotently.
        return DB::connection(self::CONN)
            ->table('BackupSnapshots')
            ->where('PinCount', '<>', 0)
            ->update(['PinCount' => 0]);
    }

    private function emitAudit(string $code, BrBackfillKind $kind, string $requestId, array $payload): void
    {
        DB::connection(self::CONN)->table(self::AUDIT_TABLE)->insert([
            'BackupAuditEventId' => Uuid::uuid7()->toString(),
            'OccurredAt'         => now(),
            'Code'               => $code,
            'ActorKind'          => 'worker',
            'RequestId'          => $requestId,
            'Payload'            => json_encode(['Kind' => $kind->value] + $payload),
            'PrevHash'           => DB::raw("'" . self::ZERO_HASH . "'::bytea"),
            'RowHash'            => DB::raw("'" . self::ZERO_HASH . "'::bytea"),
            'ShardId'            => self::LOCK_NAME,
            'SchemaVersion'      => 1,
        ]);
    }
}
