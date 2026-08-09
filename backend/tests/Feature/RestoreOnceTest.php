<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\LaraException;
use App\Services\QuotaService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Plan 06 step 52. Locks spec/21-app/48-quota-restore-on-revoke.md §2
 * "Restore contract" invariants against regression:
 *
 *   AC-QRR-001: exactly one QuotaRestored ledger row per LicenseId, ever
 *               (UX_LicenseLedger_RestoreOnce). Second call returns the
 *               same LedgerId without double-crediting.
 *   AC-QRR-002: ClosedPeriod skip. When the funding Quotas row's
 *               PeriodEnd is in the past, no ledger row is written and
 *               the response reports RestoreSkippedReason=ClosedPeriod.
 *   AC-QRR-003: Underflow guard. If LicensesConsumed on the funding row
 *               is already zero, restoreForLicense throws
 *               QuotaLedgerConflict instead of silently clamping.
 *
 * Uses sqlite-portable shard tables mirroring the Postgres column
 * contract; matches the pattern established by IdempotencyTest.
 */
final class RestoreOnceTest extends TestCase
{
    private const RESELLER_ID = 42;
    private const CATEGORY_ID = 4;
    private const TIER_ID = 2;
    private const TIER_NAME = 'Standard';
    private const LICENSE_ID = 1001;
    private const ORIGINAL_LEDGER_ID = 555;
    private const ACTOR_ID = 7;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createShardTables();
        $this->app->singleton(QuotaService::class);
        config()->set('lara.license_tiers', [
            'Free' => 1, 'Standard' => 2, 'Pro' => 3, 'Enterprise' => 4,
        ]);
    }

    public function test_restore_is_idempotent_across_two_calls(): void
    {
        $this->seedFundingQuota(licensesConsumed: 1, periodEnd: null);
        $this->seedOriginalConsumedLedger(Carbon::now()->subDay());
        $service = $this->app->make(QuotaService::class);
        $first = $service->restoreForLicense(
            self::RESELLER_ID, self::CATEGORY_ID, self::TIER_NAME, self::LICENSE_ID,
            self::ORIGINAL_LEDGER_ID, 'req-1', self::ACTOR_ID,
        );
        $second = $service->restoreForLicense(
            self::RESELLER_ID, self::CATEGORY_ID, self::TIER_NAME, self::LICENSE_ID,
            self::ORIGINAL_LEDGER_ID, 'req-2', self::ACTOR_ID,
        );
        $this->assertTrue($first['QuotaRestored']);
        $this->assertTrue($second['QuotaRestored']);
        $this->assertSame($first['LedgerId'], $second['LedgerId'], 'Second call must replay first LedgerId.');
        $this->assertSame(1, DB::connection('shard')->table('LicenseLedger')
            ->where('LedgerAction', 'QuotaRestored')->count(), 'Exactly one QuotaRestored row.');
        $consumed = (int) DB::connection('shard')->table('Quotas')->value('LicensesConsumed');
        $this->assertSame(0, $consumed, 'LicensesConsumed decremented exactly once.');
    }

    public function test_closed_period_returns_skip_and_writes_no_ledger(): void
    {
        $issuedAt = Carbon::now()->subMonths(2);
        $this->seedFundingQuota(
            licensesConsumed: 1,
            periodEnd: Carbon::now()->subMonth()->toDateTimeString(),
            periodStart: $issuedAt->copy()->subDay()->toDateTimeString(),
        );
        $this->seedOriginalConsumedLedger($issuedAt);
        $service = $this->app->make(QuotaService::class);
        $result = $service->restoreForLicense(
            self::RESELLER_ID, self::CATEGORY_ID, self::TIER_NAME, self::LICENSE_ID,
            self::ORIGINAL_LEDGER_ID, 'req-closed', self::ACTOR_ID,
        );
        $this->assertFalse($result['QuotaRestored']);
        $this->assertSame('ClosedPeriod', $result['RestoreSkippedReason']);
        $this->assertNull($result['LedgerId']);
        $this->assertSame(0, DB::connection('shard')->table('LicenseLedger')
            ->where('LedgerAction', 'QuotaRestored')->count());
        $this->assertSame(1, (int) DB::connection('shard')->table('Quotas')->value('LicensesConsumed'),
            'ClosedPeriod skip must not decrement the funding row.');
    }

    public function test_underflow_on_zero_consumed_throws_conflict(): void
    {
        $this->seedFundingQuota(licensesConsumed: 0, periodEnd: null);
        $this->seedOriginalConsumedLedger(Carbon::now()->subDay());
        $service = $this->app->make(QuotaService::class);
        try {
            $service->restoreForLicense(
                self::RESELLER_ID, self::CATEGORY_ID, self::TIER_NAME, self::LICENSE_ID,
                self::ORIGINAL_LEDGER_ID, 'req-underflow', self::ACTOR_ID,
            );
            $this->fail('Expected QuotaLedgerConflict.');
        } catch (LaraException $e) {
            $this->assertSame('QuotaLedgerConflict', $e->errorCode);
        }
    }

    private function seedFundingQuota(int $licensesConsumed, ?string $periodEnd, ?string $periodStart = null): void
    {
        DB::connection('shard')->table('Quotas')->insert([
            'ResellerId' => self::RESELLER_ID,
            'LicenseCategoryId' => self::CATEGORY_ID,
            'LicenseTierId' => self::TIER_ID,
            'PeriodStart' => $periodStart ?? Carbon::now()->subYear()->toDateTimeString(),
            'PeriodEnd' => $periodEnd,
            'LicensesGranted' => 10,
            'LicensesConsumed' => $licensesConsumed,
            'CreatedAt' => Carbon::now()->toDateTimeString(),
            'UpdatedAt' => Carbon::now()->toDateTimeString(),
        ]);
    }

    private function seedOriginalConsumedLedger(Carbon $createdAt): void
    {
        DB::connection('shard')->table('LicenseLedger')->insert([
            'LicenseLedgerId' => self::ORIGINAL_LEDGER_ID,
            'ResellerId' => self::RESELLER_ID,
            'LicenseCategoryId' => self::CATEGORY_ID,
            'TierName' => self::TIER_NAME,
            'LedgerAction' => 'QuotaConsumed',
            'Delta' => -1,
            'LicenseId' => self::LICENSE_ID,
            'QuotaRequestId' => null,
            'RequestId' => 'orig-req',
            'ActorUserId' => self::ACTOR_ID,
            'CreatedAt' => $createdAt->toDateTimeString(),
        ]);
    }

    private function createShardTables(): void
    {
        DB::connection('shard')->statement(
            'CREATE TABLE IF NOT EXISTS "Quotas" (
                "ResellerId" INTEGER NOT NULL,
                "LicenseCategoryId" INTEGER NOT NULL,
                "LicenseTierId" INTEGER NOT NULL,
                "PeriodStart" TEXT NOT NULL,
                "PeriodEnd" TEXT NULL,
                "LicensesGranted" INTEGER NOT NULL,
                "LicensesConsumed" INTEGER NOT NULL,
                "CreatedAt" TEXT NOT NULL,
                "UpdatedAt" TEXT NOT NULL,
                PRIMARY KEY ("ResellerId","LicenseCategoryId","LicenseTierId","PeriodStart")
            )'
        );
        DB::connection('shard')->statement(
            'CREATE TABLE IF NOT EXISTS "LicenseLedger" (
                "LicenseLedgerId" INTEGER PRIMARY KEY AUTOINCREMENT,
                "ResellerId" INTEGER NOT NULL,
                "LicenseCategoryId" INTEGER NOT NULL,
                "TierName" TEXT NOT NULL,
                "LedgerAction" TEXT NOT NULL,
                "Delta" INTEGER NOT NULL,
                "LicenseId" INTEGER NOT NULL,
                "QuotaRequestId" INTEGER NULL,
                "RequestId" TEXT NOT NULL,
                "ActorUserId" INTEGER NOT NULL,
                "CreatedAt" TEXT NOT NULL
            )'
        );
        DB::connection('shard')->statement(
            'CREATE UNIQUE INDEX IF NOT EXISTS "UX_LicenseLedger_RestoreOnce"'
            . ' ON "LicenseLedger" ("LicenseId") WHERE "LedgerAction" = \'QuotaRestored\''
        );
    }
}
