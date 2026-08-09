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
 * Plan 10 step 15 (Admin/* row: Admin/QuotaRequestsTest).
 *
 * Root cause guarded (one sentence): `Admin\QuotaRequestController`
 * drives the ONLY state machine that flips a shard `QuotaRequests`
 * row from Pending -> Approved/Denied inside a single transaction
 * (row UPDATE + `Quotas.LicensesGranted` upsert + `LicenseLedger`
 * insert), but no HTTP-level lock existed for
 * `GET|POST /Api/Admin/QuotaRequests[/{RequestId}/Approve|Deny]`,
 * so regressions in the role gate, `ResellerSlug` query
 * requirement, `QuotaRequestApproveRequest`/`QuotaRequestDenyRequest`
 * validation, the `requireOwnedPendingForUpdate` guard (Pending-only
 * transition), the three-way transactional write, or the paired
 * `QuotaRequestApproved`/`QuotaRequestDenied` audit rows could all
 * ship green.
 *
 * Branches guarded (10):
 *   1.  POST Approve bare              -> 401 AuthUnauthorized
 *   2.  POST Approve non-Admin         -> 403 AuthForbidden
 *   3.  POST Approve missing slug      -> 400 ValidationFailed
 *   4.  POST Approve unknown reseller  -> 404 ResellerNotFound
 *   5.  POST Approve bad ApprovedDelta -> 400 ValidationFailed
 *   6.  POST Approve unknown request   -> 404 ResourceRoleNotAssigned
 *   7.  POST Approve happy             -> 200 + Status=Approved,
 *                                          Quotas row (+delta),
 *                                          LicenseLedger row
 *                                          (LedgerAction=QuotaAdjusted),
 *                                          QuotaRequestApproved audit
 *   8.  POST Approve on Approved row   -> 409 IdempotencyConflict
 *   9.  POST Deny happy                -> 200 + Status=Denied +
 *                                          QuotaRequestDenied audit
 *   10. GET index                      -> 200 filtered by ResellerSlug
 */
final class QuotaRequestsTest extends TestCase
{
    use AssertsLaraException;

    private const ADMIN_EMAIL = 'admin-quota@example.test';
    private const PASSWORD = 'CorrectHorse!Battery42';
    private const RESELLER_SLUG = 'acme';
    private const RESELLER_NAME = 'Acme';

    private User $admin;
    private int $resellerId;
    private string $adminBearer;
    private string $shardDbPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->shardDbPath = sys_get_temp_dir() . '/lara_test_quota_shard_' . uniqid('', true) . '.sqlite';
        touch($this->shardDbPath);
        $this->configureShardTemplate();
        $this->createRootFixtures();
        $this->createShardTables();
        $this->swapRolePolicy();
        $this->admin = $this->makeUser(self::ADMIN_EMAIL);
        $this->adminBearer = $this->openSessionAndMintToken($this->admin);
        $this->resellerId = $this->seedReseller(self::RESELLER_SLUG, self::RESELLER_NAME);
    }

    protected function tearDown(): void
    {
        if (file_exists($this->shardDbPath)) {
            @unlink($this->shardDbPath);
        }
        parent::tearDown();
    }

    public function test_approve_bare_returns_401(): void
    {
        $res = $this->postJson('/Api/Admin/QuotaRequests/1/Approve?ResellerSlug=' . self::RESELLER_SLUG, ['ApprovedDelta' => 5]);
        $this->assertLaraException($res, 'AuthUnauthorized', 401);
    }

    public function test_approve_non_admin_returns_403(): void
    {
        \Tests\Feature\FakeHasRolePolicy::$grants = [(string) $this->admin->getKey() => ['Reseller']];
        $res = $this->withHeader('Authorization', 'Bearer ' . $this->adminBearer)
            ->postJson('/Api/Admin/QuotaRequests/1/Approve?ResellerSlug=' . self::RESELLER_SLUG, ['ApprovedDelta' => 5]);
        $this->assertLaraException($res, 'AuthForbidden', 403);
    }

    public function test_approve_missing_slug_returns_400(): void
    {
        \Tests\Feature\FakeHasRolePolicy::$grants = [(string) $this->admin->getKey() => ['Admin']];
        $res = $this->withHeader('Authorization', 'Bearer ' . $this->adminBearer)
            ->postJson('/Api/Admin/QuotaRequests/1/Approve', ['ApprovedDelta' => 5]);
        $this->assertLaraException($res, 'ValidationFailed', 400);
    }

    public function test_approve_unknown_reseller_returns_404(): void
    {
        \Tests\Feature\FakeHasRolePolicy::$grants = [(string) $this->admin->getKey() => ['Admin']];
        $res = $this->withHeader('Authorization', 'Bearer ' . $this->adminBearer)
            ->postJson('/Api/Admin/QuotaRequests/1/Approve?ResellerSlug=nope', ['ApprovedDelta' => 5]);
        $this->assertLaraException($res, 'ResellerNotFound', 404);
    }

    public function test_approve_bad_delta_returns_validation_failed(): void
    {
        \Tests\Feature\FakeHasRolePolicy::$grants = [(string) $this->admin->getKey() => ['Admin']];
        $res = $this->withHeader('Authorization', 'Bearer ' . $this->adminBearer)
            ->postJson('/Api/Admin/QuotaRequests/1/Approve?ResellerSlug=' . self::RESELLER_SLUG, ['ApprovedDelta' => 0]);
        $this->assertLaraException($res, 'ValidationFailed', 400);
    }

    public function test_approve_unknown_request_returns_not_found(): void
    {
        \Tests\Feature\FakeHasRolePolicy::$grants = [(string) $this->admin->getKey() => ['Admin']];
        $res = $this->withHeader('Authorization', 'Bearer ' . $this->adminBearer)
            ->postJson('/Api/Admin/QuotaRequests/9999/Approve?ResellerSlug=' . self::RESELLER_SLUG, ['ApprovedDelta' => 5]);
        $this->assertLaraException($res, 'ResourceRoleNotAssigned', 404);
    }

    public function test_approve_happy_path_transacts_all_three_writes(): void
    {
        Log::spy();
        \Tests\Feature\FakeHasRolePolicy::$grants = [(string) $this->admin->getKey() => ['Admin']];
        $qrId = $this->seedPendingQuotaRequest($this->resellerId, 7, 1, 10); // Key, Tier1, delta 10

        $res = $this->withHeader('Authorization', 'Bearer ' . $this->adminBearer)
            ->postJson('/Api/Admin/QuotaRequests/' . $qrId . '/Approve?ResellerSlug=' . self::RESELLER_SLUG, [
                'ApprovedDelta' => 10,
            ]);
        $res->assertStatus(200);
        $this->assertSame('Approved', (string) $res->json('Results.0.StatusName'));

        $this->bindShard(self::RESELLER_SLUG);
        $row = DB::connection('shard')->table('QuotaRequests')->where('QuotaRequestId', $qrId)->first();
        $this->assertSame(2, (int) $row->Status);
        $this->assertSame(10, (int) $row->ApprovedDelta);
        $this->assertSame((int) $this->admin->getKey(), (int) $row->DecidedByUserId);

        $quota = DB::connection('shard')->table('Quotas')
            ->where('ResellerId', $this->resellerId)
            ->where('LicenseCategoryId', 7)
            ->where('LicenseTierId', 1)
            ->first();
        $this->assertNotNull($quota, 'Quotas row must be upserted.');
        $this->assertSame(10, (int) $quota->LicensesGranted);

        $ledger = DB::connection('shard')->table('LicenseLedger')
            ->where('QuotaRequestId', $qrId)->first();
        $this->assertNotNull($ledger, 'LicenseLedger row must be inserted inside the transaction.');
        $this->assertSame('QuotaAdjusted', (string) $ledger->LedgerAction);
        $this->assertSame(10, (int) $ledger->Delta);
        $this->assertNull($ledger->LicenseId, 'Adjust ledger rows have no LicenseId (spec 42 v1.1.0).');
        $this->assertSame('Tier1', (string) $ledger->TierName);

        $audit = DB::connection('root')->table('AuditLogs')
            ->where('Action', 'QuotaRequestApproved')
            ->where('TargetType', 'QuotaRequests')
            ->where('TargetId', (string) $qrId)
            ->first();
        $this->assertNotNull($audit, 'AuditWriter must record QuotaRequestApproved.');
    }

    public function test_approve_on_already_approved_returns_idempotency_conflict(): void
    {
        \Tests\Feature\FakeHasRolePolicy::$grants = [(string) $this->admin->getKey() => ['Admin']];
        $qrId = $this->seedPendingQuotaRequest($this->resellerId, 7, 1, 5);
        // Flip to Approved out-of-band.
        $this->bindShard(self::RESELLER_SLUG);
        DB::connection('shard')->table('QuotaRequests')->where('QuotaRequestId', $qrId)->update(['Status' => 2]);

        $res = $this->withHeader('Authorization', 'Bearer ' . $this->adminBearer)
            ->postJson('/Api/Admin/QuotaRequests/' . $qrId . '/Approve?ResellerSlug=' . self::RESELLER_SLUG, [
                'ApprovedDelta' => 5,
            ]);
        $this->assertLaraException($res, 'IdempotencyConflict', 409);
    }

    public function test_deny_happy_path_writes_audit(): void
    {
        Log::spy();
        \Tests\Feature\FakeHasRolePolicy::$grants = [(string) $this->admin->getKey() => ['Admin']];
        $qrId = $this->seedPendingQuotaRequest($this->resellerId, 7, 1, 3);

        $res = $this->withHeader('Authorization', 'Bearer ' . $this->adminBearer)
            ->postJson('/Api/Admin/QuotaRequests/' . $qrId . '/Deny?ResellerSlug=' . self::RESELLER_SLUG, [
                'DenialReason' => 'Insufficient business justification for the requested delta.',
            ]);
        $res->assertStatus(200);
        $this->assertSame('Denied', (string) $res->json('Results.0.StatusName'));

        $this->bindShard(self::RESELLER_SLUG);
        $row = DB::connection('shard')->table('QuotaRequests')->where('QuotaRequestId', $qrId)->first();
        $this->assertSame(3, (int) $row->Status);
        $this->assertNotNull($row->DenialReason);
        $this->assertSame((int) $this->admin->getKey(), (int) $row->DecidedByUserId);

        $audit = DB::connection('root')->table('AuditLogs')
            ->where('Action', 'QuotaRequestDenied')
            ->where('TargetType', 'QuotaRequests')
            ->where('TargetId', (string) $qrId)
            ->first();
        $this->assertNotNull($audit, 'AuditWriter must record QuotaRequestDenied.');
    }

    public function test_index_returns_rows_filtered_by_reseller_slug(): void
    {
        \Tests\Feature\FakeHasRolePolicy::$grants = [(string) $this->admin->getKey() => ['Admin']];
        $qrId = $this->seedPendingQuotaRequest($this->resellerId, 7, 1, 2);

        $res = $this->withHeader('Authorization', 'Bearer ' . $this->adminBearer)
            ->getJson('/Api/Admin/QuotaRequests?ResellerSlug=' . self::RESELLER_SLUG);
        $res->assertStatus(200);
        $results = (array) $res->json('Results');
        $this->assertNotEmpty($results);
        $ids = array_map(static fn (array $r): int => (int) ($r['QuotaRequestId'] ?? $r['RequestId'] ?? 0), $results);
        $this->assertContains($qrId, $ids);
    }

    // ---------- helpers ----------

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
        DB::connection('shard')->statement('CREATE TABLE IF NOT EXISTS "QuotaRequests" (
            "QuotaRequestId"      INTEGER PRIMARY KEY AUTOINCREMENT,
            "ResellerId"          INTEGER NOT NULL,
            "LicenseCategoryId"   INTEGER NOT NULL,
            "LicenseTierId"       INTEGER NOT NULL,
            "RequestedDelta"      INTEGER NOT NULL,
            "ApprovedDelta"       INTEGER NULL,
            "Status"              INTEGER NOT NULL,
            "Justification"       TEXT NULL,
            "DenialReason"        TEXT NULL,
            "SubmittedByUserId"   INTEGER NOT NULL,
            "DecidedByUserId"     INTEGER NULL,
            "SubmittedAt"         TEXT NOT NULL,
            "DecidedAt"           TEXT NULL,
            "RequestId"           TEXT NOT NULL,
            "IdempotencyKey"      TEXT NULL
        )');
        DB::connection('shard')->statement('CREATE TABLE IF NOT EXISTS "Quotas" (
            "QuotaId"             INTEGER PRIMARY KEY AUTOINCREMENT,
            "ResellerId"          INTEGER NOT NULL,
            "LicenseCategoryId"   INTEGER NOT NULL,
            "LicenseTierId"       INTEGER NOT NULL,
            "LicensesGranted"     INTEGER NOT NULL,
            "LicensesConsumed"    INTEGER NOT NULL DEFAULT 0,
            "PeriodStart"         TEXT NOT NULL,
            "PeriodEnd"           TEXT NULL,
            "CreatedAt"           TEXT NULL,
            "UpdatedAt"           TEXT NULL
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
            "ActorUserId"         INTEGER NOT NULL,
            "CreatedAt"           TEXT NULL
        )');
        DB::connection('shard')->table('QuotaRequests')->truncate();
        DB::connection('shard')->table('Quotas')->truncate();
        DB::connection('shard')->table('LicenseLedger')->truncate();
    }

    private function seedPendingQuotaRequest(int $resellerId, int $categoryId, int $tierId, int $delta): int
    {
        $this->bindShard(self::RESELLER_SLUG);

        return (int) DB::connection('shard')->table('QuotaRequests')->insertGetId([
            'ResellerId' => $resellerId,
            'LicenseCategoryId' => $categoryId,
            'LicenseTierId' => $tierId,
            'RequestedDelta' => $delta,
            'ApprovedDelta' => null,
            'Status' => 1,
            'Justification' => 'Test seed row for admin decision flow.',
            'DenialReason' => null,
            'SubmittedByUserId' => (int) $this->admin->getKey(),
            'DecidedByUserId' => null,
            'SubmittedAt' => Carbon::now()->toDateTimeString(),
            'DecidedAt' => null,
            'RequestId' => Uuid::uuid4()->toString(),
            'IdempotencyKey' => null,
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
