<?php

declare(strict_types=1);

namespace Tests\Feature\Portal;

use App\Db\ShardResolver;
use App\Models\License;
use App\Models\Prefix;
use App\Models\Reseller;
use App\Models\Serial;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Tests\Support\AssertsLaraException;
use Tests\TestCase;

/**
 * Plan 06 step 54. Portal Serial issue E2E (signed).
 */
final class SerialIssueTest extends TestCase
{
    use AssertsLaraException;

    private const PREFIX = 'ACME';
    private const SLUG = 'acme';
    private const LICENSE_KEY = 'ACME-PROD-V1-ABCD-1234';
    private const DEVICE_HASH = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855'; // sha256 empty
    private const APP_BUILDER_ID = 'test-builder';
    private const APP_BUILDER_SECRET = 'test-secret-42';

    private int $resellerId;
    private string $shardDbPath;

    protected function setUp(): void
    {
        parent::setUp();
        
        config()->set('database.connections.root', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);

        $this->shardDbPath = sys_get_temp_dir() . '/lara_test_portal_shard_' . uniqid('', true) . '.sqlite';
        touch($this->shardDbPath);
        $this->configureShardTemplate();
        $this->createRootFixtures();
        $this->createShardTables();
        $this->resellerId = $this->seedReseller(self::SLUG, 'Acme Corp');
        $this->seedPrefix(self::PREFIX, $this->resellerId);
        $this->seedLicense(self::LICENSE_KEY, $this->resellerId);
    }

    protected function tearDown(): void
    {
        if (file_exists($this->shardDbPath)) {
            @unlink($this->shardDbPath);
        }
        parent::tearDown();
    }

    public function test_issue_without_signature_returns_401(): void
    {
        $payload = [
            'LicenseKey' => self::LICENSE_KEY,
            'DeviceIdHash' => self::DEVICE_HASH,
            'EnvironmentName' => 'Production',
        ];
        // Idempotency-Key is REQUIRED for this endpoint.
        $res = $this->postJson('/api/Portal/Serials', $payload, [
            'Idempotency-Key' => 'test-idempotency-123'
        ]);
        $this->assertLaraException($res, 'AuthUnauthorized', 401);
    }

    public function test_issue_happy_path_201_then_idempotent_200(): void
    {
        Log::spy();
        $payload = [
            'LicenseKey' => self::LICENSE_KEY,
            'DeviceIdHash' => self::DEVICE_HASH,
            'EnvironmentName' => 'Production',
        ];

        // 1. First issue (201)
        $res = $this->signedPost('/api/Portal/Serials', $payload, 'api/Portal/Serials');
        $res->assertStatus(201);
        $this->assertNotNull($res->json('Results.0.SerialValue'));
        $serialValue = $res->json('Results.0.SerialValue');

        $this->bindShard(self::SLUG);
        $this->assertDatabaseHas('Serials', [
            'SerialValue' => $serialValue,
            'DeviceIdHash' => self::DEVICE_HASH,
        ], 'shard');

        // 2. Second issue (200, idempotent)
        $res2 = $this->signedPost('/api/Portal/Serials', $payload, 'api/Portal/Serials');
        $res2->assertStatus(200);
        $this->assertSame($serialValue, $res2->json('Results.0.SerialValue'), 'Must return same serial for same device.');
    }

    public function test_issue_environment_mismatch_returns_409(): void
    {
        $payload = [
            'LicenseKey' => self::LICENSE_KEY,
            'DeviceIdHash' => self::DEVICE_HASH,
            'EnvironmentName' => 'Staging', // License is Production
        ];
        $res = $this->signedPost('/api/Portal/Serials', $payload, 'api/Portal/Serials');
        $this->assertLaraException($res, 'EnvironmentMismatch', 409);
    }

