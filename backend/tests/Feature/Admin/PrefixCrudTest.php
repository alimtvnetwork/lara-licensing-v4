<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Db\ShardResolver;
use App\Models\User;
use App\Policies\HasRolePolicy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Ramsey\Uuid\Uuid;
use Tests\Support\AssertsLaraException;
use Tests\TestCase;

/**
 * Plan 10 step 15 (Admin/* row: Admin/PrefixCrudTest).
 *
 * Root cause guarded (one sentence): the Root Prefixes registry is the
 * cross-tenant uniqueness domain feeding license-key + serial minting
 * on every reseller shard, but no HTTP-level lock existed for
 * `GET|POST /Api/Admin/Prefixes` or `DELETE /Api/Admin/Prefixes/{Value}`,
 * so regressions dropping the `require.role` gate, bypassing the
 * `PrefixStoreRequest` validation, letting a duplicate INSERT surface a
 * raw QueryException instead of `PrefixConflict`, letting a DELETE bypass
 * the cross-shard `assertNotInUseOnShard` in-use check (silently deleting
 * a prefix still referenced by live licenses), or dropping the
 * `PrefixCreated`/`PrefixDeleted` audit rows could all ship green.
 *
 * Branches guarded (10):
 *   1. GET  bare              -> 401 AuthUnauthorized
 *   2. GET  non-Admin caller  -> 403 AuthForbidden
 *   3. GET  happy (Admin)     -> 200, seeded prefix present, IsActive echoed
 *   4. POST malformed value   -> 400 ValidationFailed
 *   5. POST unknown reseller  -> 404 ResellerNotFound
 *   6. POST happy             -> 201, audit `PrefixCreated`
 *   7. POST duplicate value   -> 409 PrefixConflict (sqlite + pg unique map)
 *   8. DELETE unknown value   -> 404 PrefixNotFound
 *   9. DELETE in-use on shard -> 409 PrefixInUse (assertNotInUseOnShard)
 *  10. DELETE happy           -> 200, row removed, audit `PrefixDeleted`
 */
final class PrefixCrudTest extends TestCase
{
    use AssertsLaraException;

    private const ADMIN_EMAIL = 'admin-prefix@example.test';
    private const PASSWORD = 'CorrectHorse!Battery42';
    private const RESELLER_SLUG = 'acme';
    private const RESELLER_NAME = 'Acme';
    private const SEED_PREFIX = 'ACME01';
    private const NEW_PREFIX = 'ACME02';

