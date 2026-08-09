<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Step 19 of Plan 20 (v3.3).
 * Domain-specific seeder for User Profiles and Preferences.
 */
final class UserProfilesSeeder extends Seeder
{
    private const CONN_ROOT = 'root';

    public function run(): void
    {
        $users = DB::connection(self::CONN_ROOT)->select('SELECT "UserId" FROM "Users" LIMIT 5');
        
        foreach ($users as $user) {
            DB::connection(self::CONN_ROOT)->statement(
                'INSERT INTO "UserProfiles" ("UserId", "FullName", "PreferencesJson") 
                 VALUES (?, ?, ?) ON CONFLICT ("UserId") DO NOTHING',
                [$user->UserId, "User " . $user->UserId, json_encode(['theme' => 'dark', 'notifications' => true])]
            );
        }

        $this->command?->line('  UserProfilesSeeder: domain populated.');
    }
}
