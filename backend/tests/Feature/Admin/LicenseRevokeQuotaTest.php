<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Db\ShardResolver;
use App\Models\License;
use App\Models\Prefix;
use App\Models\Reseller;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\AssertsLaraException;
use Tests\TestCase;

/**
 * Plan 06 step 52. License Revoke Quota restoration E2E.
 * 
 * Verifies that revoking a license correctly restores quota to the reseller's
 * balance on the shard, provided the license was issued by a reseller (not admin)
 * and has a valid ledger link.
 */
final class LicenseRevokeQuotaTest extends TestCase
{
    use AssertsLaraException;

    private const SLUG = 'acme-revoke';
    private const PREFIX = 'REV';
    private const TIER_ID = 1;
    private const CAT_ID = 7; // Key

    private int $resellerId;
    private int $adminId;
    private string $shardDbPath;

    protected function setUp(): void
    {
        parent::setUp();
        
        config()->set('database.connections.root', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'foreign_key_constraints' => false,
        ]);

        $this->shardDbPath = sys_get_temp_dir() . '/lara_test_revoke_shard_' . uniqid('', true) . '.sqlite';
        touch($this->shardDbPath);
        
        $this->configureShardTemplate();
        $this->createRootFixtures();
        $this->createShardTables();

        $this->resellerId = $this->seedReseller(self::SLUG);
        $this->seedPrefix(self::PREFIX, $this->resellerId);
        $this->adminId = $this->seedAdmin();
    }

    protected function tearDown(): void
    {
        if (file_exists($this->shardDbPath)) {
            @unlink($this->shardDbPath);
        }
        parent::tearDown();
    }

    public function test_revoke_restores_quota_for_reseller_issued_license(): void
    {
        $this->bindShard(self::SLUG);
        
        // 1. Seed active quota
        DB::connection('shard')->table('Quotas')->insert([
            'ResellerId' => $this->resellerId,
            'LicenseCategoryId' => self::CAT_ID,
            'LicenseTierId' => self::TIER_ID,
            'LicensesGranted' => 10,
            'LicensesConsumed' => 1,
            'PeriodStart' => Carbon::now()->subDay(),
        ]);

        // 2. Seed consumed ledger row
        $ledgerId = DB::connection('shard')->table('LicenseLedger')->insertGetId([
            'ResellerId' => $this->resellerId,
            'LicenseCategoryId' => self::CAT_ID,
            'TierName' => 'Pro',
            'Action' => 'QuotaConsumed',
            'Delta' => -1,
            'RequestId' => 'req-consume',
            'ActorUserId' => 99,
            'CreatedAt' => Carbon::now(),
        ]);

        // 3. Seed license linked to ledger
        $licenseKey = self::PREFIX . '-RES-1234';
        $licenseId = DB::connection('shard')->table('Licenses')->insertGetId([
            'LicenseKey' => $licenseKey,
            'Status' => 'Active',
            'EnvironmentName' => 'Production',
            'PrefixValue' => self::PREFIX,
            'LicenseCategoryId' => self::CAT_ID,
            'LicenseTierId' => self::TIER_ID,
            'TierName' => 'Pro',
            'ProductVersion' => 'V1',
            'ResellerQuotaLedgerId' => $ledgerId,
            'Version' => 1,
        ]);

        $admin = User::find($this->adminId);
        $res = $this->actingAs($admin)->deleteJson("/api/Admin/Licenses/{$licenseKey}?ResellerSlug=" . self::SLUG, [
            'RevokeReason' => 'Testing restoration'
        ], ['X-Request-Id' => 'req-revoke']);

        $res->assertStatus(200);
        $res->assertJsonPath('Results.0.QuotaRestored', true);

        // Verify Quotas.LicensesConsumed decremented (1 -> 0)
        $this->assertDatabaseHas('Quotas', [
            'ResellerId' => $this->resellerId,
            'LicensesConsumed' => 0,
        ], 'shard');

        // Verify QuotaRestored row in ledger
        $this->assertDatabaseHas('LicenseLedger', [
            'LicenseId' => $licenseId,
            'Action' => 'QuotaRestored',
            'Delta' => 1,
        ], 'shard');
    }

    public function test_revoke_skips_restoration_for_admin_issued_license(): void
    {
        $this->bindShard(self::SLUG);
        
        // Seed license with NULL ResellerQuotaLedgerId (Admin issued)
        $licenseKey = self::PREFIX . '-ADM-5678';
        DB::connection('shard')->table('Licenses')->insert([
            'LicenseKey' => $licenseKey,
            'Status' => 'Active',
            'EnvironmentName' => 'Production',
            'PrefixValue' => self::PREFIX,
            'LicenseCategoryId' => self::CAT_ID,
            'LicenseTierId' => self::TIER_ID,
            'TierName' => 'Pro',
            'ProductVersion' => 'V1',
            'ResellerQuotaLedgerId' => null,
            'Version' => 1,
        ]);

        $admin = User::find($this->adminId);
        $res = $this->actingAs($admin)->deleteJson("/api/Admin/Licenses/{$licenseKey}?ResellerSlug=" . self::SLUG, [
            'RevokeReason' => 'Testing skip'
        ], ['X-Request-Id' => 'req-revoke-skip']);

        $res->assertStatus(200);
        $res->assertJsonPath('Results.0.QuotaRestored', false);
        $res->assertJsonPath('Results.0.RestoreSkippedReason', 'AdminIssued');
    }

    private function configureShardTemplate(): void
    {
        config()->set('database.connections.shard_template', [
            'driver' => 'sqlite',
            'database' => $this->shardDbPath,
            'foreign_key_constraints' => false,
        ]);
        config()->set('lara.license_categories', ['Key' => self::CAT_ID]);
    }

    private function bindShard(string $slug): void
    {
        $this->app->make(ShardResolver::class)->bind($slug);
    }

    private function createRootFixtures(): void
    {
        $root = DB::connection('root');
        $root->statement('CREATE TABLE Resellers (ResellerId INTEGER PRIMARY KEY, ResellerName TEXT, ResellerSlug TEXT, IsActive INTEGER)');
        $root->statement('CREATE TABLE Prefixes (PrefixId INTEGER PRIMARY KEY, ResellerId INTEGER, PrefixValue TEXT, IsActive INTEGER)');
        $root->statement('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, email TEXT)');
    }

    private function createShardTables(): void
    {
        $this->bindShard(self::SLUG);
        $shard = DB::connection('shard');
        $shard->statement('CREATE TABLE Licenses (LicenseId INTEGER PRIMARY KEY, LicenseKey TEXT, Status TEXT, EnvironmentName TEXT, PrefixValue TEXT, LicenseCategoryId INTEGER, LicenseTierId INTEGER, TierName TEXT, ProductVersion TEXT, ResellerQuotaLedgerId INTEGER, Version INTEGER)');
        $shard->statement('CREATE TABLE Quotas (QuotaId INTEGER PRIMARY KEY, ResellerId INTEGER, LicenseCategoryId INTEGER, LicenseTierId INTEGER, LicensesGranted INTEGER, LicensesConsumed INTEGER, PeriodStart TEXT, PeriodEnd TEXT)');
        $shard->statement('CREATE TABLE LicenseLedger (LedgerId INTEGER PRIMARY KEY, ResellerId INTEGER, LicenseId INTEGER, LicenseCategoryId INTEGER, TierName TEXT, Action TEXT, Delta INTEGER, RequestId TEXT, ActorUserId INTEGER, CreatedAt TEXT)');
    }

    private function seedReseller(string $slug): int
    {
        return DB::connection('root')->table('Resellers')->insertGetId([
            'ResellerName' => 'Acme',
            'ResellerSlug' => $slug,
            'IsActive' => 1,
        ]);
    }

    private function seedPrefix(string $val, int $rid): void
    {
        DB::connection('root')->table('Prefixes')->insert([
            'PrefixValue' => $val,
            'ResellerId' => $rid,
            'IsActive' => 1,
        ]);
    }

    private function seedAdmin(): int
    {
        return DB::connection('root')->table('users')->insertGetId([
            'name' => 'Admin',
            'email' => 'admin@example.com',
        ]);
    }
}
