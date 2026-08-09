<?php

declare(strict_types=1);

namespace Tests\Feature\Reseller;

use App\Db\ShardResolver;
use App\Models\User;
use App\Policies\HasRolePolicy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Ramsey\Uuid\Uuid;
use Tests\Support\AssertsLaraException;
use Tests\TestCase;

/**
 * Plan 10 step 15 (Reseller/* row: Reseller/LicenseIndexTest).
 *
 * Root cause guarded (one sentence): the Reseller portal license
 * directory (`GET /Api/Reseller/Licenses` and
 * `GET /Api/Reseller/Licenses/{LicenseKey}`) is the ONLY read surface
 * a reseller uses to enumerate its own shard-scoped licenses under the
 * `auth:sanctum` -> `session.active` -> `require.role:Reseller` ->
 * `ShardBindingMiddleware` chain, but no HTTP-level lock existed, so
 * regressions dropping the role gate (leaking Admin-only reads),
 * skipping shard binding (querying the wrong tenant), or removing the
 * defensive `ResellerId != $resellerId` predicate in
 * `requireOwnedLicense` (spec 21-app/12 existence-leak protection)
 * could all ship green.
 *
 * Branches guarded (7):
 *   1. GET index bare                -> 401 AuthUnauthorized
 *   2. GET index as non-Reseller     -> 403 AuthForbidden (role gate)
 *   3. GET index user without tenant -> 403 AuthForbidden (shard bind)
 *   4. GET index happy               -> 200 with own rows only, no foreign
 *   5. GET show unknown key          -> 404 LicenseNotFound
 *   6. GET show foreign license      -> 404 LicenseNotFound (existence leak)
 *   7. GET show happy                -> 200 with projected license
 */
final class LicenseIndexTest extends TestCase
{
    use AssertsLaraException;

    private const RESELLER_EMAIL = 'reseller-license@example.test';
    private const PASSWORD = 'CorrectHorse!Battery42';
    private const RESELLER_SLUG = 'acme';
    private const RESELLER_NAME = 'Acme';
    private const FOREIGN_SLUG = 'globex';
    private const FOREIGN_NAME = 'Globex';
    private const PREFIX = 'ACME01';
    private const OWN_KEY_A = 'ACME01-AAAA1111';
    private const OWN_KEY_B = 'ACME01-BBBB2222';
    private const FOREIGN_KEY = 'GLBX01-CCCC3333';

