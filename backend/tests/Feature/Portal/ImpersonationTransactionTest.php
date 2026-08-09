<?php

declare(strict_types=1);

namespace Tests\Feature\Portal;

use App\Db\ShardResolver;
use App\Models\AuthSession;
use App\Models\ImpersonationIndex;
use App\Models\Reseller;
use App\Models\User;
use App\Services\AuthSessionService;
use App\Services\ImpersonationService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\AssertsLaraException;
use Tests\TestCase;

/**
 * Plan 06 step 56. Feature test ImpersonationTransactionTest locking 
 * session begin/end pair + audit rows (spec 47).
 *
 * Locked behaviors:
 *  - AC-IMP-001/002: Admin can begin impersonation with valid reason.
 *  - AC-IMP-004/011: One active impersonation session per operator (Root UK gate).
 *  - AC-IMP-010: Shard-scoped target has AuthSession row in shard, not Root.
 *  - AC-IMP-005: Operator can end their own session.
 *  - AC-IMP-006: Admin can force-end any active session.
 *  - AC-IMP-007: AuditLogs.PayloadJson carries ImpersonatorUserId.
 */
final class ImpersonationTransactionTest extends TestCase
{
    use AssertsLaraException;

    private const ROOT = 'root';
    private const SHARD = 'shard';
    private const SLUG = 'acme-shard';

    private User $admin;
    private User $rootTarget;
    private User $shardTarget;
    private Reseller $reseller;
    private string $shardDbPath;

    protected function setUp(): void
    {
        parent::setUp();

        // 1. Setup Root DB (SQLite Memory)
        config()->set('database.connections.root', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]);

        // 2. Setup Shard Template
        $this->shardDbPath = sys_get_temp_dir() . '/lara_test_imp_shard_' . uniqid('', true) . '.sqlite';
        touch($this->shardDbPath);
        config()->set('database.connections.shard_template', [
            'driver' => 'sqlite',
            'database' => $this->shardDbPath,
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]);

        // 3. Migrate Root
        $this->migrateRoot();