    public function test_issue_revoked_license_returns_409(): void
    {
        $this->bindShard(self::SLUG);
        DB::connection('shard')->table('Licenses')->where('LicenseKey', self::LICENSE_KEY)->update(['Status' => 'Revoked']);

        $payload = [
            'LicenseKey' => self::LICENSE_KEY,
            'DeviceIdHash' => self::DEVICE_HASH,
            'EnvironmentName' => 'Production',
        ];
        $res = $this->signedPost('/api/Portal/Serials', $payload, 'api/Portal/Serials');
        $this->assertLaraException($res, 'LicenseRevoked', 409);
    }

    private function signedPost(string $uri, array $payload, string $signingPath): \Illuminate\Testing\TestResponse
    {
        $body = json_encode($payload);
        $ts = (string) time();
        $nonce = bin2hex(random_bytes(16));
        $method = 'POST';
        $path = strtolower($signingPath);
        $bodyHash = hash('sha256', $body);
        
        $canonical = implode("\n", ['v1', $method, $path, $ts, $nonce, $bodyHash]);
        $sig = 'v1=' . hash_hmac('sha256', $canonical, self::APP_BUILDER_SECRET);

        return $this->withHeaders([
            'X-Lara-KeyId' => self::APP_BUILDER_ID,
            'X-Lara-Timestamp' => $ts,
            'X-Lara-Nonce' => $nonce,
            'X-Lara-Signature' => $sig,
            'Idempotency-Key' => 'idemp-' . $nonce,
        ])->postJson($uri, $payload);
    }

    private function configureShardTemplate(): void
    {
        config()->set('database.connections.shard_template', [
            'driver' => 'sqlite',
            'database' => $this->shardDbPath,
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]);
        config()->set('lara.environments', ['Production', 'Staging', 'Development']);
        config()->set('lara.license_category_codes', [7 => 'K']);
        config()->set('lara.portal_signing_keys', [
            self::APP_BUILDER_ID => base64_encode(self::APP_BUILDER_SECRET),
        ]);
        $this->bindShard(self::SLUG);
    }

    private function bindShard(string $slug): void
    {
        /** @var ShardResolver $resolver */
        $resolver = $this->app->make(ShardResolver::class);
        $resolver->bind($slug);
    }

    private function createRootFixtures(): void
    {
        $root = DB::connection('root');
        $root->statement('CREATE TABLE IF NOT EXISTS "IdempotencyRecords" (
            "IdempotencyKey" TEXT PRIMARY KEY,
            "ActorId" TEXT NOT NULL,
            "Endpoint" TEXT NOT NULL,
            "BodyHash" TEXT NOT NULL,
            "ResponseStatus" INTEGER NOT NULL,
            "ResponseHeadersJson" TEXT NOT NULL,
            "ResponseBody" TEXT NOT NULL,
            "CreatedAt" TEXT NOT NULL,
            "UpdatedAt" TEXT NOT NULL,
            "ExpiresAt" TEXT NOT NULL
        )');
        $root->statement('CREATE TABLE IF NOT EXISTS "Resellers" (
            "ResellerId" INTEGER PRIMARY KEY AUTOINCREMENT,
            "ResellerName" TEXT NOT NULL,
            "ResellerSlug" TEXT NOT NULL UNIQUE,
            "IsActive" INTEGER NOT NULL DEFAULT 1
        )');
        $root->statement('CREATE TABLE IF NOT EXISTS "Prefixes" (
            "PrefixId" INTEGER PRIMARY KEY AUTOINCREMENT,
            "ResellerId" INTEGER NOT NULL,
            "PrefixValue" TEXT NOT NULL UNIQUE,
            "IsActive" INTEGER NOT NULL DEFAULT 1
        )');
        $root->statement('CREATE TABLE IF NOT EXISTS "AppBuilders" (
            "AppBuilderId" INTEGER PRIMARY KEY AUTOINCREMENT,
            "ClientId" TEXT NOT NULL UNIQUE,
            "ClientSecretHash" TEXT NOT NULL,
            "IsActive" INTEGER NOT NULL DEFAULT 1
        )');
        // Seed AppBuilder for signature matching
        $root->table('AppBuilders')->insert([
            'ClientId' => self::APP_BUILDER_ID,
            'ClientSecretHash' => Hash::make(self::APP_BUILDER_SECRET),
            'IsActive' => 1,
        ]);
    }

