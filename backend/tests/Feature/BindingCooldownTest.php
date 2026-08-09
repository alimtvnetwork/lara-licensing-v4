<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\LaraException;
use App\Services\BindingService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Plan 06 step 53. Locks spec/21-app/30-machine-bindings.md invariants
 * for BindingService::applyMachineBinding:
 *
 *   AC-MB-003: LicenseMachineLimit (409) when active binding count for
 *              a LicenseId already equals MaxConcurrentBindings.
 *   AC-MB-004: No-op refresh path. Existing active row for the same
 *              (LicenseId, FingerprintHash) returns the same
 *              MachineBindingId and updates LastSeenAt without insert.
 *   AC-MB-006: MachineRebindCooldownActive (409) when a released row
 *              for the same fingerprint still has RebindCooldownUntil
 *              in the future.
 *   AC-MB-007: Post-cooldown re-INSERT lands as a new row rather than
 *              mutating the released historical row.
 *
 * Uses a sqlite-portable MachineBindings table mirroring the Postgres
 * column contract; matches the pattern established by IdempotencyTest
 * and RestoreOnceTest.
 */
final class BindingCooldownTest extends TestCase
{
    private const LICENSE_ID = 501;
    private const FP_A = 'aaaa1111bbbb2222cccc3333dddd4444eeee5555ffff6666aaaa7777bbbb8888';
    private const FP_B = 'bbbb1111cccc2222dddd3333eeee4444ffff5555aaaa6666bbbb7777cccc8888';

    protected function setUp(): void
    {
        parent::setUp();
        $this->createMachineBindingsTable();
    }

    public function test_existing_active_binding_returns_same_id_and_refreshes_last_seen(): void
    {
        $earlier = Carbon::now()->subHour()->toDateTimeString();
        $id = DB::connection('shard')->table('MachineBindings')->insertGetId([
            'LicenseId' => self::LICENSE_ID,
            'FingerprintHash' => self::FP_A,
            'FirstSeenAt' => $earlier,
            'LastSeenAt' => $earlier,
            'ReleasedAt' => null,
            'RebindCooldownUntil' => null,
            'CreatedAt' => $earlier,
            'UpdatedAt' => $earlier,
        ], 'MachineBindingId');
        $service = $this->app->make(BindingService::class);
        $returned = $service->applyMachineBinding(self::LICENSE_ID, self::FP_A, 1, 'req-refresh');
        $this->assertSame((int) $id, $returned);
        $row = DB::connection('shard')->table('MachineBindings')->where('MachineBindingId', $id)->first();
        $this->assertNotSame($earlier, (string) $row->LastSeenAt, 'LastSeenAt must be refreshed.');
        $this->assertSame(1, DB::connection('shard')->table('MachineBindings')->count(),
            'No-op refresh must not INSERT a new row.');
    }

    public function test_cooldown_active_blocks_rebind_with_409_code(): void
    {
        $released = Carbon::now()->subMinutes(5)->toDateTimeString();
        $cooldownUntil = Carbon::now()->addMinutes(10)->toDateTimeString();
        DB::connection('shard')->table('MachineBindings')->insert([
            'LicenseId' => self::LICENSE_ID,
            'FingerprintHash' => self::FP_A,
            'FirstSeenAt' => $released,
            'LastSeenAt' => $released,
            'ReleasedAt' => $released,
            'RebindCooldownUntil' => $cooldownUntil,
            'CreatedAt' => $released,
            'UpdatedAt' => $released,
        ]);
        $service = $this->app->make(BindingService::class);
        try {
            $service->applyMachineBinding(self::LICENSE_ID, self::FP_A, 1, 'req-cooldown');
            $this->fail('Expected MachineRebindCooldownActive.');
        } catch (LaraException $e) {
            $this->assertSame('MachineRebindCooldownActive', $e->errorCode);
        }
        $this->assertSame(1, DB::connection('shard')->table('MachineBindings')->count(),
            'Cooldown block must not INSERT a new active row.');
    }

    public function test_quota_exceeded_blocks_new_fingerprint(): void
    {
        $now = Carbon::now()->toDateTimeString();
        DB::connection('shard')->table('MachineBindings')->insert([
            'LicenseId' => self::LICENSE_ID,
            'FingerprintHash' => self::FP_A,
            'FirstSeenAt' => $now,
            'LastSeenAt' => $now,
            'ReleasedAt' => null,
            'RebindCooldownUntil' => null,
            'CreatedAt' => $now,
            'UpdatedAt' => $now,
        ]);
        $service = $this->app->make(BindingService::class);
        try {
            $service->applyMachineBinding(self::LICENSE_ID, self::FP_B, 1, 'req-quota');
            $this->fail('Expected LicenseMachineLimit.');
        } catch (LaraException $e) {
            $this->assertSame('LicenseMachineLimit', $e->errorCode);
        }
    }

    public function test_post_cooldown_reinsert_creates_new_row(): void
    {
        $past = Carbon::now()->subHour()->toDateTimeString();
        $cooldownExpired = Carbon::now()->subMinutes(1)->toDateTimeString();
        DB::connection('shard')->table('MachineBindings')->insert([
            'LicenseId' => self::LICENSE_ID,
            'FingerprintHash' => self::FP_A,
            'FirstSeenAt' => $past,
            'LastSeenAt' => $past,
            'ReleasedAt' => $past,
            'RebindCooldownUntil' => $cooldownExpired,
            'CreatedAt' => $past,
            'UpdatedAt' => $past,
        ]);
        $service = $this->app->make(BindingService::class);
        $newId = $service->applyMachineBinding(self::LICENSE_ID, self::FP_A, 1, 'req-reinsert');
        $this->assertGreaterThan(0, $newId);
        $this->assertSame(2, DB::connection('shard')->table('MachineBindings')->count(),
            'Post-cooldown rebind must land as a new row (AC-MB-007).');
        $active = DB::connection('shard')->table('MachineBindings')
            ->whereNull('ReleasedAt')->where('FingerprintHash', self::FP_A)->count();
        $this->assertSame(1, $active, 'Exactly one active row after re-INSERT.');
    }

    private function createMachineBindingsTable(): void
    {
        DB::connection('shard')->statement(
            'CREATE TABLE IF NOT EXISTS "MachineBindings" (
                "MachineBindingId" INTEGER PRIMARY KEY AUTOINCREMENT,
                "LicenseId" INTEGER NOT NULL,
                "FingerprintHash" TEXT NOT NULL,
                "FirstSeenAt" TEXT NOT NULL,
                "LastSeenAt" TEXT NOT NULL,
                "ReleasedAt" TEXT NULL,
                "RebindCooldownUntil" TEXT NULL,
                "CreatedAt" TEXT NOT NULL,
                "UpdatedAt" TEXT NOT NULL
            )'
        );
    }
}
