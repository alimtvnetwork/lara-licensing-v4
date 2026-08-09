<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Db\ShardResolver;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Step 8 of Plan 20 (v3.3).
 * Domain-specific seeder for Quotas and Licenses in Shard DBs.
 */
final class QuotasLicensesSeeder extends Seeder
{
    private const CONN_ROOT = 'root';
    private const CONN_SHARD = 'shard';

    public function run(): void
    {
        $resellers = DB::connection(self::CONN_ROOT)->select('SELECT "ResellerId", "ResellerSlug" FROM "Resellers" LIMIT 2');
        
        foreach ($resellers as $reseller) {
            try {
                app(ShardResolver::class)->bind($reseller->ResellerSlug);
            } catch (\Throwable $e) {
                continue;
            }

            $this->seedQuotas((int)$reseller->ResellerId);
            $this->seedLicenses((int)$reseller->ResellerId);
        }

        $this->command?->line('  QuotasLicensesSeeder: domain populated.');
    }

    private function seedQuotas(int $resellerId): void
    {
        // Category 3 = Monthly, Tier 1 = Tier1
        DB::connection(self::CONN_SHARD)->statement(
            'INSERT INTO "Quotas" ("ResellerId","LicenseCategoryId","LicenseTierId","LicensesGranted","LicensesConsumed","PeriodStart")
             VALUES (?,3,1,500,25,NOW()) ON CONFLICT DO NOTHING',
            [$resellerId]
        );
    }

    private function seedLicenses(int $resellerId): void
    {
        $prefix = DB::connection(self::CONN_ROOT)->selectOne('SELECT "PrefixValue" FROM "Prefixes" WHERE "ResellerId" = ?', [$resellerId])?->PrefixValue ?? 'LARA';

        for ($i = 1; $i <= 5; $i++) {
            $key = sprintf("%s-E2E-TEST-%04d", $prefix, $i);
            DB::connection(self::CONN_SHARD)->statement(
                'INSERT INTO "Licenses" ("LicenseKey","PrefixValue","ResellerId","IssuedByUserId","TierName","EnvironmentName","Status")
                 VALUES (?,?,1,1,\'Tier1\',\'Production\',\'Active\') ON CONFLICT DO NOTHING',
                [$key, $prefix, $resellerId]
            );
        }
    }
}
