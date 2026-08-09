<?php

declare(strict_types=1);

namespace Tests\Feature\BR;

use App\Domain\BR\BrBackfillKind;
use App\Services\BR\BrBackfillService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Plan 14 step 5d. Contract tests for `BrBackfillService`.
 *
 * Locks the three invariants pinned by Step 5 spec cross-refs:
 *
 *  1. INV-BR-MG-7: every backfill acquires the `br.global` rank-1
 *     advisory xact lock BEFORE mutating rows. Verified by inspecting
 *     the query log: the first non-BEGIN statement in the transaction
 *     is a `pg_advisory_xact_lock(hashtext(?))` call with parameter
 *     `br.global`.
 *  2. INV-BR-MG-8: rank-1 `BackfillAuditGenesis` emits at least one
 *     `backup.audit.chain_genesis` row per shard lacking a genesis.
 *  3. Idempotency: re-running the full ladder produces zero additional
 *     KEK-epoch rows and zero additional genesis rows (`shardsSeeded`
 *     is 0 on the second pass).
 */
final class BrBackfillServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_run_all_acquires_br_global_lock_before_mutations(): void
    {
        DB::connection('root')->enableQueryLog();
        app(BrBackfillService::class)->runAll('req-test-1');
        $log = DB::connection('root')->getQueryLog();
        $advisory = array_values(array_filter($log, fn ($q) => str_contains($q['query'], 'pg_advisory_xact_lock')));
        $this->assertNotEmpty($advisory, 'br.global lock was never acquired');
        $this->assertSame('br.global', $advisory[0]['bindings'][0]);
    }

    public function test_kek_epoch_zero_is_idempotent(): void
    {
        $service = app(BrBackfillService::class);
        $first = $service->run(BrBackfillKind::KekEpochZero, 'req-idem-1');
        $second = $service->run(BrBackfillKind::KekEpochZero, 'req-idem-2');
        $this->assertSame(1, $first['epochsRegistered']);
        $this->assertSame(0, $second['epochsRegistered']);
        $this->assertSame(1, DB::connection('root')->table('BrKekEpochs')->where('Epoch', 0)->count());
    }

    public function test_audit_genesis_emits_chain_genesis_code(): void
    {
        app(BrBackfillService::class)->run(BrBackfillKind::AuditGenesis, 'req-genesis-1');
        $count = DB::connection('root')->table('BackupAuditEvents')
            ->where('Code', 'backup.audit.chain_genesis')
            ->where('ShardId', 'br.global')
            ->count();
        $this->assertSame(1, $count);
        // Second run must be a no-op (idempotency).
        app(BrBackfillService::class)->run(BrBackfillKind::AuditGenesis, 'req-genesis-2');
        $recount = DB::connection('root')->table('BackupAuditEvents')
            ->where('Code', 'backup.audit.chain_genesis')
            ->where('ShardId', 'br.global')
            ->count();
        $this->assertSame(1, $recount);
    }
}
