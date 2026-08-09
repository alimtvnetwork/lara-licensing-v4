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
 * Plan 10 step 16 (Reseller/QuotaRequestSubmitTest).
 *
 * Root cause guarded (one sentence): `Reseller\QuotaRequestController`
 * is the ONLY code path that lets a reseller submit, list, and cancel
 * its own `Pending` `QuotaRequests` rows on its own shard under the
 * `auth:sanctum` -> `session.active` -> `require.role:Reseller` ->
 * `ShardBindingMiddleware` chain, but no HTTP-level lock existed, so
 * regressions dropping the role gate, skipping the Idempotency-Key
 * requirement, letting a non-owner cancel, or approving/denying via
 * the Reseller surface (Admin-only per spec 42) could all ship green.
 *
 * Branches guarded (11):
 *   1. GET  index bare                      -> 401 AuthUnauthorized
 *   2. GET  index non-Reseller              -> 403 AuthForbidden
 *   3. GET  index no tenant                 -> 403 AuthForbidden (shard bind)
 *   4. GET  index happy                     -> 200 own rows only
 *   5. POST store missing Idempotency-Key   -> 4xx IdempotencyKeyRequired
 *   6. POST store bad closed-set category   -> 400 ValidationFailed
 *   7. POST store validation min delta      -> 400 ValidationFailed
 *   8. POST store happy                     -> 201 Status=Pending + audit
 *   9. POST store idempotent replay         -> 200 same row (no dup)
 *  10. POST cancel non-owner                -> 403 AuthForbidden
 *  11. POST cancel happy                    -> 200 Status=Cancelled + audit
 */
final class QuotaRequestSubmitTest extends TestCase
{
    use AssertsLaraException;

