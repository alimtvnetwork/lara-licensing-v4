<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Step 5 of Plan 20 (v3.3).
 * Provides stable identities for the Demo Login feature.
 */
final class DemoLoginSeeder extends Seeder
{
    private const CONN_ROOT = 'root';

    public function run(): void
    {
        $users = [
            [
                'email' => 'admin@demo.lara.test',
                'password' => 'demo123',
                'role' => 'Admin'
            ],
            [
                'email' => 'reseller@demo.lara.test',
                'password' => 'demo123',
                'role' => 'Reseller'
            ],
        ];

        foreach ($users as $u) {
            $userId = $this->upsertUser($u['email'], $u['password']);
            $this->attachRole($userId, $u['role']);
        }

        $this->command?->line('  DemoLoginSeeder: stable identities seeded.');
    }

    private function upsertUser(string $email, string $password): int
    {
        // Low cost (4) for fast seeding and demo login
        $hash = Hash::make($password, ['rounds' => 4]);
        
        DB::connection(self::CONN_ROOT)->statement(
            'INSERT INTO "Users" ("Email","PasswordHash","IsActive") VALUES (?,?,TRUE) ON CONFLICT ("Email") DO NOTHING',
            [$email, $hash]
        );
        
        DB::connection(self::CONN_ROOT)->statement(
            'UPDATE "Users" SET "PasswordHash" = ? WHERE "Email" = ?',
            [$hash, $email]
        );

        return (int) DB::connection(self::CONN_ROOT)->selectOne('SELECT "UserId" FROM "Users" WHERE "Email" = ?', [$email])->UserId;
    }

    private function attachRole(int $userId, string $roleName): void
    {
        $roleId = DB::connection(self::CONN_ROOT)->selectOne('SELECT "RoleId" FROM "Roles" WHERE "RoleName" = ?', [$roleName])?->RoleId;
        
        if (!$roleId) {
            return;
        }

        DB::connection(self::CONN_ROOT)->statement(
            'INSERT INTO "UserRoles" ("UserId","RoleId") VALUES (?,?) ON CONFLICT ("UserId","RoleId") DO NOTHING',
            [$userId, $roleId]
        );
    }
}
