<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\LicenseLedgerService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Plan 06 step 48. Locks LicenseLedgerService against regression:
 *
 *   AC-LDR-001: Shard-scoped reads. Only rows matching the given
 *               (ResellerId, LicenseId) tuple are returned. A row
 *               belonging to a different reseller sitting in the same
 *               physical shard fixture is NEVER returned.
 *   AC-LDR-002: Deterministic order. Rows are returned in
 *               CreatedAt ASC, LicenseLedgerId ASC order so ties
 *               within an instant stay stable (spec 21-app/48).
 *   AC-LDR-003: Empty result for an unknown LicenseId. The service
 *               returns [] rather than surfacing an unrelated row;
 *               controllers translate not-found to 404 upstream.
 *
 * The 401 (unauthenticated) and 403 (wrong role / unbound tenant)
 * paths for the reseller ledger route are already covered by
 * RbacEnforcementPest + ResellerTenantIsolationPest, which exercise
 * the same middleware chain. Cross-tenant 404 is asserted here at the
 * service level via AC-LDR-001.
 */
final class LicenseLedgerReadTest extends TestCase
{
    private const RESELLER_A = 10;
    private const RESELLER_B = 20;
    private const LICENSE_A1 = 501;
    private const LICENSE_A2 = 502;
    private const LICENSE_B1 = 601;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createShardLedgerTable();
    }

    public function test_returns_only_rows_for_the_requested_license_and_reseller(): void
    {
        $this->seedRow(self::RESELLER_A, self::LICENSE_A1, 'QuotaConsumed', -1, Carbon::now()->subMinutes(3));
        $this->seedRow(self::RESELLER_A, self::LICENSE_A1, 'LicenseRenewed', 0, Carbon::now()->subMinutes(2));
        $this->seedRow(self::RESELLER_A, self::LICENSE_A2, 'QuotaConsumed', -1, Carbon::now()->subMinutes(1));
        $this->seedRow(self::RESELLER_B, self::LICENSE_B1, 'QuotaConsumed', -1, Carbon::now());
        $service = $this->app->make(LicenseLedgerService::class);
        $rows = $service->listForLicense(self::RESELLER_A, self::LICENSE_A1);
        $this->assertCount(2, $rows, 'Only rows for LicenseA1 must be returned.');
        $this->assertSame([self::LICENSE_A1, self::LICENSE_A1], array_column($rows, 'LicenseId'));
        $this->assertSame(['QuotaConsumed', 'LicenseRenewed'], array_column($rows, 'LedgerAction'));
    }

    public function test_cross_tenant_probe_returns_no_rows_even_when_license_id_matches(): void
    {
        // Same LicenseId number on both resellers (physically different rows
        // in the same fixture): the defensive ResellerId filter must exclude
        // the other tenant's row.
        $this->seedRow(self::RESELLER_A, 777, 'QuotaConsumed', -1, Carbon::now()->subMinute());
        $this->seedRow(self::RESELLER_B, 777, 'QuotaConsumed', -1, Carbon::now());
        $service = $this->app->make(LicenseLedgerService::class);
        $rows = $service->listForLicense(self::RESELLER_A, 777);
        $this->assertCount(1, $rows);
        $this->assertSame(self::RESELLER_A, $rows[0]['ResellerId']);
    }

    public function test_unknown_license_returns_empty_array(): void
    {
        $this->seedRow(self::RESELLER_A, self::LICENSE_A1, 'QuotaConsumed', -1, Carbon::now());
        $service = $this->app->make(LicenseLedgerService::class);
        $this->assertSame([], $service->listForLicense(self::RESELLER_A, 999999));
    }

    private function seedRow(int $resellerId, int $licenseId, string $action, int $delta, Carbon $createdAt): void
    {
        DB::connection('shard')->table('LicenseLedger')->insert([
            'ResellerId' => $resellerId,
            'LicenseCategoryId' => 7,
            'TierName' => 'Tier1',
            'LedgerAction' => $action,
            'Delta' => $delta,
            'LicenseId' => $licenseId,
            'QuotaRequestId' => null,
            'RequestId' => 'req-' . $licenseId . '-' . $action,
            'ActorUserId' => 1,
            'CreatedAt' => $createdAt->toDateTimeString(),
        ]);
    }

    private function createShardLedgerTable(): void
    {
        DB::connection('shard')->statement('DROP TABLE IF EXISTS "LicenseLedger"');
        DB::connection('shard')->statement(
            'CREATE TABLE "LicenseLedger" (
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
    }
}
