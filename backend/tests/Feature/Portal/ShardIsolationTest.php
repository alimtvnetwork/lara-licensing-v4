<?php

declare(strict_types=1);

namespace Tests\Feature\Portal;

use App\Models\Reseller;
use App\Models\User;
use App\Models\License;
use Illuminate\Support\Facades\DB;
use Tests\Support\AssertsLaraException;
use Tests\TestCase;

/**
 * Plan 06 step 58. Feature test ShardIsolationTest asserting reseller A
 * cannot read reseller B licenses even with forged IDs.
 *
 * Locked behaviors:
 *  - Reseller A authenticated session binds to Shard A.
 *  - GET /Api/Reseller/Licenses returns only licenses in Shard A.
 *  - GET /Api/Reseller/Licenses/{Key} returns 404 LicenseNotFound if Key exists in Shard B but not A.
 *  - Cross-reseller probes via forged TenantId or Slug are blocked by ShardBindingMiddleware.
 */
final class ShardIsolationTest extends TestCase
{
    use AssertsLaraException;

    private const ROOT = 'root';
    private const SHARD = 'shard';

    private Reseller $resellerA;
    private Reseller $resellerB;
    private User $userA;
    private User $userB;
    private string $dbPathA;
    private string $dbPathB;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('database.connections.root', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]);

        $this->dbPathA = sys_get_temp_dir() . '/lara_shard_a_' . uniqid() . '.sqlite';
        $this->dbPathB = sys_get_temp_dir() . '/lara_shard_b_' . uniqid() . '.sqlite';
        touch($this->dbPathA);
        touch($this->dbPathB);

        $this->migrateRoot();
        $this->seedTenants();
    }

    protected function tearDown(): void
    {
        if (file_exists($this->dbPathA)) unlink($this->dbPathA);
        if (file_exists($this->dbPathB)) unlink($this->dbPathB);
        parent::tearDown();
    }

    /**
     * @test
     */
    public function it_isolates_license_list_to_bound_shard(): void
    {
        // 1. Seed license in Shard A
        $this->seedLicense($this->resellerA, $this->dbPathA, 'LARA-AAAA-AAAA');
        // 2. Seed license in Shard B
        $this->seedLicense($this->resellerB, $this->dbPathB, 'LARA-BBBB-BBBB');

        // 3. Act as User A
        $response = $this->actingAs($this->userA, 'sanctum')
            ->getJson('/Api/Reseller/Licenses');

        $response->assertStatus(200);
        $results = $response->json('Results');
        $this->assertCount(1, $results);
        $this->assertEquals('LARA-AAAA-AAAA', $results[0]['LicenseKey']);
        
        // 4. Act as User B
        $response = $this->actingAs($this->userB, 'sanctum')
            ->getJson('/Api/Reseller/Licenses');

        $response->assertStatus(200);
        $results = $response->json('Results');
        $this->assertCount(1, $results);
        $this->assertEquals('LARA-BBBB-BBBB', $results[0]['LicenseKey']);
    }

    /**
     * @test
     */
    public function it_returns_404_when_reseller_probes_license_in_another_shard(): void
    {
        $this->seedLicense($this->resellerB, $this->dbPathB, 'LARA-FORGED-KEY');

        $this->assertLaraException('LicenseNotFound', function() {
            $this->actingAs($this->userA, 'sanctum')
                ->getJson('/Api/Reseller/Licenses/LARA-FORGED-KEY')
                ->assertStatus(404);
        });
    }

    private function migrateRoot(): void
    {
        $files = [
            '2026_07_18_000001_create_root_identity_tables.php',
            '2026_07_18_000002_create_root_reseller_tables.php',
            '2026_07_18_000006_create_root_auth_sessions_table.php',
            '2026_07_18_000008_create_root_personal_access_tokens_table.php',
        ];
        foreach ($files as $file) {
            $m = require base_path('database/migrations/root/' . $file);
            $m->up();
        }
    }

    private function seedTenants(): void
    {
        $this->resellerA = Reseller::create([
            'ResellerName' => 'Reseller A',
            'ResellerSlug' => 'reseller-a',
            'ContactEmail' => 'a@test.com',
            'IsActive' => true,
        ]);
        $this->userA = User::create([
            'Email' => 'user.a@test.com',
            'PasswordHash' => 'hash',
            'TenantId' => $this->resellerA->ResellerId,
            'IsActive' => true,
        ]);
        DB::connection(self::ROOT)->table('ResellerShardRoutes')->insert([
            'ResellerId' => $this->resellerA->ResellerId,
            'AppDbPath' => $this->dbPathA,
            'ShardStatus' => 'Active',
        ]);

        $this->resellerB = Reseller::create([
            'ResellerName' => 'Reseller B',
            'ResellerSlug' => 'reseller-b',
            'ContactEmail' => 'b@test.com',
            'IsActive' => true,
        ]);
        $this->userB = User::create([
            'Email' => 'user.b@test.com',
            'PasswordHash' => 'hash',
            'TenantId' => $this->resellerB->ResellerId,
            'IsActive' => true,
        ]);
        DB::connection(self::ROOT)->table('ResellerShardRoutes')->insert([
            'ResellerId' => $this->resellerB->ResellerId,
            'AppDbPath' => $this->dbPathB,
            'ShardStatus' => 'Active',
        ]);
        
        // Setup shard template for SQLite path substitution
        config()->set('database.connections.shard_template', [
            'driver' => 'sqlite',
            'database' => '{reseller}', // ShardResolver replaces this
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]);
    }

    private function seedLicense(Reseller $reseller, string $dbPath, string $key): void
    {
        config()->set('database.connections.temp_shard', [
            'driver' => 'sqlite',
            'database' => $dbPath,
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]);
        
        $m = require base_path('database/migrations/shard/2026_07_18_000001_create_shard_licenses_table.php');
        $m->up();

        DB::connection('temp_shard')->table('Licenses')->insert([
            'LicenseKey' => $key,
            'PrefixValue' => 'LARA',
            'ResellerId' => $reseller->ResellerId,
            'IssuedByUserId' => 1,
            'IssuerActorType' => 'Reseller',
            'LicenseCategoryId' => 1,
            'TierName' => 'Standard',
            'EnvironmentName' => 'Production',
            'ProductVersion' => 'V1',
            'Status' => 'Active',
            'IssuedAt' => now(),
            'Version' => 1,
        ]);
    }
}
