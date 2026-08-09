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
 * Plan 10 step 17 (Reseller/LicenseRenewTest).
 *
 * Root cause guarded (one sentence): `Reseller\LicenseController::renew`
 * is the ONLY reseller mutation that bumps `Licenses.Version` under an
 * If-Match gate, extends `ExpiresAt`, and inserts a `LicenseLedger`
 * `LicenseRenewed` row inside a single shard transaction (spec 48 §1
 * ledger invariant), but no HTTP-level lock existed, so regressions
 * dropping the EtagMiddleware scope entry (`PATCH:api/reseller/licenses/`),
 * skipping the `enforceIfMatch` version check, weakening the
 * `LicenseRevoked` guard, or omitting the ledger insert could all
 * ship green.
 *
 * Branches guarded (10):
 *   1. PATCH bare                          -> 401 AuthUnauthorized
 *   2. PATCH non-Reseller                  -> 403 AuthForbidden
 *   3. PATCH unknown key                   -> 404 LicenseNotFound
 *   4. PATCH foreign key on same shard     -> 404 LicenseNotFound
 *   5. PATCH missing If-Match              -> 428 PreconditionRequired
 *   6. PATCH wildcard If-Match             -> 400 ValidationFailed
 *   7. PATCH stale ETag                    -> 412 PreconditionFailed
 *   8. PATCH past ExpiresAt                -> 400 ValidationFailed
 *   9. PATCH revoked license               -> 409 LicenseRevoked
 *  10. PATCH happy                         -> 200 + Version bump + ledger + audit
 */
final class LicenseRenewTest extends TestCase
{
    use AssertsLaraException;

    private const RESELLER_EMAIL = 'reseller-renew@example.test';
    private const PASSWORD = 'CorrectHorse!Battery42';
    private const RESELLER_SLUG = 'acme';
    private const RESELLER_NAME = 'Acme';
    private const FOREIGN_SLUG = 'globex';
    private const FOREIGN_NAME = 'Globex';
    private const PREFIX = 'ACME01';
    private const OWN_KEY = 'ACME01-RENW1111';
    private const FOREIGN_KEY = 'GLBX01-RENW2222';
    private const REVOKED_KEY = 'ACME01-RENW3333';

