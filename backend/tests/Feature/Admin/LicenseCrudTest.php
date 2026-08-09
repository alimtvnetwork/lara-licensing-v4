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
 * Plan 10 step 15 (Admin/* row: Admin/LicenseCrudTest).
 *
 * Root cause guarded (one sentence): `Admin\LicenseController` is the
 * only path that mints licenses on a reseller shard, restores or skips
 * quota on revoke, and bumps `Version` under If-Match, but no HTTP-level
 * lock existed for `POST|GET|PATCH|DELETE /Api/Admin/Licenses[/{Key}]`,
 * so regressions in the `require.role` gate, `assertCatalogSeeded`
 * preflight ordering (masks `FeatureCatalogUnseeded` behind
 * `ResellerNotFound`), `LicenseIssueRequest` validation, prefix
 * ownership check (`PrefixForbidden`), `LicenseIssued` audit,
 * If-Match enforcement on PATCH, or the `RestoreSkippedReason =
 * AdminIssued` decision on revoke of an admin-issued license could
 * all ship green.
 *
 * Branches guarded (10):
 *   1.  POST   bare                          -> 401 AuthUnauthorized
 *   2.  POST   non-Admin caller              -> 403 AuthForbidden
 *   3.  POST   missing TierName              -> 400 ValidationFailed
 *   4.  POST   unknown reseller              -> 404 ResellerNotFound
 *   5.  POST   prefix not owned by reseller  -> 403 PrefixForbidden
 *   6.  POST   unseeded Features catalog     -> 500 FeatureCatalogUnseeded
 *                                               (preflight runs BEFORE
 *                                               tenant lookup)
 *   7.  POST   happy                         -> 201 + `LicenseIssued`
 *                                               audit, ResellerQuotaLedgerId
 *                                               NULL, IssuerActorType=Admin
 *   8.  GET    unknown key                   -> 404 LicenseNotFound
 *   9.  PATCH  without If-Match              -> 428 PreconditionRequired
 *   10. DELETE admin-issued (revoke)         -> 200 + RestoreSkippedReason
 *                                               = AdminIssued + audit
 */
final class LicenseCrudTest extends TestCase
{
    use AssertsLaraException;

    private const ADMIN_EMAIL = 'admin-license@example.test';
    private const PASSWORD = 'CorrectHorse!Battery42';
    private const RESELLER_SLUG = 'acme';
    private const RESELLER_NAME = 'Acme';
    private const OTHER_SLUG = 'beta-corp';
    private const OTHER_NAME = 'Beta Corp';
    private const PREFIX = 'ACME01';
    private const OTHER_PREFIX = 'BETA01';

    private User $admin;
    private int $resellerId;
    private int $otherResellerId;
    private string $adminBearer;
    private string $shardDbPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->shardDbPath = sys_get_temp_dir() . '/lara_test_license_shard_' . uniqid('', true) . '.sqlite';
        touch($this->shardDbPath);
        $this->configureShardTemplate();
        $this->createRootFixtures();
        $this->createShardLicensesTable();
        $this->seedFeaturesCatalog();
        $this->swapRolePolicy();
        $this->admin = $this->makeUser(self::ADMIN_EMAIL);
        $this->adminBearer = $this->openSessionAndMintToken($this->admin);
        $this->resellerId = $this->seedReseller(self::RESELLER_SLUG, self::RESELLER_NAME);
        $this->otherResellerId = $this->seedReseller(self::OTHER_SLUG, self::OTHER_NAME);
        $this->seedPrefix(self::PREFIX, $this->resellerId, true);
        $this->seedPrefix(self::OTHER_PREFIX, $this->otherResellerId, true);
    }

    protected function tearDown(): void
    {
        if (file_exists($this->shardDbPath)) {
            @unlink($this->shardDbPath);
        }
        parent::tearDown();
    }

    public function test_issue_bare_returns_401(): void
    {
        $res = $this->postJson('/Api/Admin/Licenses', $this->validIssueBody());
        $this->assertLaraException($res, 'AuthUnauthorized', 401);
    }

    public function test_issue_non_admin_returns_403(): void
    {
        \Tests\Feature\FakeHasRolePolicy::$grants = [(string) $this->admin->getKey() => ['Reseller']];
        $res = $this->withHeader('Authorization', 'Bearer ' . $this->adminBearer)
            ->postJson('/Api/Admin/Licenses', $this->validIssueBody());
        $this->assertLaraException($res, 'AuthForbidden', 403);
    }

    public function test_issue_missing_tier_returns_400(): void
    {
        \Tests\Feature\FakeHasRolePolicy::$grants = [(string) $this->admin->getKey() => ['Admin']];
        $body = $this->validIssueBody();
        unset($body['TierName']);
        $res = $this->withHeader('Authorization', 'Bearer ' . $this->adminBearer)
            ->postJson('/Api/Admin/Licenses', $body);
        $this->assertLaraException($res, 'ValidationFailed', 400);
    }

    public function test_issue_unknown_reseller_returns_404(): void
    {
        \Tests\Feature\FakeHasRolePolicy::$grants = [(string) $this->admin->getKey() => ['Admin']];
        $body = $this->validIssueBody();
        $body['ResellerSlug'] = 'no-such-tenant';
        $res = $this->withHeader('Authorization', 'Bearer ' . $this->adminBearer)
            ->postJson('/Api/Admin/Licenses', $body);
        $this->assertLaraException($res, 'ResellerNotFound', 404);
    }

    public function test_issue_prefix_not_owned_returns_403_prefix_forbidden(): void
    {
        \Tests\Feature\FakeHasRolePolicy::$grants = [(string) $this->admin->getKey() => ['Admin']];
        $body = $this->validIssueBody();
        // Prefix belongs to the OTHER reseller, mismatch with ResellerSlug.
        $body['PrefixValue'] = self::OTHER_PREFIX;
        $res = $this->withHeader('Authorization', 'Bearer ' . $this->adminBearer)
            ->postJson('/Api/Admin/Licenses', $body);
        $this->assertLaraException($res, 'PrefixForbidden', 403);
    }

    public function test_issue_unseeded_features_catalog_returns_feature_catalog_unseeded(): void
    {
        // Wipe the seeded features so assertCatalogSeeded() must fire
        // BEFORE any tenant lookup can mask the real root cause.
        DB::connection('root')->table('Features')->truncate();

        \Tests\Feature\FakeHasRolePolicy::$grants = [(string) $this->admin->getKey() => ['Admin']];
        $res = $this->withHeader('Authorization', 'Bearer ' . $this->adminBearer)
            ->postJson('/Api/Admin/Licenses', $this->validIssueBody());
        $this->assertLaraException($res, 'FeatureCatalogUnseeded', 500);
    }

    public function test_issue_happy_path_writes_audit_and_shard_row(): void
    {
        Log::spy();
        \Tests\Feature\FakeHasRolePolicy::$grants = [(string) $this->admin->getKey() => ['Admin']];
        $res = $this->withHeader('Authorization', 'Bearer ' . $this->adminBearer)
            ->postJson('/Api/Admin/Licenses', $this->validIssueBody());
        $res->assertStatus(201);
        $licenseKey = (string) $res->json('Results.0.LicenseKey');
        $this->assertNotEmpty($licenseKey);
        $this->assertStringStartsWith(self::PREFIX . '-', $licenseKey);

        // Rebind shard for direct verification (ShardResolver::purge on
        // repeated bind is why we use a file-backed sqlite for the shard).
        $this->bindShard(self::RESELLER_SLUG);
        $row = DB::connection('shard')->table('Licenses')
            ->where('LicenseKey', $licenseKey)->first();
        $this->assertNotNull($row, 'License row must exist on the reseller shard.');
        $this->assertSame('Admin', (string) $row->IssuerActorType);
        $this->assertNull($row->ResellerQuotaLedgerId, 'Admin-issued rows must not consume reseller quota (spec 48 §1.4).');
        $this->assertSame('Active', (string) $row->Status);

        $audit = DB::connection('root')->table('AuditLogs')
            ->where('Action', 'LicenseIssued')
            ->where('TargetType', 'Licenses')
            ->first();
        $this->assertNotNull($audit, 'AuditWriter must record LicenseIssued.');
    }

    public function test_show_unknown_key_returns_404(): void
    {
        \Tests\Feature\FakeHasRolePolicy::$grants = [(string) $this->admin->getKey() => ['Admin']];
        $res = $this->withHeader('Authorization', 'Bearer ' . $this->adminBearer)
            ->getJson('/Api/Admin/Licenses/ACME01-DEADBEEFCAFEBABE?ResellerSlug=' . self::RESELLER_SLUG);
        $this->assertLaraException($res, 'LicenseNotFound', 404);
    }

    public function test_patch_without_if_match_returns_428(): void
    {
        \Tests\Feature\FakeHasRolePolicy::$grants = [(string) $this->admin->getKey() => ['Admin']];
        // Route + shard scope trigger EtagMiddleware; If-Match required
        // regardless of whether the LicenseKey exists.
        $res = $this->withHeader('Authorization', 'Bearer ' . $this->adminBearer)
            ->patchJson('/Api/Admin/Licenses/ACME01-DEADBEEFCAFEBABE?ResellerSlug=' . self::RESELLER_SLUG, [
                'TierName' => 'Tier2',
            ]);
        $this->assertLaraException($res, 'PreconditionRequired', 428);
    }

    public function test_revoke_admin_issued_license_records_restore_skipped_admin_issued(): void
    {
        Log::spy();
        \Tests\Feature\FakeHasRolePolicy::$grants = [(string) $this->admin->getKey() => ['Admin']];
        // Issue an admin license first so we have a real key to revoke.
        $issueRes = $this->withHeader('Authorization', 'Bearer ' . $this->adminBearer)
            ->postJson('/Api/Admin/Licenses', $this->validIssueBody());
        $issueRes->assertStatus(201);
        $licenseKey = (string) $issueRes->json('Results.0.LicenseKey');

        // Fetch fresh ETag so If-Match matches on DELETE (EtagMiddleware
        // scope covers DELETE:api/admin/licenses/).
        $this->bindShard(self::RESELLER_SLUG);
        $showRes = $this->withHeader('Authorization', 'Bearer ' . $this->adminBearer)
            ->getJson('/Api/Admin/Licenses/' . $licenseKey . '?ResellerSlug=' . self::RESELLER_SLUG);
        $showRes->assertStatus(200);
        $etag = $showRes->headers->get('ETag');
        $this->assertNotEmpty($etag, 'GET must emit ETag header.');

        $revokeRes = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminBearer,
            'If-Match' => $etag,
        ])->deleteJson('/Api/Admin/Licenses/' . $licenseKey . '?ResellerSlug=' . self::RESELLER_SLUG, [
            'RevokeReason' => 'AdminForced',
        ]);
        $revokeRes->assertStatus(200);
        $this->assertFalse((bool) $revokeRes->json('Results.0.QuotaRestored'));
        $this->assertSame('AdminIssued', (string) $revokeRes->json('Results.0.RestoreSkippedReason'));

        $audit = DB::connection('root')->table('AuditLogs')
            ->where('Action', 'LicenseRevoked')
            ->where('TargetType', 'Licenses')
            ->first();
        $this->assertNotNull($audit, 'AuditWriter must record LicenseRevoked.');
    }

    /**
     * @return array{ResellerSlug:string,PrefixValue:string,TierName:string,EnvironmentName:string,LicenseCategory:string}
     */
    private function validIssueBody(): array
    {
        return [
            'ResellerSlug' => self::RESELLER_SLUG,
            'PrefixValue' => self::PREFIX,
            'TierName' => 'Tier1',
            'EnvironmentName' => 'Production',
            'LicenseCategory' => 'Key',
        ];
    }

    private function configureShardTemplate(): void
    {
        config()->set('database.connections.shard_template', [
            'driver' => 'sqlite',
            'database' => $this->shardDbPath,
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]);
        $this->bindShard(self::RESELLER_SLUG);
    }

    private function bindShard(string $slug): void
    {
        /** @var ShardResolver $resolver */
        $resolver = $this->app->make(ShardResolver::class);
        $resolver->bind($slug);
    }

    private function createShardLicensesTable(): void
    {
        $this->bindShard(self::RESELLER_SLUG);
        DB::connection('shard')->statement('CREATE TABLE IF NOT EXISTS "Licenses" (
            "LicenseId"             INTEGER PRIMARY KEY AUTOINCREMENT,
            "LicenseKey"            TEXT NOT NULL UNIQUE,
            "PrefixValue"           TEXT NOT NULL,
            "ResellerId"            INTEGER NOT NULL,
            "IssuedByUserId"        INTEGER NOT NULL,
            "IssuerActorType"       TEXT NOT NULL,
            "LicenseCategoryId"     INTEGER NOT NULL,
            "TierName"              TEXT NOT NULL,
            "EnvironmentName"       TEXT NOT NULL,
            "ProductVersion"        TEXT NOT NULL,
            "Status"                TEXT NOT NULL,
            "IssuedAt"              TEXT NOT NULL,
            "ExpiresAt"             TEXT NULL,
            "Version"               INTEGER NOT NULL DEFAULT 1,
            "RevokedAt"             TEXT NULL,
            "RevokedByUserId"       INTEGER NULL,
            "RevokeReason"          TEXT NULL,
            "ResellerQuotaLedgerId" INTEGER NULL,
            "CreatedAt"             TEXT NULL,
            "UpdatedAt"             TEXT NULL
        )');
        DB::connection('shard')->table('Licenses')->truncate();
    }

    private function seedFeaturesCatalog(): void
    {
        DB::connection('root')->table('Features')->truncate();
        foreach (array_keys((array) config('lara.feature_registry', [])) as $key) {
            DB::connection('root')->table('Features')->insert([
                'FeatureKey' => (string) $key,
                'ValueType' => 'Boolean',
            ]);
        }
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
        $root->statement('CREATE TABLE IF NOT EXISTS "Features" (
            "FeatureId" INTEGER PRIMARY KEY AUTOINCREMENT,
            "FeatureKey" TEXT NOT NULL UNIQUE,
            "ValueType" TEXT NOT NULL
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