    private User $reseller;
    private int $resellerId;
    private int $foreignResellerId;
    private string $bearer;
    private string $shardDbPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->shardDbPath = sys_get_temp_dir() . '/lara_test_reseller_licenses_' . uniqid('', true) . '.sqlite';
        touch($this->shardDbPath);
        $this->configureShardTemplate();
        $this->createRootFixtures();
        $this->swapRolePolicy();
        $this->resellerId = $this->seedReseller(self::RESELLER_SLUG, self::RESELLER_NAME);
        $this->foreignResellerId = $this->seedReseller(self::FOREIGN_SLUG, self::FOREIGN_NAME);
        $this->reseller = $this->makeUser(self::RESELLER_EMAIL, $this->resellerId);
        $this->bearer = $this->openSessionAndMintToken($this->reseller);
        $this->createShardLicensesTable();
        $this->seedShardLicense(1, self::OWN_KEY_A, $this->resellerId);
        $this->seedShardLicense(2, self::OWN_KEY_B, $this->resellerId);
        // Foreign row lives on the same physical sqlite file (shard_template
        // maps every slug to the same test file), but carries a different
        // ResellerId so the controller predicate must exclude it.
        $this->seedShardLicense(3, self::FOREIGN_KEY, $this->foreignResellerId);
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
        $res = $this->getJson('/Api/Reseller/Licenses');
        $this->assertLaraException($res, 'AuthUnauthorized', 401);
    }

    public function test_index_non_reseller_returns_403(): void
    {
        \Tests\Feature\FakeHasRolePolicy::$grants = [(string) $this->reseller->getKey() => ['Admin']];
        $res = $this->withHeader('Authorization', 'Bearer ' . $this->bearer)
            ->getJson('/Api/Reseller/Licenses');
        $this->assertLaraException($res, 'AuthForbidden', 403);
    }

    public function test_index_user_without_tenant_returns_403(): void
    {
        // Role gate passes (Reseller) but ShardBindingMiddleware rejects
        // callers whose Users.TenantId is null/0 with AuthForbidden and
        // RequiredScope=ResellerTenant.
        $rootless = $this->makeUser('rootless-reseller@example.test', null);
        $bearer = $this->openSessionAndMintToken($rootless);
        \Tests\Feature\FakeHasRolePolicy::$grants = [(string) $rootless->getKey() => ['Reseller']];
        $res = $this->withHeader('Authorization', 'Bearer ' . $bearer)
            ->getJson('/Api/Reseller/Licenses');
        $this->assertLaraException($res, 'AuthForbidden', 403);
    }

    public function test_index_happy_path_lists_only_own_licenses(): void
    {
        \Tests\Feature\FakeHasRolePolicy::$grants = [(string) $this->reseller->getKey() => ['Reseller']];
        $res = $this->withHeader('Authorization', 'Bearer ' . $this->bearer)
            ->getJson('/Api/Reseller/Licenses');
        $res->assertStatus(200);
        $keys = array_column($res->json('Results'), 'LicenseKey');
        $this->assertContains(self::OWN_KEY_A, $keys);
        $this->assertContains(self::OWN_KEY_B, $keys);
        $this->assertNotContains(self::FOREIGN_KEY, $keys, 'Foreign-tenant row must not leak into caller shard scope.');
        foreach ($res->json('Results') as $row) {
            $this->assertSame($this->resellerId, (int) $row['ResellerId']);
            $this->assertArrayHasKey('Version', $row);
            $this->assertArrayHasKey('Status', $row);
        }
    }

    public function test_show_unknown_key_returns_404(): void
    {
        \Tests\Feature\FakeHasRolePolicy::$grants = [(string) $this->reseller->getKey() => ['Reseller']];
        $res = $this->withHeader('Authorization', 'Bearer ' . $this->bearer)
            ->getJson('/Api/Reseller/Licenses/ACME01-ZZZZ9999');
        $this->assertLaraException($res, 'LicenseNotFound', 404);
    }

    public function test_show_foreign_license_returns_404(): void
    {
        // The row exists on the same shard file but carries a different
        // ResellerId. `requireOwnedLicense` must return NotFound (existence
        // leak protection per spec 21-app/12), not 200 or 403.
        \Tests\Feature\FakeHasRolePolicy::$grants = [(string) $this->reseller->getKey() => ['Reseller']];
        $res = $this->withHeader('Authorization', 'Bearer ' . $this->bearer)
            ->getJson('/Api/Reseller/Licenses/' . self::FOREIGN_KEY);
        $this->assertLaraException($res, 'LicenseNotFound', 404);
    }

    public function test_show_happy_path_returns_projected_license(): void
    {
        \Tests\Feature\FakeHasRolePolicy::$grants = [(string) $this->reseller->getKey() => ['Reseller']];
        $res = $this->withHeader('Authorization', 'Bearer ' . $this->bearer)
            ->getJson('/Api/Reseller/Licenses/' . self::OWN_KEY_A);
        $res->assertStatus(200);
        $this->assertSame(self::OWN_KEY_A, $res->json('Results.0.LicenseKey'));
        $this->assertSame($this->resellerId, (int) $res->json('Results.0.ResellerId'));
        $this->assertSame(self::PREFIX, $res->json('Results.0.PrefixValue'));
    }

    private function configureShardTemplate(): void
    {
        config()->set('database.connections.shard_template', [
            'driver' => 'sqlite',
            'database' => $this->shardDbPath,
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]);
        /** @var ShardResolver $resolver */
        $resolver = $this->app->make(ShardResolver::class);
        $resolver->bind(self::RESELLER_SLUG);
    }

    private function createShardLicensesTable(): void
    {
        /** @var ShardResolver $resolver */
        $resolver = $this->app->make(ShardResolver::class);
        $resolver->bind(self::RESELLER_SLUG);
        DB::connection('shard')->statement('CREATE TABLE IF NOT EXISTS "Licenses" (
            "LicenseId"            INTEGER PRIMARY KEY,
            "LicenseKey"           TEXT NOT NULL UNIQUE,
            "PrefixValue"          TEXT NOT NULL,
            "ResellerId"           INTEGER NOT NULL,
            "IssuedByUserId"       INTEGER NOT NULL DEFAULT 0,
            "IssuerActorType"      TEXT NOT NULL DEFAULT "Reseller",
            "LicenseCategoryId"    INTEGER NULL,
            "TierName"             TEXT NOT NULL DEFAULT "Tier1",
            "EnvironmentName"      TEXT NOT NULL DEFAULT "Prod",
            "ProductVersion"       TEXT NOT NULL DEFAULT "V1",
            "Status"               TEXT NOT NULL DEFAULT "Active",
            "IssuedAt"             TEXT NULL,
            "ExpiresAt"            TEXT NULL,
            "RevokedAt"            TEXT NULL,
            "RevokedByUserId"      INTEGER NULL,
            "RevokeReason"         TEXT NULL,
            "ResellerQuotaLedgerId" INTEGER NULL,
            "Version"              INTEGER NOT NULL DEFAULT 1,
            "CreatedAt"            TEXT NULL,
            "UpdatedAt"            TEXT NULL,
            "DeletedAt"            TEXT NULL
        )');
        DB::connection('shard')->table('Licenses')->truncate();
    }

    private function seedShardLicense(int $id, string $key, int $resellerId): void
    {
        $now = Carbon::now()->format('Y-m-d\TH:i:s\Z');
        DB::connection('shard')->table('Licenses')->insert([
            'LicenseId' => $id,
            'LicenseKey' => $key,
            'PrefixValue' => substr($key, 0, strpos($key, '-') ?: 6),
            'ResellerId' => $resellerId,
            'IssuedByUserId' => 1,
            'IssuerActorType' => 'Reseller',
            'TierName' => 'Tier1',
            'EnvironmentName' => 'Prod',
            'ProductVersion' => 'V1',
            'Status' => 'Active',
            'IssuedAt' => $now,
            'ExpiresAt' => Carbon::now()->addYear()->format('Y-m-d\TH:i:s\Z'),
            'Version' => 1,
            'CreatedAt' => $now,
            'UpdatedAt' => $now,
        ]);
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

    private function makeUser(string $email, ?int $tenantId): User
    {
        $user = new User();
        $user->Email = $email;
        $user->PasswordHash = Hash::make(self::PASSWORD);
        $user->TenantId = $tenantId;
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
