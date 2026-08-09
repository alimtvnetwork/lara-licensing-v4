<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Step 12 of Plan 20 (v3.3).
 * Domain-specific seeder for Reseller Staff and permissions.
 */
final class ResellerStaffSeeder extends Seeder
{
    private const CONN_ROOT = 'root';

    public function run(): void
    {
        $resellers = DB::connection(self::CONN_ROOT)->select('SELECT "ResellerId" FROM "Resellers" LIMIT 2');
        
        foreach ($resellers as $reseller) {
            $this->seedStaff((int)$reseller->ResellerId);
        }

        $this->command?->line('  ResellerStaffSeeder: domain populated.');
    }

    private function seedStaff(int $resellerId): void
    {
        $staff = [
            ['Name' => 'Alice Staff', 'Email' => "alice@staff-{$resellerId}.test"],
            ['Name' => 'Bob Manager', 'Email' => "bob@staff-{$resellerId}.test"],
        ];

        foreach ($staff as $s) {
            DB::connection(self::CONN_ROOT)->statement(
                'INSERT INTO "ResellerStaff" ("ResellerId", "DisplayName", "ContactEmail", "IsActive") 
                 VALUES (?, ?, ?, TRUE) ON CONFLICT DO NOTHING',
                [$resellerId, $s['Name'], $s['Email']]
            );
        }
    }
}
