<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Step 11 of Plan 20 (v3.3).
 * Populates system-wide notifications for the Admin Dashboard.
 */
final class NotificationsSeeder extends Seeder
{
    private const CONN_ROOT = 'root';

    public function run(): void
    {
        $notifications = [
            [
                'Title' => 'System Maintenance',
                'Message' => 'Scheduled maintenance on 2026-06-01.',
                'Severity' => 'Info',
                'IsActive' => true
            ],
            [
                'Title' => 'Security Patch',
                'Message' => 'Critical patch applied to all shards.',
                'Severity' => 'Warning',
                'IsActive' => true
            ],
        ];

        foreach ($notifications as $n) {
            DB::connection(self::CONN_ROOT)->statement(
                'INSERT INTO "Notifications" ("Title", "Message", "Severity", "IsActive", "CreatedAt") 
                 VALUES (?, ?, ?, ?, NOW())',
                [$n['Title'], $n['Message'], $n['Severity'], $n['IsActive']]
            );
        }

        $this->command?->line('  NotificationsSeeder: domain populated.');
    }
}
