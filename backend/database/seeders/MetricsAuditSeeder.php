<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Db\ShardResolver;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Step 7 of Plan 20 (v3.3).
 * Domain-specific seeder for Metrics and Audit Logs in Shard DBs.
 */
final class MetricsAuditSeeder extends Seeder
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

            $this->seedMetrics((int)$reseller->ResellerId);
            $this->seedAuditLogs((int)$reseller->ResellerId);
        }

        $this->command?->line('  MetricsAuditSeeder: domain populated.');
    }

    private function seedMetrics(int $resellerId): void
    {
        // Seed some historical metrics for charts
        for ($i = 30; $i >= 0; $i--) {
            $date = now()->subDays($i)->format('Y-m-d');
            DB::connection(self::CONN_SHARD)->statement(
                'INSERT INTO "DailyMetrics" ("ResellerId", "MetricDate", "RequestCount", "SuccessCount", "FailureCount") 
                 VALUES (?, ?, ?, ?, ?) ON CONFLICT DO NOTHING',
                [$resellerId, $date, rand(100, 500), rand(90, 480), rand(0, 20)]
            );
        }
    }

    private function seedAuditLogs(int $resellerId): void
    {
        $logs = [
            ['Action' => 'Login', 'Details' => 'User logged in'],
            ['Action' => 'LicenseCreated', 'Details' => 'New license issued'],
            ['Action' => 'QuotaUpdated', 'Details' => 'Monthly quota bumped'],
        ];

        foreach ($logs as $log) {
            DB::connection(self::CONN_SHARD)->statement(
                'INSERT INTO "AuditLogs" ("ResellerId", "Action", "Details", "CreatedAt") VALUES (?, ?, ?, NOW())',
                [$resellerId, $log['Action'], $log['Details']]
            );
        }
    }
}