    private User $admin;
    private int $resellerId;
    private string $adminBearer;
    private string $shardDbPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->shardDbPath = sys_get_temp_dir() . '/lara_test_prefix_shard_' . uniqid('', true) . '.sqlite';
        touch($this->shardDbPath);
        $this->configureShardTemplate();
        $this->createRootFixtures();
        $this->swapRolePolicy();
        $this->admin = $this->makeUser(self::ADMIN_EMAIL);
        $this->adminBearer = $this->openSessionAndMintToken($this->admin);
        $this->resellerId = $this->seedReseller(self::RESELLER_SLUG, self::RESELLER_NAME);
        $this->seedPrefix(self::SEED_PREFIX, $this->resellerId, true);
        $this->createShardLicensesTable();
    }

    protected function tearDown(): void
    {
        if (file_exists($this->shardDbPath)) {
            @unlink($this->shardDbPath);
        }
        parent::tearDown();
    }

    public function test_index_bare_returns_401(): void
    {
        $res = $this->getJson('/Api/Admin/Prefixes');
        $this->assertLaraException($res, 'AuthUnauthorized', 401);
    }

    public function test_index_non_admin_returns_403(): void
    {
        \Tests\Feature\FakeHasRolePolicy::$grants = [(string) $this->admin->getKey() => ['Reseller']];
        $res = $this->withHeader('Authorization', 'Bearer ' . $this->adminBearer)
            ->getJson('/Api/Admin/Prefixes');
        $this->assertLaraException($res, 'AuthForbidden', 403);
    }

    public function test_index_happy_path_lists_seeded_prefix(): void
    {
        \Tests\Feature\FakeHasRolePolicy::$grants = [(string) $this->admin->getKey() => ['Admin']];
        $res = $this->withHeader('Authorization', 'Bearer ' . $this->adminBearer)
            ->getJson('/Api/Admin/Prefixes');
        $res->assertStatus(200);
        $values = array_column($res->json('Results'), 'PrefixValue');
        $this->assertContains(self::SEED_PREFIX, $values, 'Seeded prefix must be listed.');
        foreach ($res->json('Results') as $row) {
            $this->assertArrayHasKey('IsActive', $row);
            $this->assertArrayHasKey('ResellerId', $row);
        }
    }

    public function test_store_validation_failed_on_malformed_value(): void
    {
        \Tests\Feature\FakeHasRolePolicy::$grants = [(string) $this->admin->getKey() => ['Admin']];
        $res = $this->withHeader('Authorization', 'Bearer ' . $this->adminBearer)
            ->postJson('/Api/Admin/Prefixes', [
                'ResellerId' => $this->resellerId,
                // lowercase + too short = regex + length fail
                'PrefixValue' => 'a',
            ]);
        $this->assertLaraException($res, 'ValidationFailed', 400);
    }

    public function test_store_unknown_reseller_returns_404(): void
    {
        \Tests\Feature\FakeHasRolePolicy::$grants = [(string) $this->admin->getKey() => ['Admin']];
        $res = $this->withHeader('Authorization', 'Bearer ' . $this->adminBearer)
            ->postJson('/Api/Admin/Prefixes', [
                'ResellerId' => 999999,
                'PrefixValue' => self::NEW_PREFIX,
            ]);
        $this->assertLaraException($res, 'ResellerNotFound', 404);
    }

    public function test_store_happy_path_writes_audit(): void
    {
        Log::spy();
        \Tests\Feature\FakeHasRolePolicy::$grants = [(string) $this->admin->getKey() => ['Admin']];
        $res = $this->withHeader('Authorization', 'Bearer ' . $this->adminBearer)
            ->postJson('/Api/Admin/Prefixes', [
                'ResellerId' => $this->resellerId,
                'PrefixValue' => self::NEW_PREFIX,
                'IsActive' => true,
            ]);
        $res->assertStatus(201);
        $this->assertSame(self::NEW_PREFIX, $res->json('Results.0.PrefixValue'));

        $row = DB::connection('root')->table('Prefixes')->where('PrefixValue', self::NEW_PREFIX)->first();
        $this->assertNotNull($row, 'Row must exist on Root DB.');
        $audit = DB::connection('root')->table('AuditLogs')
            ->where('Action', 'PrefixCreated')
            ->where('TargetType', 'Prefixes')
            ->first();
        $this->assertNotNull($audit, 'AuditWriter must record PrefixCreated.');
    }

    public function test_store_duplicate_value_returns_409_prefix_conflict(): void
    {
        \Tests\Feature\FakeHasRolePolicy::$grants = [(string) $this->admin->getKey() => ['Admin']];
        $res = $this->withHeader('Authorization', 'Bearer ' . $this->adminBearer)
            ->postJson('/Api/Admin/Prefixes', [
                'ResellerId' => $this->resellerId,
                'PrefixValue' => self::SEED_PREFIX, // already seeded
            ]);
        $this->assertLaraException($res, 'PrefixConflict', 409);
    }

    public function test_destroy_unknown_value_returns_404(): void
    {
        \Tests\Feature\FakeHasRolePolicy::$grants = [(string) $this->admin->getKey() => ['Admin']];
        $res = $this->withHeader('Authorization', 'Bearer ' . $this->adminBearer)
            ->deleteJson('/Api/Admin/Prefixes/ZZZ99');
        $this->assertLaraException($res, 'PrefixNotFound', 404);
    }

    public function test_destroy_in_use_on_shard_returns_409(): void
    {
        \Tests\Feature\FakeHasRolePolicy::$grants = [(string) $this->admin->getKey() => ['Admin']];
        // Simulate an active license on the shard consuming SEED_PREFIX.
        DB::connection('shard')->table('Licenses')->insert([
            'LicenseId' => 1,
            'PrefixValue' => self::SEED_PREFIX,
        ]);

        $res = $this->withHeader('Authorization', 'Bearer ' . $this->adminBearer)
            ->deleteJson('/Api/Admin/Prefixes/' . self::SEED_PREFIX);
        $this->assertLaraException($res, 'PrefixInUse', 409);

        // Row must still exist (no destructive side effect on refusal).
        $stillThere = DB::connection('root')->table('Prefixes')->where('PrefixValue', self::SEED_PREFIX)->count();
        $this->assertSame(1, $stillThere);
    }

    public function test_destroy_happy_path_writes_audit(): void
    {
        Log::spy();
        \Tests\Feature\FakeHasRolePolicy::$grants = [(string) $this->admin->getKey() => ['Admin']];
        // Zero licenses on shard for this prefix -> deletion allowed.
        $res = $this->withHeader('Authorization', 'Bearer ' . $this->adminBearer)
            ->deleteJson('/Api/Admin/Prefixes/' . self::SEED_PREFIX);
        $res->assertStatus(200);
        $this->assertSame(0, DB::connection('root')->table('Prefixes')->where('PrefixValue', self::SEED_PREFIX)->count());

        $audit = DB::connection('root')->table('AuditLogs')
            ->where('Action', 'PrefixDeleted')
            ->where('TargetType', 'Prefixes')
            ->first();
        $this->assertNotNull($audit, 'AuditWriter must record PrefixDeleted.');
    }

    private function configureShardTemplate(): void
    {
        config()->set('database.connections.shard_template', [
            'driver' => 'sqlite',
            'database' => $this->shardDbPath,
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]);
        // Pre-bind for our reseller so DB::connection('shard') resolves.
        /** @var ShardResolver $resolver */
        $resolver = $this->app->make(ShardResolver::class);
        $resolver->bind(self::RESELLER_SLUG);
    }

    private function createShardLicensesTable(): void
    {
        // Rebind (in case setUp already bound) and create the shard-side
        // Licenses table the destroy path scans for `PrefixValue` matches.
        /** @var ShardResolver $resolver */
        $resolver = $this->app->make(ShardResolver::class);
        $resolver->bind(self::RESELLER_SLUG);
        DB::connection('shard')->statement('CREATE TABLE IF NOT EXISTS "Licenses" (
            "LicenseId"   INTEGER PRIMARY KEY,
            "PrefixValue" TEXT NOT NULL
        )');
        DB::connection('shard')->table('Licenses')->truncate();
    }

    private function swapRolePolicy(): void
    {
        require_once __DIR__ . '/../RoleGateTest.php';
        \Tests\Feature\FakeHasRolePolicy::$grants = [];
        $this->app->instance(HasRolePolicy::class, new \Tests\Feature\FakeHasRolePolicy());
    }

    private function createRootFixtures(): void
    {
        $root = DB::connection('root');
        $root->statement('CREATE TABLE IF NOT EXISTS "Users" (
            "UserId" INTEGER PRIMARY KEY AUTOINCREMENT,
            "Email" TEXT NOT NULL UNIQUE,
            "PasswordHash" TEXT NOT NULL,
            "TenantId" INTEGER NULL,
            "IsActive" INTEGER NOT NULL DEFAULT 1,
            "CreatedAt" TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            "UpdatedAt" TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            "DeletedAt" TEXT NULL
        )');
        $root->statement('CREATE TABLE IF NOT EXISTS "AuthSessions" (
            "SessionId" TEXT PRIMARY KEY,
            "UserId" INTEGER NOT NULL,
            "Kind" TEXT NOT NULL,
            "ImpersonatorUserId" INTEGER NULL,
            "ParentSessionId" TEXT NULL,
            "CreatedAt" TEXT NOT NULL,
            "ExpiresAt" TEXT NOT NULL,
            "EndedAt" TEXT NULL,
            "RevokeReason" TEXT NULL
        )');
        $root->statement('CREATE TABLE IF NOT EXISTS "personal_access_tokens" (
            "id" INTEGER PRIMARY KEY AUTOINCREMENT,
            "tokenable_type" TEXT NOT NULL,
            "tokenable_id" INTEGER NOT NULL,
            "name" TEXT NOT NULL,
            "token" TEXT NOT NULL UNIQUE,
            "abilities" TEXT NULL,
            "last_used_at" TEXT NULL,
            "expires_at" TEXT NULL,
            "created_at" TEXT NULL,
            "updated_at" TEXT NULL
        )');
        $root->statement('CREATE TABLE IF NOT EXISTS "AuditLogs" (
            "AuditLogId" INTEGER PRIMARY KEY AUTOINCREMENT,
            "ActorType" TEXT NOT NULL,
            "ActorId" INTEGER NULL,
            "Action" TEXT NOT NULL,
            "TargetType" TEXT NOT NULL,
            "TargetId" TEXT NULL,
            "RequestId" TEXT NOT NULL,
            "PayloadJson" TEXT NULL,
            "CreatedAt" TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )');
        $root->statement('CREATE TABLE IF NOT EXISTS "Resellers" (
            "ResellerId" INTEGER PRIMARY KEY AUTOINCREMENT,
            "ResellerName" TEXT NOT NULL UNIQUE,
            "ResellerSlug" TEXT NOT NULL UNIQUE,
            "ContactEmail" TEXT NOT NULL,
            "IsActive" INTEGER NOT NULL DEFAULT 1,
            "CreatedAt" TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            "UpdatedAt" TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            "DeletedAt" TEXT NULL
        )');
        $root->statement('CREATE TABLE IF NOT EXISTS "Prefixes" (
            "PrefixId" INTEGER PRIMARY KEY AUTOINCREMENT,
            "ResellerId" INTEGER NOT NULL,
            "PrefixValue" TEXT NOT NULL UNIQUE,
            "IsActive" INTEGER NOT NULL DEFAULT 1,
            "CreatedAt" TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            "UpdatedAt" TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )');
    }

    private function seedReseller(string $slug, string $name): int
    {
        return (int) DB::connection('root')->table('Resellers')->insertGetId([
            'ResellerName' => $name,
            'ResellerSlug' => $slug,
            'ContactEmail' => 'ops@' . $slug . '.test',
            'IsActive' => 1,
        ]);
    }

    private function seedPrefix(string $value, int $resellerId, bool $isActive): void
    {
        DB::connection('root')->table('Prefixes')->insert([
            'ResellerId' => $resellerId,
            'PrefixValue' => $value,
            'IsActive' => $isActive ? 1 : 0,
        ]);
    }

    private function makeUser(string $email): User
    {
        $user = new User();
        $user->Email = $email;
        $user->PasswordHash = Hash::make(self::PASSWORD);
        $user->TenantId = null;
        $user->IsActive = true;
        $user->save();

        return $user->refresh();
    }

    private function openSessionAndMintToken(User $user): string
    {
        $sessionId = Uuid::uuid4()->toString();
        $now = Carbon::now();
        $row = new \App\Models\AuthSession();
        $row->SessionId = $sessionId;
        $row->UserId = (int) $user->getKey();
        $row->Kind = \App\Models\AuthSession::KIND_NORMAL;
        $row->ImpersonatorUserId = null;
        $row->ParentSessionId = null;
        $row->CreatedAt = $now;
        $row->ExpiresAt = $now->copy()->addMinutes(60);
        $row->EndedAt = null;
        $row->RevokeReason = null;
        $row->save();
        $token = $user->createToken($sessionId);

        return $token->plainTextToken;
    }
}