        // 4. Seed bootstrap identities
        $this->seedIdentities();
    }

    protected function tearDown(): void
    {
        if (file_exists($this->shardDbPath)) {
            unlink($this->shardDbPath);
        }
        parent::tearDown();
    }

    /**
     * @test AC-IMP-001, AC-IMP-004, AC-IMP-010, AC-IMP-007
     */
    public function it_begins_impersonation_transactionally_across_root_and_shard(): void
    {
        // 1. Create a parent session for the Admin
        $parent = $this->openNormalSession($this->admin);
        $requestId = 'req-imp-001';

        // 2. Begin impersonation of shard target
        /** @var ImpersonationService $svc */
        $svc = app(ImpersonationService::class);
        
        $payload = $svc->begin(
            $this->admin,
            $parent,
            $this->shardTarget,
            'Testing shard isolation and audit',
            $requestId
        );

        $sessionId = $payload['SessionId'];
        $this->assertNotEmpty($sessionId);

        // 3. Verify Root ImpersonationIndex
        $this->assertDatabaseHas('ImpersonationIndex', [
            'SessionId' => $sessionId,
            'ImpersonatorUserId' => $this->admin->UserId,
            'TargetUserId' => $this->shardTarget->UserId,
            'TargetResellerId' => $this->reseller->ResellerId,
            'EndedAt' => null,
        ], self::ROOT);

        // 4. Verify Shard AuthSessions (AC-IMP-010)
        // ShardResolver was bound during begin()
        $this->assertDatabaseHas('AuthSessions', [
            'SessionId' => $sessionId,
            'UserId' => $this->shardTarget->UserId,
            'Kind' => AuthSession::KIND_IMPERSONATION,
            'ImpersonatorUserId' => $this->admin->UserId,
            'ParentSessionId' => $parent->SessionId,
            'EndedAt' => null,
        ], self::SHARD);

        // Verify Root AuthSessions DOES NOT have it
        $this->assertDatabaseMissing('AuthSessions', [
            'SessionId' => $sessionId,
        ], self::ROOT);

        // 5. Verify Audit Log (AC-IMP-007)
        $audit = DB::connection(self::ROOT)->table('AuditLogs')
            ->where('RequestId', $requestId)
            ->where('Action', 'ImpersonationStarted')
            ->first();
        
        $this->assertNotNull($audit);
        $payloadJson = json_decode($audit->PayloadJson, true);
        $this->assertEquals($this->admin->UserId, $payloadJson['ImpersonatorUserId']);
        $this->assertEquals($this->shardTarget->UserId, $payloadJson['TargetUserId']);
        $this->assertEquals('Testing shard isolation and audit', $payloadJson['Reason']);

        // 6. Verify AC-IMP-004 (Unique active gate)
        $this->assertLaraException('AuthzPermissionDenied', function() use ($svc, $parent, $requestId) {
             // ImpersonationService uses a unique index, so the SQLSTATE unique violation 
             // is caught or throws QueryException. The service doesn't have a check, it relies on DB.
             // But it should throw because of the Root tx rollback.
             $svc->begin($this->admin, $parent, $this->rootTarget, 'Second attempt', $requestId);
        });
    }

    /**
     * @test AC-IMP-005
     */
    public function it_ends_impersonation_transactionally(): void
    {
        $parent = $this->openNormalSession($this->admin);
        /** @var ImpersonationService $svc */
        $svc = app(ImpersonationService::class);
        $payload = $svc->begin($this->admin, $parent, $this->rootTarget, 'Cleanup test', 'req-002');
        $sessionId = $payload['SessionId'];
        
        $session = AuthSession::query()->where('SessionId', $sessionId)->first();
        
        $svc->end($this->admin, $session, 'OperatorEnded', 'req-003');

        // Verify index closed
        $this->assertDatabaseHas('ImpersonationIndex', [
            'SessionId' => $sessionId,
            'EndReason' => 'OperatorEnded',
        ], self::ROOT);
        $this->assertNotNull(ImpersonationIndex::find($sessionId)->EndedAt);

        // Verify session closed
        $this->assertDatabaseHas('AuthSessions', [
            'SessionId' => $sessionId,
            'RevokeReason' => 'OperatorEnded',
        ], self::ROOT);
        
        // Verify audit
        $this->assertDatabaseHas('AuditLogs', [
            'RequestId' => 'req-003',
            'Action' => 'ImpersonationEnded',
        ], self::ROOT);
    }

    /**
     * @test AC-IMP-006
     */
    public function it_allows_admin_to_force_end_arbitrary_session(): void
    {
        $parent = $this->openNormalSession($this->admin);
        /** @var ImpersonationService $svc */
        $svc = app(ImpersonationService::class);
        $payload = $svc->begin($this->admin, $parent, $this->shardTarget, 'Force end test', 'req-004');
        $sessionId = $payload['SessionId'];

        // End by force
        $svc->forceEnd($this->admin, $sessionId, 'req-005');

        $this->assertDatabaseHas('ImpersonationIndex', [
            'SessionId' => $sessionId,
            'EndReason' => 'AdminForced',
        ], self::ROOT);
        
        // Verify shard session closed
        $this->assertDatabaseHas('AuthSessions', [
            'SessionId' => $sessionId,
            'RevokeReason' => 'AdminForced',
        ], self::SHARD);
    }

    private function migrateRoot(): void
    {
        $files = [
            '2026_07_18_000001_create_root_identity_tables.php',
            '2026_07_18_000002_create_root_reseller_tables.php',
            '2026_07_18_000006_create_root_auth_sessions_table.php',
            '2026_07_18_000007_create_root_impersonation_index_table.php',
            '2026_07_18_000008_create_root_personal_access_tokens_table.php',
        ];

        foreach ($files as $file) {
            $m = require base_path('database/migrations/root/' . $file);
            $m->up();
        }

        // Shard needs migrations too
        $shardFiles = [
            '2026_07_18_000013_create_shard_auth_sessions_table.php',
        ];
        
        // Temporarily bind shard to run migrations
        config()->set('database.connections.shard', config('database.connections.shard_template'));
        foreach ($shardFiles as $file) {
            $m = require base_path('database/migrations/shard/' . $file);
            $m->up();
        }
    }

    private function seedIdentities(): void
    {
        $roleId = DB::connection(self::ROOT)->table('Roles')->insertGetId([
            'RoleName' => 'Admin',
        ]);

        $this->admin = User::create([
            'Email' => 'admin@lara.test',
            'PasswordHash' => 'hash',
            'IsActive' => true,
        ]);

        DB::connection(self::ROOT)->table('UserRoles')->insert([
            'UserId' => $this->admin->UserId,
            'RoleId' => $roleId,
        ]);

        $this->rootTarget = User::create([
            'Email' => 'target.root@lara.test',
            'PasswordHash' => 'hash',
            'IsActive' => true,
        ]);

        $this->reseller = Reseller::create([
            'ResellerName' => 'ACME Shard',
            'ResellerSlug' => self::SLUG,
            'ContactEmail' => 'acme@lara.test',
            'IsActive' => true,
        ]);

        $this->shardTarget = User::create([
            'Email' => 'target.shard@lara.test',
            'PasswordHash' => 'hash',
            'TenantId' => $this->reseller->ResellerId,
            'IsActive' => true,
        ]);
        
        // Add shard route
        DB::connection(self::ROOT)->table('ResellerShardRoutes')->insert([
            'ResellerId' => $this->reseller->ResellerId,
            'AppDbPath' => $this->shardDbPath,
            'ShardStatus' => 'Active',
        ]);
    }

    private function openNormalSession(User $user): AuthSession
    {
        /** @var AuthSessionService $svc */
        $svc = app(AuthSessionService::class);

        return $svc->openNormal($user);
    }
}
