<?php

declare(strict_types=1);

namespace Tests\Feature\Portal;

use App\Db\ShardResolver;
use App\Models\License;
use App\Models\Prefix;
use App\Models\Reseller;
use App\Models\Serial;
use App\Models\VerifyKey;
use App\Services\EnvironmentService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\AssertsLaraException;
use Tests\TestCase;

/**
 * Plan 06 step 55. Portal Verify/Final handshake E2E.
 */
final class VerifyFinalHandshakeTest extends TestCase
{
    use AssertsLaraException;

    private const PREFIX = 'ACME';
    private const SLUG = 'acme';
    private const SERIAL_VALUE = 'ACME-K-V1-ABCD-1234-EFGH-5678';
    private const HASH_KEY = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';
    private const ENV_ID = 1; // Production
    private const VERIFY_KEY = 'a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4';

    private int $resellerId;
    private int $licenseId;
    private int $serialId;
    private string $shardDbPath;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Ensure Root DB is using SQLite memory for tests
        config()->set('database.connections.root', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]);

        $this->shardDbPath = sys_get_temp_dir() . '/lara_test_verify_shard_' . uniqid('', true) . '.sqlite';
        touch($this->shardDbPath);
        $this->configureShardTemplate();
        $this->createRootFixtures();
        $this->createShardTables();
        $this->resellerId = $this->seedReseller(self::SLUG, 'Acme Corp');
        $this->seedPrefix(self::PREFIX, $this->resellerId);
        $this->licenseId = $this->seedLicense(self::SLUG);
        $this->serialId = $this->seedSerial(self::SERIAL_VALUE, $this->licenseId);
        
        // Provision signing keys for Middleware
        config()->set('lara.portal_signing_keys', [
            'test-key' => base64_encode('test-secret-32-chars-long-exactly-!!'),
        ]);
    }

    protected function tearDown(): void
    {
        if (file_exists($this->shardDbPath)) {
            @unlink($this->shardDbPath);
        }
        parent::tearDown();
    }

    public function test_final_handshake_happy_path_200(): void
    {
        $this->seedVerifyKey(self::VERIFY_KEY, $this->licenseId, $this->serialId, self::HASH_KEY);

        $body = [
            'SerialValue' => self::SERIAL_VALUE,
            'HashKey' => self::HASH_KEY,
            'VerifyKey' => self::VERIFY_KEY,
            'EnvironmentId' => self::ENV_ID,
        ];

        $headers = $this->signedHeaders('POST', 'api/Portal/Verify/Final', $body);
        $headers['X-Request-Id'] = 'test-req-123';

        $res = $this->postJson('/api/Portal/Verify/Final', $body, $headers);

        $res->assertStatus(200);
        $res->assertJsonPath('Results.0.IsAuthorized', true);
        $res->assertJsonPath('Results.0.EnvironmentId', self::ENV_ID);
        $res->assertJsonStructure(['Results' => [['Features', 'AuthorizedAt', 'ExpiresAt']]]);

        // Verify consumption
        $this->bindShard(self::SLUG);
        $this->assertDatabaseHas('VerifyKeys', [
            'VerifyKeyValue' => self::VERIFY_KEY,
            'IsConsumed' => 1,
        ], 'shard');
    }

    public function test_final_handshake_consumed_key_returns_403(): void
    {
        $this->seedVerifyKey(self::VERIFY_KEY, $this->licenseId, $this->serialId, self::HASH_KEY, isConsumed: true);

        $body = [
            'SerialValue' => self::SERIAL_VALUE,
            'HashKey' => self::HASH_KEY,
            'VerifyKey' => self::VERIFY_KEY,
            'EnvironmentId' => self::ENV_ID,
        ];

        $headers = $this->signedHeaders('POST', 'api/Portal/Verify/Final', $body);
        $headers['X-Request-Id'] = 'test-req-456';

        $res = $this->postJson('/api/Portal/Verify/Final', $body, $headers);

        $this->assertLaraException($res, 'VerifyKeyConsumed', 409);
    }

    public function test_final_handshake_expired_key_returns_403(): void
    {
        $this->seedVerifyKey(self::VERIFY_KEY, $this->licenseId, $this->serialId, self::HASH_KEY, expiresAt: Carbon::now()->subMinutes(1));

        $body = [
            'SerialValue' => self::SERIAL_VALUE,
            'HashKey' => self::HASH_KEY,
            'VerifyKey' => self::VERIFY_KEY,
            'EnvironmentId' => self::ENV_ID,
        ];

        $headers = $this->signedHeaders('POST', 'api/Portal/Verify/Final', $body);
        $headers['X-Request-Id'] = 'test-req-789';

        $res = $this->postJson('/api/Portal/Verify/Final', $body, $headers);

        $this->assertLaraException($res, 'VerifyKeyExpired', 409);
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
        config()->set('lara.feature_registry', [
            'FeatA' => ['ValueType' => 'Boolean'],
        ]);
        config()->set('lara.error_codes', [
            'VerifyKeyConsumed',
            'VerifyKeyExpired',
            'VerifyKeyNotFound',
            'HashKeyMismatch',
        ]);
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
        $root->statement('CREATE TABLE IF NOT EXISTS "Features" (
            "FeatureId" INTEGER PRIMARY KEY AUTOINCREMENT,
            "FeatureKey" TEXT NOT NULL UNIQUE,
            "ValueType" TEXT NOT NULL
        )');
        $root->statement('CREATE TABLE IF NOT EXISTS "TierFeatures" (
            "TierFeatureId" INTEGER PRIMARY KEY AUTOINCREMENT,
            "LicenseTierId" INTEGER NOT NULL,
            "FeatureId" INTEGER NOT NULL,
            "Value" TEXT NOT NULL
        )');
        $root->table('Features')->insert(['FeatureKey' => 'FeatA', 'ValueType' => 'Boolean']);
    }

    private function createShardTables(): void
    {
        $this->bindShard(self::SLUG);
        $shard = DB::connection('shard');
        $shard->statement('CREATE TABLE IF NOT EXISTS "Licenses" (
            "LicenseId" INTEGER PRIMARY KEY AUTOINCREMENT,
            "LicenseKey" TEXT NOT NULL UNIQUE,
            "Status" TEXT NOT NULL,
            "EnvironmentName" TEXT NOT NULL,
            "PrefixValue" TEXT NOT NULL,
            "LicenseCategoryId" INTEGER NOT NULL,
            "ProductVersion" TEXT NOT NULL,
            "LicenseTierId" INTEGER NULL,
            "ExpiresAt" TEXT NULL,
            "RevokedAt" TEXT NULL,
            "Version" INTEGER NOT NULL DEFAULT 1
        )');
        $shard->statement('CREATE TABLE IF NOT EXISTS "Serials" (
            "SerialId" INTEGER PRIMARY KEY AUTOINCREMENT,
            "LicenseId" INTEGER NOT NULL,
            "SerialValue" TEXT NOT NULL UNIQUE,
            "DeviceIdHash" TEXT NOT NULL,
            "EnvironmentName" TEXT NOT NULL,
            "IsRevoked" INTEGER NOT NULL DEFAULT 0,
            "IssuedAt" TEXT NOT NULL,
            "IdempotencyKey" TEXT NOT NULL
        )');
        $shard->statement('CREATE TABLE IF NOT EXISTS "VerifyKeys" (
            "VerifyKeyId" INTEGER PRIMARY KEY AUTOINCREMENT,
            "LicenseId" INTEGER NOT NULL,
            "SerialId" INTEGER NOT NULL,
            "HashKeyDigest" TEXT NOT NULL,
            "VerifyKeyValue" TEXT NOT NULL UNIQUE,
            "IssuedAt" TEXT NOT NULL,
            "ExpiresAt" TEXT NOT NULL,
            "IsConsumed" INTEGER NOT NULL DEFAULT 0,
            "ConsumedAt" TEXT NULL,
            "RequestId" TEXT NOT NULL,
            "CreatedAt" TEXT NOT NULL,
            "UpdatedAt" TEXT NOT NULL
        )');
        $shard->statement('CREATE TABLE IF NOT EXISTS "MachineBindings" (
            "MachineBindingId" INTEGER PRIMARY KEY AUTOINCREMENT,
            "LicenseId" INTEGER NOT NULL,
            "FingerprintHash" TEXT NOT NULL,
            "FirstSeenAt" TEXT NOT NULL,
            "LastSeenAt" TEXT NOT NULL,
            "ReleasedAt" TEXT NULL,
            "RebindCooldownUntil" TEXT NULL,
            "CreatedAt" TEXT NOT NULL,
            "UpdatedAt" TEXT NOT NULL
        )');
        $shard->statement('CREATE TABLE IF NOT EXISTS "UserBindings" (
            "UserBindingId" INTEGER PRIMARY KEY AUTOINCREMENT,
            "LicenseId" INTEGER NOT NULL,
            "UserIdentifier" TEXT NOT NULL,
            "FirstSeenAt" TEXT NOT NULL,
            "LastSeenAt" TEXT NOT NULL,
            "IsReleased" INTEGER NOT NULL DEFAULT 0,
            "CreatedAt" TEXT NOT NULL,
            "UpdatedAt" TEXT NOT NULL
        )');
        $shard->statement('CREATE TABLE IF NOT EXISTS "LicenseFeatures" (
            "LicenseFeatureId" INTEGER PRIMARY KEY AUTOINCREMENT,
            "LicenseId" INTEGER NOT NULL,
            "FeatureId" INTEGER NOT NULL,
            "Value" TEXT NOT NULL,
            "CreatedByUserId" INTEGER NOT NULL
        )');
        $shard->statement('CREATE TABLE IF NOT EXISTS "TierFeatures" (
            "TierFeatureId" INTEGER PRIMARY KEY AUTOINCREMENT,
            "LicenseTierId" INTEGER NOT NULL,
            "FeatureId" INTEGER NOT NULL,
            "Value" TEXT NOT NULL
        )');
        $shard->statement('CREATE TABLE IF NOT EXISTS "Features" (
            "FeatureId" INTEGER PRIMARY KEY AUTOINCREMENT,
            "FeatureKey" TEXT NOT NULL,
            "DefaultValue" TEXT NOT NULL,
            "Description" TEXT
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

    private function seedLicense(string $slug): int
    {
        $this->bindShard($slug);

        return (int) DB::connection('shard')->table('Licenses')->insertGetId([
            'LicenseKey' => 'ACME-PROD-V1-TEST',
            'Status' => 'Active',
            'EnvironmentName' => 'Production',
            'PrefixValue' => self::PREFIX,
            'LicenseCategoryId' => 7,
            'ProductVersion' => 'V1',
            'LicenseTierId' => 1,
        ]);
    }

    private function seedSerial(string $value, int $licenseId): int
    {
        return (int) DB::connection('shard')->table('Serials')->insertGetId([
            'SerialValue' => $value,
            'LicenseId' => $licenseId,
            'DeviceIdHash' => 'some-device-hash',
            'EnvironmentName' => 'Production',
            'IssuedAt' => Carbon::now(),
            'IdempotencyKey' => 'test-idempotency',
        ]);
    }

    private function seedVerifyKey(string $value, int $licenseId, int $serialId, string $hashKey, bool $isConsumed = false, ?Carbon $expiresAt = null): void
    {
        DB::connection('shard')->table('VerifyKeys')->insert([
            'VerifyKeyValue' => $value,
            'LicenseId' => $licenseId,
            'SerialId' => $serialId,
            'HashKeyDigest' => hash('sha256', $hashKey),
            'IssuedAt' => Carbon::now(),
            'ExpiresAt' => $expiresAt ?? Carbon::now()->addMinutes(5),
            'IsConsumed' => $isConsumed ? 1 : 0,
            'RequestId' => 'test-req',
            'CreatedAt' => Carbon::now(),
            'UpdatedAt' => Carbon::now(),
        ]);
    }

    private function signedHeaders(string $method, string $path, array $body): array
    {
        $timestamp = time();
        $nonce = bin2hex(random_bytes(16));
        $bodyJson = json_encode($body);
        $bodyHash = hash('sha256', $bodyJson);
        
        $canonical = implode("\n", [
            'v1',
            strtoupper($method),
            strtolower(ltrim($path, '/')), // No leading slash
            $timestamp,
            $nonce,
            $bodyHash
        ]);

        $secret = base64_decode(config('lara.portal_signing_keys.test-key'));
        $signature = hash_hmac('sha256', $canonical, $secret);

        return [
            'X-Lara-KeyId' => 'test-key',
            'X-Lara-Timestamp' => (string) $timestamp,
            'X-Lara-Nonce' => $nonce,
            'X-Lara-Signature' => 'v1=' . $signature,
            'Idempotency-Key' => 'idemp-' . $nonce,
        ];
    }
}