    private const PRIMARY_EMAIL = 'reseller-qr@example.test';
    private const OTHER_EMAIL = 'reseller-qr-other@example.test';
    private const PASSWORD = 'CorrectHorse!Battery42';
    private const RESELLER_SLUG = 'acme';
    private const RESELLER_NAME = 'Acme';
    private const IDEM_A = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'; // 32 chars
    private const IDEM_B = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    private User $reseller;
    private User $other;
    private int $resellerId;
    private string $bearer;
    private string $otherBearer;
    private string $shardDbPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->shardDbPath = sys_get_temp_dir() . '/lara_test_reseller_qr_' . uniqid('', true) . '.sqlite';
        touch($this->shardDbPath);
        $this->configureShardTemplate();
        $this->createRootFixtures();
        $this->swapRolePolicy();
        $this->resellerId = $this->seedReseller(self::RESELLER_SLUG, self::RESELLER_NAME);
        $this->reseller = $this->makeUser(self::PRIMARY_EMAIL, $this->resellerId);
        $this->other = $this->makeUser(self::OTHER_EMAIL, $this->resellerId);
        $this->bearer = $this->openSessionAndMintToken($this->reseller);
        $this->otherBearer = $this->openSessionAndMintToken($this->other);
        $this->createShardTables();
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
        $res = $this->getJson('/Api/Reseller/QuotaRequests');
        $this->assertLaraException($res, 'AuthUnauthorized', 401);
    }

    public function test_index_non_reseller_returns_403(): void
    {
        \Tests\Feature\FakeHasRolePolicy::$grants = [(string) $this->reseller->getKey() => ['Admin']];
        $res = $this->withHeader('Authorization', 'Bearer ' . $this->bearer)
            ->getJson('/Api/Reseller/QuotaRequests');
        $this->assertLaraException($res, 'AuthForbidden', 403);
    }

    public function test_index_user_without_tenant_returns_403(): void
    {
        $rootless = $this->makeUser('rootless-qr@example.test', null);
        $bearer = $this->openSessionAndMintToken($rootless);
        \Tests\Feature\FakeHasRolePolicy::$grants = [(string) $rootless->getKey() => ['Reseller']];
        $res = $this->withHeader('Authorization', 'Bearer ' . $bearer)
            ->getJson('/Api/Reseller/QuotaRequests');
        $this->assertLaraException($res, 'AuthForbidden', 403);
    }

    public function test_index_happy_path_lists_only_own_rows(): void
    {
        $this->grantReseller();
        $mine = $this->seedPending((int) $this->reseller->getKey(), 5);
        $foreign = $this->seedPendingForResellerId(9999, (int) $this->reseller->getKey(), 7);
        $res = $this->withHeader('Authorization', 'Bearer ' . $this->bearer)
            ->getJson('/Api/Reseller/QuotaRequests');
        $res->assertStatus(200);
        $ids = array_map(static fn (array $r): int => (int) ($r['QuotaRequestId'] ?? 0), (array) $res->json('Results'));
        $this->assertContains($mine, $ids);
        $this->assertNotContains($foreign, $ids, 'Foreign-tenant QuotaRequests row must not leak into caller shard scope.');
    }

    public function test_store_missing_idempotency_key_returns_error(): void
    {
        $this->grantReseller();
        $res = $this->withHeader('Authorization', 'Bearer ' . $this->bearer)
            ->postJson('/Api/Reseller/QuotaRequests', [
                'LicenseCategoryId' => 1,
                'LicenseTierId' => 1,
                'RequestedDelta' => 5,
                'Justification' => 'Need more seats for Q3.',
            ]);
        $this->assertSame('IdempotencyKeyRequired', (string) $res->json('Error.ErrorCode'));
    }

    public function test_store_unknown_closed_set_category_returns_400(): void
    {
        $this->grantReseller();
        $res = $this->withHeader('Authorization', 'Bearer ' . $this->bearer)
            ->withHeader('Idempotency-Key', self::IDEM_A)
            ->postJson('/Api/Reseller/QuotaRequests', [
                'LicenseCategoryId' => 99,
                'LicenseTierId' => 1,
                'RequestedDelta' => 5,
                'Justification' => 'Need more seats for Q3.',
            ]);
        $this->assertLaraException($res, 'ValidationFailed', 400);
    }

    public function test_store_validation_zero_delta_returns_400(): void
    {
        $this->grantReseller();
        $res = $this->withHeader('Authorization', 'Bearer ' . $this->bearer)
            ->withHeader('Idempotency-Key', self::IDEM_A)
            ->postJson('/Api/Reseller/QuotaRequests', [
                'LicenseCategoryId' => 1,
                'LicenseTierId' => 1,
                'RequestedDelta' => 0,
                'Justification' => 'Need more seats.',
            ]);
        $this->assertLaraException($res, 'ValidationFailed', 400);
    }

    public function test_store_happy_path_returns_201_and_writes_pending(): void
    {
        $this->grantReseller();
        $res = $this->withHeader('Authorization', 'Bearer ' . $this->bearer)
            ->withHeader('Idempotency-Key', self::IDEM_A)
            ->postJson('/Api/Reseller/QuotaRequests', [
                'LicenseCategoryId' => 1,
                'LicenseTierId' => 1,
                'RequestedDelta' => 5,
                'Justification' => 'Need more seats for Q3 launch.',
            ]);
        $res->assertStatus(201);
        $qrId = (int) $res->json('Results.0.QuotaRequestId');
        $this->assertGreaterThan(0, $qrId);
        $this->bindShard(self::RESELLER_SLUG);
        $row = DB::connection('shard')->table('QuotaRequests')->where('QuotaRequestId', $qrId)->first();
        $this->assertNotNull($row);
        $this->assertSame(1, (int) $row->Status, 'New row must persist Status=Pending.');
        $this->assertSame($this->resellerId, (int) $row->ResellerId);
        $this->assertSame((int) $this->reseller->getKey(), (int) $row->SubmittedByUserId);
        $audit = DB::connection('root')->table('AuditLogs')
            ->where('Action', 'QuotaRequestSubmitted')
            ->where('SubjectId', $qrId)
            ->first();
        $this->assertNotNull($audit, 'QuotaRequestSubmitted audit row must be written.');
    }

    public function test_store_idempotent_replay_returns_existing_row(): void
    {
        $this->grantReseller();
        $first = $this->withHeader('Authorization', 'Bearer ' . $this->bearer)
            ->withHeader('Idempotency-Key', self::IDEM_B)
            ->postJson('/Api/Reseller/QuotaRequests', [
                'LicenseCategoryId' => 1,
                'LicenseTierId' => 1,
                'RequestedDelta' => 5,
                'Justification' => 'Need more seats for Q3 launch.',
            ]);
        $first->assertStatus(201);
        $firstId = (int) $first->json('Results.0.QuotaRequestId');
        $second = $this->withHeader('Authorization', 'Bearer ' . $this->bearer)
            ->withHeader('Idempotency-Key', self::IDEM_B)
            ->postJson('/Api/Reseller/QuotaRequests', [
                'LicenseCategoryId' => 1,
                'LicenseTierId' => 1,
                'RequestedDelta' => 5,
                'Justification' => 'Need more seats for Q3 launch.',
            ]);
        $second->assertStatus(200);
        $this->assertSame($firstId, (int) $second->json('Results.0.QuotaRequestId'), 'Replay must return the same row.');
        $this->bindShard(self::RESELLER_SLUG);
        $count = DB::connection('shard')->table('QuotaRequests')
            ->where('IdempotencyKey', self::IDEM_B)->count();
        $this->assertSame(1, (int) $count, 'Idempotent replay must not duplicate the row.');
    }

    public function test_cancel_non_owner_returns_403(): void
    {
        // Owner is the primary reseller; the OTHER user (same tenant, different UserId)
        // attempts to cancel and must be rejected with AuthForbidden.
        $qrId = $this->seedPending((int) $this->reseller->getKey(), 5);
        \Tests\Feature\FakeHasRolePolicy::$grants = [(string) $this->other->getKey() => ['Reseller']];
        $res = $this->withHeader('Authorization', 'Bearer ' . $this->otherBearer)
            ->postJson('/Api/Reseller/QuotaRequests/' . $qrId . '/Cancel');
        $this->assertLaraException($res, 'AuthForbidden', 403);
    }

    public function test_cancel_happy_path_transitions_to_cancelled(): void
    {
        $this->grantReseller();
        $qrId = $this->seedPending((int) $this->reseller->getKey(), 5);
        $res = $this->withHeader('Authorization', 'Bearer ' . $this->bearer)
            ->postJson('/Api/Reseller/QuotaRequests/' . $qrId . '/Cancel');
        $res->assertStatus(200);
        $this->bindShard(self::RESELLER_SLUG);
        $row = DB::connection('shard')->table('QuotaRequests')->where('QuotaRequestId', $qrId)->first();
        $this->assertNotNull($row);
        $this->assertSame(4, (int) $row->Status, 'Status must transition Pending -> Cancelled.');
        $this->assertSame((int) $this->reseller->getKey(), (int) $row->DecidedByUserId);
        $audit = DB::connection('root')->table('AuditLogs')
            ->where('Action', 'QuotaRequestCanceled')
            ->where('SubjectId', $qrId)
            ->first();
        $this->assertNotNull($audit, 'QuotaRequestCanceled audit row must be written.');
    }

    private function grantReseller(): void
    {
        \Tests\Feature\FakeHasRolePolicy::$grants = [
            (string) $this->reseller->getKey() => ['Reseller'],
            (string) $this->other->getKey() => ['Reseller'],
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
        DB::connection('shard')->table('QuotaRequests')->truncate();
    }

    private function seedPending(int $submittedByUserId, int $delta): int
    {
        return $this->seedPendingForResellerId($this->resellerId, $submittedByUserId, $delta);
    }

    private function seedPendingForResellerId(int $resellerId, int $submittedByUserId, int $delta): int
    {
        $this->bindShard(self::RESELLER_SLUG);
        $now = Carbon::now()->format('Y-m-d\TH:i:s\Z');

        return (int) DB::connection('shard')->table('QuotaRequests')->insertGetId([
            'ResellerId' => $resellerId,
            'LicenseCategoryId' => 1,
            'LicenseTierId' => 1,
            'RequestedDelta' => $delta,
            'ApprovedDelta' => null,
            'Status' => 1,
            'Justification' => 'Seed row.',
            'DenialReason' => null,
            'SubmittedByUserId' => $submittedByUserId,
            'DecidedByUserId' => null,
            'SubmittedAt' => $now,
            'DecidedAt' => null,
            'RequestId' => Uuid::uuid4()->toString(),
            'IdempotencyKey' => bin2hex(random_bytes(16)),
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
