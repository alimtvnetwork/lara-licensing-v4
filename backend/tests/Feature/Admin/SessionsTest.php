<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\AuthSession;
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
 * Plan 10 step 15 (Pest matrix, Admin/* row 2: Admin/SessionsTest).
 *
 * Locks the Admin session-management surface end-to-end:
 *   GET    /Api/Admin/Users/{UserId}/Sessions
 *   DELETE /Api/Admin/Sessions/{SessionId}
 * through the full admin stack: auth:sanctum -> session.active ->
 * require.role:Admin|SuperAdmin (routes/api.php line 61, 175-177).
 *
 * Root cause guarded (one sentence): Admin\SessionController shipped in
 * v0.298.0-v0.302.0 with force-revoke authority over any AuthSession
 * (RevokeReason=AdminForced, spec 47 §"AdminForced") plus the paired
 * Sanctum PAT deletion in AuthSessionService::revokeTokensForSession,
 * but zero HTTP-level lock existed, so regressions that (a) skipped
 * User-existence 404, (b) skipped Session-existence 404, (c) let a
 * DELETE against an already-closed session double-write (409 -> 200
 * drift), (d) failed to stamp RevokeReason=AdminForced on the row, or
 * (e) failed to delete the paired PAT (leaving revoked sessions with
 * live bearer tokens: security-critical) could all ship green.
 *
 * Branches guarded:
 *   1. GET bare -> 401 AuthUnauthorized (guard).
 *   2. GET as non-Admin -> 403 AuthForbidden.
 *   3. GET for unknown UserId -> 404 UserNotFound.
 *   4. GET happy path: active session included, ended session filtered by
 *      default, IncludeEnded=1 includes it, Limit clamps to LIMIT_MAX=200.
 *   5. DELETE unknown SessionId -> 404 AuthSessionNotFound.
 *   6. DELETE already-ended SessionId -> 409 AuthSessionAlreadyClosed.
 *   7. DELETE happy path: row EndedAt stamped, RevokeReason='AdminForced',
 *      paired PAT deleted, audit row written, response envelope shape.
 */
final class SessionsTest extends TestCase
{
    use AssertsLaraException;

    private const ADMIN_EMAIL = 'admin-sessions@example.test';
    private const TARGET_EMAIL = 'target-sessions@example.test';
    private const PASSWORD = 'CorrectHorse!Battery42';

    private User $admin;
    private User $target;
    private string $adminSessionId;
    private string $adminBearer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createRootFixtures();
        $this->swapRolePolicy();
        $this->admin = $this->makeUser(self::ADMIN_EMAIL);
        $this->target = $this->makeUser(self::TARGET_EMAIL);
        [$this->adminSessionId, $this->adminBearer] = $this->openSessionAndMintToken($this->admin);
    }

    public function test_index_bare_returns_401(): void
    {
        $res = $this->getJson('/Api/Admin/Users/' . $this->target->getKey() . '/Sessions');
        $this->assertLaraException($res, 'AuthUnauthorized', 401);
    }

    public function test_index_without_admin_role_returns_403(): void
    {
        \Tests\Feature\FakeHasRolePolicy::$grants = [(string) $this->admin->getKey() => ['Reseller']];
        $res = $this->withHeader('Authorization', 'Bearer ' . $this->adminBearer)
            ->getJson('/Api/Admin/Users/' . $this->target->getKey() . '/Sessions');
        $this->assertLaraException($res, 'AuthForbidden', 403);
    }

    public function test_index_unknown_user_returns_404(): void
    {
        \Tests\Feature\FakeHasRolePolicy::$grants = [(string) $this->admin->getKey() => ['Admin']];
        $res = $this->withHeader('Authorization', 'Bearer ' . $this->adminBearer)
            ->getJson('/Api/Admin/Users/9999999/Sessions');
        $this->assertLaraException($res, 'UserNotFound', 404);
    }

    public function test_index_happy_path_filters_ended_and_clamps_limit(): void
    {
        Log::spy();
        \Tests\Feature\FakeHasRolePolicy::$grants = [(string) $this->admin->getKey() => ['Admin']];

        // Seed one active + one ended session on the target user.
        [$activeSessionId] = $this->openSessionAndMintToken($this->target);
        $endedSessionId = $this->openEndedSessionRow($this->target);

        // Default (IncludeEnded=0): only active is returned.
        $res = $this->withHeader('Authorization', 'Bearer ' . $this->adminBearer)
            ->getJson('/Api/Admin/Users/' . $this->target->getKey() . '/Sessions');
        $res->assertStatus(200);
        $rows = $res->json('Results');
        $ids = array_column($rows, 'SessionId');
        $this->assertContains($activeSessionId, $ids);
        $this->assertNotContains($endedSessionId, $ids, 'IncludeEnded=0 (default) must filter EndedAt IS NOT NULL rows.');

        // IncludeEnded=1: ended row appears too.
        $res2 = $this->withHeader('Authorization', 'Bearer ' . $this->adminBearer)
            ->getJson('/Api/Admin/Users/' . $this->target->getKey() . '/Sessions?IncludeEnded=1');
        $res2->assertStatus(200);
        $ids2 = array_column($res2->json('Results'), 'SessionId');
        $this->assertContains($endedSessionId, $ids2);
        $this->assertContains($activeSessionId, $ids2);

        // Limit clamps to LIST_LIMIT_MAX=200.
        $res3 = $this->withHeader('Authorization', 'Bearer ' . $this->adminBearer)
            ->getJson('/Api/Admin/Users/' . $this->target->getKey() . '/Sessions?Limit=9999');
        $res3->assertStatus(200);
        $this->assertSame(200, $res3->json('Attributes.Limit'), 'Limit must clamp to LIST_LIMIT_MAX=200.');

        Log::shouldHaveReceived('info')
            ->withArgs(fn ($event, $ctx = []) => $event === 'admin.sessions.index' && ($ctx['UserId'] ?? null) === (int) $this->target->getKey())
            ->atLeast()->once();
    }

    public function test_destroy_unknown_session_returns_404(): void
    {
        \Tests\Feature\FakeHasRolePolicy::$grants = [(string) $this->admin->getKey() => ['Admin']];
        $res = $this->withHeader('Authorization', 'Bearer ' . $this->adminBearer)
            ->deleteJson('/Api/Admin/Sessions/' . Uuid::uuid4()->toString());
        $this->assertLaraException($res, 'AuthSessionNotFound', 404);
    }

    public function test_destroy_already_ended_returns_409(): void
    {
        \Tests\Feature\FakeHasRolePolicy::$grants = [(string) $this->admin->getKey() => ['Admin']];
        $endedSessionId = $this->openEndedSessionRow($this->target);
        $res = $this->withHeader('Authorization', 'Bearer ' . $this->adminBearer)
            ->deleteJson('/Api/Admin/Sessions/' . $endedSessionId);
        $this->assertLaraException($res, 'AuthSessionAlreadyClosed', 409);
    }

    public function test_destroy_happy_path_stamps_admin_forced_and_kills_pat(): void
    {
        Log::spy();
        \Tests\Feature\FakeHasRolePolicy::$grants = [(string) $this->admin->getKey() => ['SuperAdmin']];

        [$victimSessionId, $victimBearer] = $this->openSessionAndMintToken($this->target);
        $this->assertNotNull($victimBearer);

        // Confirm paired PAT exists BEFORE revoke.
        $patBefore = DB::connection('root')->table('personal_access_tokens')
            ->where('name', $victimSessionId)->count();
        $this->assertSame(1, $patBefore, 'Precondition: victim session must have exactly one paired PAT.');

        $res = $this->withHeader('Authorization', 'Bearer ' . $this->adminBearer)
            ->deleteJson('/Api/Admin/Sessions/' . $victimSessionId);
        $res->assertStatus(200);
        $this->assertSame($victimSessionId, $res->json('Results.0.SessionId'));
        $this->assertSame('AdminForced', $res->json('Results.0.RevokeReason'));

        // Row stamped.
        $row = DB::connection('root')->table('AuthSessions')
            ->where('SessionId', $victimSessionId)->first();
        $this->assertNotNull($row->EndedAt, 'EndedAt must be stamped after force-revoke.');
        $this->assertSame('AdminForced', $row->RevokeReason, 'RevokeReason must be AdminForced (spec 47).');

        // Paired PAT deleted (security-critical: no live bearer post-revoke).
        $patAfter = DB::connection('root')->table('personal_access_tokens')
            ->where('name', $victimSessionId)->count();
        $this->assertSame(0, $patAfter, 'Paired Sanctum PAT must be deleted so the bearer cannot outlive the revoked session.');

        // Audit trail row (AuthSessionRevoked).
        $audit = DB::connection('root')->table('AuditLogs')
            ->where('Action', 'AuthSessionRevoked')
            ->where('TargetType', 'AuthSessions')
            ->first();
        $this->assertNotNull($audit, 'AuditWriter must write an AuthSessionRevoked row.');

        Log::shouldHaveReceived('info')
            ->withArgs(fn ($event, $ctx = []) => $event === 'auth.session.close' && ($ctx['RevokeReason'] ?? null) === 'AdminForced')
            ->atLeast()->once();
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
            "TargetId" INTEGER NULL,
            "RequestId" TEXT NOT NULL,
            "PayloadJson" TEXT NULL,
            "CreatedAt" TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )');
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

    /** @return array{0:string,1:string} */
    private function openSessionAndMintToken(User $user): array
    {
        $sessionId = Uuid::uuid4()->toString();
        $now = Carbon::now();
        $row = new AuthSession();
        $row->SessionId = $sessionId;
        $row->UserId = (int) $user->getKey();
        $row->Kind = AuthSession::KIND_NORMAL;
        $row->ImpersonatorUserId = null;
        $row->ParentSessionId = null;
        $row->CreatedAt = $now;
        $row->ExpiresAt = $now->copy()->addMinutes(60);
        $row->EndedAt = null;
        $row->RevokeReason = null;
        $row->save();
        $token = $user->createToken($sessionId);

        return [$sessionId, $token->plainTextToken];
    }

    private function openEndedSessionRow(User $user): string
    {
        $sessionId = Uuid::uuid4()->toString();
        $now = Carbon::now();
        $row = new AuthSession();
        $row->SessionId = $sessionId;
        $row->UserId = (int) $user->getKey();
        $row->Kind = AuthSession::KIND_NORMAL;
        $row->ImpersonatorUserId = null;
        $row->ParentSessionId = null;
        $row->CreatedAt = $now->copy()->subHour();
        $row->ExpiresAt = $now->copy()->addMinutes(60);
        $row->EndedAt = $now->copy()->subMinutes(5);
        $row->RevokeReason = 'OperatorLogout';
        $row->save();

        return $sessionId;
    }
}
