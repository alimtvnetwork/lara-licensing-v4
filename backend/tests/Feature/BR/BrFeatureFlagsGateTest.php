<?php

declare(strict_types=1);

namespace Tests\Feature\BR;

use App\Domain\BR\BrFlagId;
use App\Domain\BR\BrFlagValue;
use App\Exceptions\LaraException;
use App\Services\BR\BrFeatureFlagService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Plan 14 step 4 contract test.
 *
 * Locks INV-BR-MG-3: a disabled feature flag MUST short-circuit BEFORE
 * any advisory-lock acquisition, so a disabled feature can never mutate
 * state.
 *
 * The test enables DB query logging, invokes `assertEnabled` on an off
 * flag, and asserts:
 *  - `LaraException` is thrown with code `FeatureNotAvailable`.
 *  - No `pg_advisory_xact_lock` statement was executed between the
 *    service invocation and the throw (INV-BR-IL-4 companion check).
 */
final class BrFeatureFlagsGateTest extends TestCase
{
    public function test_seed_rows_default_off_for_every_br_flag(): void
    {
        foreach (BrFlagId::cases() as $flag) {
            $value = DB::connection('root')->table('FeatureFlags')
                ->where('FlagId', $flag->value)->value('Value');
            $this->assertSame(BrFlagValue::Off->value, $value, "Flag {$flag->value} must seed as off.");
        }
    }

    public function test_assert_enabled_throws_before_advisory_lock_when_off(): void
    {
        $service = app(BrFeatureFlagService::class);
        DB::connection('root')->enableQueryLog();
        try {
            $service->assertEnabled(BrFlagId::ExportEnabled);
            $this->fail('assertEnabled must throw when flag is off.');
        } catch (LaraException $e) {
            $this->assertSame('FeatureNotAvailable', $e->errorCode);
        }
        $this->assertAdvisoryLockNotAcquired(DB::connection('root')->getQueryLog());
    }

    public function test_kill_switch_gate_emits_retry_after(): void
    {
        DB::connection('root')->table('FeatureFlags')
            ->where('FlagId', BrFlagId::KillSwitch->value)
            ->update(['Value' => BrFlagValue::On->value]);
        $service = app(BrFeatureFlagService::class);
        $service->forgetCache();
        try {
            $service->assertKillSwitchOff();
            $this->fail('assertKillSwitchOff must throw when kill-switch is on.');
        } catch (LaraException $e) {
            $this->assertSame('FeatureNotAvailable', $e->errorCode);
            $this->assertSame('60', $e->headers['Retry-After'] ?? null);
        }
    }

    /** @param array<int, array{query:string}> $log */
    private function assertAdvisoryLockNotAcquired(array $log): void
    {
        foreach ($log as $entry) {
            $sql = strtolower((string) ($entry['query'] ?? ''));
            $this->assertStringNotContainsString('pg_advisory_xact_lock', $sql);
            $this->assertStringNotContainsString('pg_advisory_lock', $sql);
        }
    }
}