    private function createShardTables(): void
    {
        $this->bindShard(self::SLUG);
        DB::connection('shard')->statement('CREATE TABLE IF NOT EXISTS "Licenses" (
            "LicenseId" INTEGER PRIMARY KEY AUTOINCREMENT,
            "LicenseKey" TEXT NOT NULL UNIQUE,
            "Status" TEXT NOT NULL,
            "EnvironmentName" TEXT NOT NULL,
            "PrefixValue" TEXT NOT NULL,
            "LicenseCategoryId" INTEGER NOT NULL,
            "ProductVersion" TEXT NOT NULL,
            "ExpiresAt" TEXT NULL,
            "RevokedAt" TEXT NULL
        )');
        DB::connection('shard')->statement('CREATE TABLE IF NOT EXISTS "Serials" (
            "SerialId" INTEGER PRIMARY KEY AUTOINCREMENT,
            "LicenseId" INTEGER NOT NULL,
            "SerialValue" TEXT NOT NULL UNIQUE,
            "DeviceIdHash" TEXT NOT NULL,
            "EnvironmentName" TEXT NOT NULL,
            "FeaturePayloadHash" TEXT NULL,
            "IdempotencyKey" TEXT NULL,
            "IsRevoked" INTEGER NOT NULL DEFAULT 0,
            "IssuedAt" TEXT NOT NULL
        )');
        DB::connection('shard')->statement('CREATE TABLE IF NOT EXISTS "Features" (
            "FeatureId" INTEGER PRIMARY KEY AUTOINCREMENT,
            "FeatureKey" TEXT NOT NULL,
            "DefaultValue" TEXT NOT NULL,
            "Description" TEXT
        )');
        DB::connection('shard')->statement('CREATE TABLE IF NOT EXISTS "TierFeatures" (
            "TierFeatureId" INTEGER PRIMARY KEY AUTOINCREMENT,
            "LicenseTierId" INTEGER NOT NULL,
            "FeatureId" INTEGER NOT NULL,
            "Value" TEXT NOT NULL
        )');
        DB::connection('shard')->statement('CREATE TABLE IF NOT EXISTS "LicenseFeatures" (
            "LicenseFeatureId" INTEGER PRIMARY KEY AUTOINCREMENT,
            "LicenseId" INTEGER NOT NULL,
            "FeatureId" INTEGER NOT NULL,
            "Value" TEXT NOT NULL,
            "CreatedByUserId" INTEGER NOT NULL
        )');
    }

    private function seedReseller(string $slug, string $name): int
    {
        return (int) DB::connection('root')->table('Resellers')->insertGetId([
            'ResellerName' => $name,
            'ResellerSlug' => $slug,
            'IsActive' => 1,
        ]);
    }

    private function seedPrefix(string $value, int $resellerId): void
    {
        DB::connection('root')->table('Prefixes')->insert([
            'PrefixValue' => $value,
            'ResellerId' => $resellerId,
            'IsActive' => 1,
        ]);
    }

    private function seedLicense(string $key, int $resellerId): void
    {
        $this->bindShard(self::SLUG);
        DB::connection('shard')->table('Licenses')->insert([
            'LicenseKey' => $key,
            'Status' => 'Active',
            'EnvironmentName' => 'Production',
            'PrefixValue' => self::PREFIX,
            'LicenseCategoryId' => 7,
            'ProductVersion' => 'V1',
            'ExpiresAt' => null,
            'RevokedAt' => null,
        ]);
    }
}