    private User $reseller;
    private int $resellerId;
    private int $foreignResellerId;
    private string $bearer;
    private string $shardDbPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->shardDbPath = sys_get_temp_dir() . '/lara_test_reseller_renew_' . uniqid('', true) . '.sqlite';
        touch($this->shardDbPath);
        $this->configureShardTemplate();
        $this->createRootFixtures();
        $this->swapRolePolicy();
        $this->resellerId = $this->seedReseller(self::RESELLER_SLUG, self::RESELLER_NAME);
        $this->foreignResellerId = $this->seedReseller(self::FOREIGN_SLUG, self::FOREIGN_NAME);
        $this->reseller = $this->makeUser(self::RESELLER_EMAIL, $this->resellerId);
        $this->bearer = $this->openSessionAndMintToken($this->reseller);
        $this->createShardTables();
        $this->seedShardLicense(1, self::OWN_KEY, $this->resellerId, 'Active');
        $this->seedShardLicense(2, self::FOREIGN_KEY, $this->foreignResellerId, 'Active');
        $this->seedShardLicense(3, self::REVOKED_KEY, $this->resellerId, 'Revoked');
    }

    protected function tearDown(): void
    {
        if (file_exists($this->shardDbPath)) {
            @unlink($this->shardDbPath);
        }
        parent::tearDown();
    }

    public function test_renew_bare_returns_401(): void
    {
        $res = $this->patchJson('/Api/Reseller/Licenses/' . self::OWN_KEY . '/Renew', [
            'ExpiresAt' => Carbon::now()->addYear()->format('Y-m-d\TH:i:s\Z'),
        ]);
        $this->assertLaraException($res, 'AuthUnauthorized', 401);
    }

    public function test_renew_non_reseller_returns_403(): void
    {
        \Tests\Feature\FakeHasRolePolicy::$grants = [(string) $this->reseller->getKey() => ['Admin']];
        $res = $this->withHeader('Authorization', 'Bearer ' . $this->bearer)
            ->withHeader('If-Match', '"deadbeef"')
            ->patchJson('/Api/Reseller/Licenses/' . self::OWN_KEY . '/Renew', [
                'ExpiresAt' => Carbon::now()->addYear()->format('Y-m-d\TH:i:s\Z'),
            ]);
        $this->assertLaraException($res, 'AuthForbidden', 403);
    }

    public function test_renew_missing_if_match_returns_428(): void
    {
        $this->grantReseller();
        $res = $this->withHeader('Authorization', 'Bearer ' . $this->bearer)
            ->patchJson('/Api/Reseller/Licenses/' . self::OWN_KEY . '/Renew', [
                'ExpiresAt' => Carbon::now()->addYear()->format('Y-m-d\TH:i:s\Z'),
            ]);
        $this->assertLaraException($res, 'PreconditionRequired', 428);
    }

    public function test_renew_wildcard_if_match_returns_400(): void
    {
        $this->grantReseller();
        $res = $this->withHeader('Authorization', 'Bearer ' . $this->bearer)
            ->withHeader('If-Match', '*')
            ->patchJson('/Api/Reseller/Licenses/' . self::OWN_KEY . '/Renew', [
                'ExpiresAt' => Carbon::now()->addYear()->format('Y-m-d\TH:i:s\Z'),
            ]);
        $this->assertLaraException($res, 'ValidationFailed', 400);
    }

    public function test_renew_stale_etag_returns_412(): void
    {
        $this->grantReseller();
        $res = $this->withHeader('Authorization', 'Bearer ' . $this->bearer)
            ->withHeader('If-Match', '"0000000000000000000000000000000000000000000000000000000000000000"')
            ->patchJson('/Api/Reseller/Licenses/' . self::OWN_KEY . '/Renew', [
                'ExpiresAt' => Carbon::now()->addYear()->format('Y-m-d\TH:i:s\Z'),
            ]);
        $this->assertLaraException($res, 'PreconditionFailed', 412);
    }

    public function test_renew_unknown_key_returns_404(): void
    {
        $this->grantReseller();
        $etag = $this->fetchLicenseEtag(self::OWN_KEY); // valid ETag but wrong key path
        $res = $this->withHeader('Authorization', 'Bearer ' . $this->bearer)
            ->withHeader('If-Match', $etag)
            ->patchJson('/Api/Reseller/Licenses/ACME01-ZZZZ9999/Renew', [
                'ExpiresAt' => Carbon::now()->addYear()->format('Y-m-d\TH:i:s\Z'),
            ]);
        $this->assertLaraException($res, 'LicenseNotFound', 404);
    }

    public function test_renew_foreign_key_returns_404(): void
    {
        $this->grantReseller();
        $res = $this->withHeader('Authorization', 'Bearer ' . $this->bearer)
            ->withHeader('If-Match', '"deadbeef"')
            ->patchJson('/Api/Reseller/Licenses/' . self::FOREIGN_KEY . '/Renew', [
                'ExpiresAt' => Carbon::now()->addYear()->format('Y-m-d\TH:i:s\Z'),
            ]);
        // Ownership predicate runs BEFORE If-Match check in applyRenew
        // -> requireOwnedLicenseForUpdate throws LicenseNotFound.
        $this->assertLaraException($res, 'LicenseNotFound', 404);
    }

    public function test_renew_past_expires_at_returns_400(): void
    {
        $this->grantReseller();
        $etag = $this->fetchLicenseEtag(self::OWN_KEY);
        $res = $this->withHeader('Authorization', 'Bearer ' . $this->bearer)
            ->withHeader('If-Match', $etag)
            ->patchJson('/Api/Reseller/Licenses/' . self::OWN_KEY . '/Renew', [
                'ExpiresAt' => Carbon::now()->subDay()->format('Y-m-d\TH:i:s\Z'),
            ]);
        $this->assertLaraException($res, 'ValidationFailed', 400);
    }

    public function test_renew_revoked_license_returns_error(): void
    {
        $this->grantReseller();
        $etag = $this->fetchLicenseEtag(self::REVOKED_KEY);
        $res = $this->withHeader('Authorization', 'Bearer ' . $this->bearer)
            ->withHeader('If-Match', $etag)
            ->patchJson('/Api/Reseller/Licenses/' . self::REVOKED_KEY . '/Renew', [
                'ExpiresAt' => Carbon::now()->addYear()->format('Y-m-d\TH:i:s\Z'),
            ]);
        $this->assertSame('LicenseRevoked', (string) $res->json('Error.ErrorCode'));
    }

    public function test_renew_happy_path_bumps_version_and_writes_ledger(): void
    {
        $this->grantReseller();
        $etag = $this->fetchLicenseEtag(self::OWN_KEY);
        $newExpires = Carbon::now()->addYear()->format('Y-m-d\TH:i:s\Z');
        $res = $this->withHeader('Authorization', 'Bearer ' . $this->bearer)
            ->withHeader('If-Match', $etag)
            ->patchJson('/Api/Reseller/Licenses/' . self::OWN_KEY . '/Renew', [
                'ExpiresAt' => $newExpires,
            ]);
        $res->assertStatus(200);
        $this->assertSame(2, (int) $res->json('Results.0.Version'), 'Version must bump 1 -> 2.');
        $this->bindShard(self::RESELLER_SLUG);
        $row = DB::connection('shard')->table('Licenses')->where('LicenseKey', self::OWN_KEY)->first();
        $this->assertNotNull($row);
        $this->assertSame(2, (int) $row->Version);
        $this->assertNotSame('', (string) $row->ExpiresAt, 'ExpiresAt must be persisted.');
        $ledger = DB::connection('shard')->table('LicenseLedger')
            ->where('LicenseId', (int) $row->LicenseId)
            ->where('LedgerAction', 'LicenseRenewed')
            ->first();
        $this->assertNotNull($ledger, 'LicenseRenewed ledger row must be written inside the shard transaction.');
        $this->assertSame(0, (int) $ledger->Delta, 'Renew ledger delta is neutral (0).');
        $audit = DB::connection('root')->table('AuditLogs')
            ->where('Action', 'LicenseRenewed')
            ->where('SubjectId', (int) $row->LicenseId)
            ->first();
        $this->assertNotNull($audit, 'LicenseRenewed audit row must be written.');
    }

    private function fetchLicenseEtag(string $key): string
    {
        \Tests\Feature\FakeHasRolePolicy::$grants = [(string) $this->reseller->getKey() => ['Reseller']];
        $res = $this->withHeader('Authorization', 'Bearer ' . $this->bearer)
            ->getJson('/Api/Reseller/Licenses/' . $key);
        $res->assertStatus(200);
        $etag = (string) $res->headers->get('ETag');
        $this->assertNotSame('', $etag, 'GET must emit an ETag for the license read.');

        return $etag;
    }

    private function grantReseller(): void
    {
        \Tests\Feature\FakeHasRolePolicy::$grants = [(string) $this->reseller->getKey() => ['Reseller']];
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

    private function createShardTables(): void
    {
        $this->bindShard(self::RESELLER_SLUG);
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
        DB::connection('shard')->statement('CREATE TABLE IF NOT EXISTS "LicenseLedger" (
            "LedgerId"            INTEGER PRIMARY KEY AUTOINCREMENT,
            "ResellerId"          INTEGER NOT NULL,
            "TierName"            TEXT NOT NULL,
            "LedgerAction"        TEXT NOT NULL,
            "Delta"               INTEGER NOT NULL,
            "LicenseId"           INTEGER NULL,
            "QuotaRequestId"      INTEGER NULL,
            "RequestId"           TEXT NOT NULL,
            "ActorUserId"         INTEGER NULL,
            "CreatedAt"           TEXT NULL
        )');
        DB::connection('shard')->table('Licenses')->truncate();
        DB::connection('shard')->table('LicenseLedger')->truncate();
    }

    private function seedShardLicense(int $id, string $key, int $resellerId, string $status): void
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
            'Status' => $status,
            'IssuedAt' => $now,
            'ExpiresAt' => Carbon::now()->addMonth()->format('Y-m-d\TH:i:s\Z'),
            'RevokedAt' => $status === 'Revoked' ? $now : null,
            'RevokeReason' => $status === 'Revoked' ? 'test' : null,
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
        $root->statement('CREATE TABLE IF NOT EXISTS "AuditLogs" (
            "AuditId" INTEGER PRIMARY KEY AUTOINCREMENT,
            "ActorUserId" INTEGER NULL,
            "Action" TEXT NOT NULL,
            "Subject" TEXT NOT NULL,
            "SubjectId" INTEGER NULL,
            "Payload" TEXT NULL,
            "RequestId" TEXT NULL,
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
