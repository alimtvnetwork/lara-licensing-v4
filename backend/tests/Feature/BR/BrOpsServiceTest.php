<?php

declare(strict_types=1);

namespace Tests\Feature\BR;

use App\Exceptions\LaraException;
use App\Services\BR\BrOpsService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Plan 14 step 6d. Contract tests for the `br-ops` read-only surface.
 *
 * Locks:
 *  - `assertNotYetImplemented` throws `BrOpsNotYetImplemented` (never
 *    swallows, never falls through to a silent no-op) so runbook verbs
 *    that lack a backing service cannot be confused with success.
 *  - `kekEpochs` returns rows ordered by `Epoch` ASC (spec 26 §"Key
 *    epochs" requires ascending scan for retirement drills).
 *  - `auditVerify` returns the shard's row count and head hash without
 *    acquiring any advisory lock (INV-BR-IL-4 companion: reads never
 *    take `pg_advisory_lock` / `pg_advisory_xact_lock`).
 */
final class BrOpsServiceTest extends TestCase
{
    public function test_stub_verb_throws_not_yet_implemented(): void
    {
        $ops = app(BrOpsService::class);
        $this->expectException(LaraException::class);
        $this->expectExceptionMessageMatches('/jobs:fence/');
        $ops->assertNotYetImplemented('jobs:fence');
    }

    public function test_kek_epochs_returns_ascending_rows(): void
    {
        $ops = app(BrOpsService::class);
        $rows = $ops->kekEpochs('br-ops-test-kek');
        $epochs = array_map(static fn (array $r) => (int) $r['Epoch'], $rows);
        $sorted = $epochs;
        sort($sorted);
        $this->assertSame($sorted, $epochs, 'BrKekEpochs must be returned Epoch-ascending.');
    }

    public function test_audit_verify_does_not_acquire_advisory_lock(): void
    {
        DB::connection('root')->enableQueryLog();
        DB::connection('root')->flushQueryLog();
        app(BrOpsService::class)->auditVerify('br.global', null, 'br-ops-test-verify');
        $log = DB::connection('root')->getQueryLog();
        foreach ($log as $entry) {
            $this->assertStringNotContainsString('pg_advisory_lock', $entry['query']);
            $this->assertStringNotContainsString('pg_advisory_xact_lock', $entry['query']);
        }
    }
}
