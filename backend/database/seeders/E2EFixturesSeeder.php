<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Db\ShardResolver;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Step 20 of Plan 20 (v3.3).
 * Populates E2EFixturesSeeder with deterministic inventory and profile-based branching. Final v3.3 Baseline.
 */
final class E2EFixturesSeeder extends Seeder
{
    private const CONN_ROOT = 'root';
    private const CONN_SHARD = 'shard';

    public function run(): void
    {
        $config = $this->resolveConfig();
        if ($config === null) {
            $this->command?->line('  E2EFixturesSeeder: disabled.');

            return;
        }

        $profile = $config['profile'] ?? 'default';
        $this->command?->line("  E2EFixturesSeeder: seeding profile '{$profile}'...");

        switch ($profile) {
            case 'empty':
                $this->seedEmptyProfile($config);
                break;
            case 'error':
                $this->seedErrorProfile($config);
                break;
            case 'default':
            default:
                $this->seedDefaultProfile($config);
                break;
        }

        $this->command?->line("  E2EFixturesSeeder: profile '{$profile}' populated (Step 3 completed).");
    }

    private function seedDefaultProfile(array $config): void
    {
        // 1. Root Identity
        $adminEmail = $config['admin']['email'] ?? 'admin@lara.test';
        $adminPass = $config['admin']['password'] ?? 'password123';
        
        $adminId = $this->upsertRootUser($adminEmail, $adminPass);
        $this->attachRootRole($adminId, 'Admin');
        $this->attachRootRole($adminId, 'SuperAdmin');

        // 2. Composite Domain Seeding
        $this->call(DemoLoginSeeder::class);
        $this->call(ResellersSeeder::class);
        $this->call(QuotasLicensesSeeder::class);
        $this->call(MetricsAuditSeeder::class);
        $this->call(ServiceUsageSeeder::class);
        $this->call(NotificationsSeeder::class);
        $this->call(ResellerStaffSeeder::class);
        $this->call(SettingsSeeder::class);
        $this->call(ProductCatalogSeeder::class);
        $this->call(PlatformStatsSeeder::class);
        $this->call(WebhooksSeeder::class);
        $this->call(HealthCheckSeeder::class);
        $this->call(ApiKeysSeeder::class);
        $this->call(UserProfilesSeeder::class);
    }

    private function seedEmptyProfile(array $config): void
    {
        // Only seed the admin user so login works, but no resellers/data
        $adminEmail = $config['admin']['email'] ?? 'admin@lara.test';
        $adminPass = $config['admin']['password'] ?? 'password123';
        $adminId = $this->upsertRootUser($adminEmail, $adminPass);
        $this->attachRootRole($adminId, 'Admin');
    }

    private function seedErrorProfile(array $config): void
    {
        // Seed an admin user and some "broken" data state if needed
        $this->seedDefaultProfile($config);
        
        // Example: Seed a reseller with an invalid shard route to test error handling
        DB::connection(self::CONN_ROOT)->statement(
            'INSERT INTO "Resellers" ("ResellerName","ResellerSlug","ContactEmail") VALUES (?,?,?) ON CONFLICT ("ResellerSlug") DO NOTHING',
            ['Broken Corp', 'broken-corp', 'broken@test.com']
        );
        $resellerId = DB::connection(self::CONN_ROOT)->selectOne('SELECT "ResellerId" FROM "Resellers" WHERE "ResellerSlug" = ?', ['broken-corp'])->ResellerId;
        DB::connection(self::CONN_ROOT)->statement(
            'INSERT INTO "ResellerShardRoutes" ("ResellerId","AppDbPath","ShardStatus") VALUES (?,?,\'Offline\') ON CONFLICT ("ResellerId") DO NOTHING',
            [$resellerId, 'non_existent_shard']
        );
    }

    private function resolveConfig(): ?array
    {
        $block = config('lara.e2e_fixtures');
        // Even if disabled in config, we might want to force it if LARA_E2E_SEED=true is passed
        if (!is_array($block)) return ['enabled' => true];
        if (($block['enabled'] ?? false) !== true && !env('LARA_E2E_SEED')) return null;

        return $block;
    }

    private function upsertRootUser(string $email, string $password): int
    {
        $hash = Hash::make($password, ['rounds' => 4]); // cost 4 per Plan 20 step 5
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

    private function attachRootRole(int $userId, string $roleName): void
    {
        $roleId = DB::connection(self::CONN_ROOT)->selectOne('SELECT "RoleId" FROM "Roles" WHERE "RoleName" = ?', [$roleName])?->RoleId;
        if (!$roleId) {
             // Try to seed Roles if missing (safety)
             $this->call(RolesSeeder::class);
             $roleId = DB::connection(self::CONN_ROOT)->selectOne('SELECT "RoleId" FROM "Roles" WHERE "RoleName" = ?', [$roleName])?->RoleId;
        }
        if (!$roleId) throw new RuntimeException("Role {$roleName} missing.");
        
        DB::connection(self::CONN_ROOT)->statement(
            'INSERT INTO "UserRoles" ("UserId","RoleId") VALUES (?,?) ON CONFLICT ("UserId","RoleId") DO NOTHING',
            [$userId, $roleId]
        );
    }

    // Domain logic moved to ResellersSeeder, QuotasLicensesSeeder, MetricsAuditSeeder (Steps 6-8).
}
