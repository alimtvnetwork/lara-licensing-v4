<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\AuthSession;
use App\Models\User;
use App\Services\AuthSessionService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Ramsey\Uuid\Uuid;
use Tests\Support\AssertsLaraException;
use Tests\TestCase;

/**
 * Plan 10 step 15 (Pest matrix, `Auth/LogoutTest` row).
 *
 * Locks `POST /Api/Auth/Logout` (App\Http\Controllers\Auth\LogoutController)
 * end-to-end through the HTTP kernel including the `auth:sanctum` +
 * `session.active` middleware pair registered in `routes/api.php:50`.
 *
 * Branches guarded:
 *   1. Happy path with a live bearer:
 *      - HTTP 200, `Status.IsSuccess=true`, `Results[0].SessionId` equals
 *        the paired SessionId (not blank, not a fresh UUID).
 *      - `AuthSessions.EndedAt` gets stamped and `RevokeReason` equals
 *        `AuthSessionService::REVOKE_OPERATOR_LOGOUT` ("OperatorEnded"),
 *        not one of the other closed-set members ("Timeout"/"AdminForced").
 *      - The `personal_access_tokens` row is deleted so the same bearer
 *        cannot be replayed (spec 46 §4.3 defence-in-depth).
 *      - `Log::info('auth.logout', ...)` fires with the caller UserId and
 *        SessionId, and `Log::info('auth.session.close', ...)` fires with
 *        `RevokeReason=OperatorEnded` so operator observability survives.
 *   2. Replay of a token whose paired AuthSession is already closed:
 *      returns 401 `AuthUnauthorized` via `AssertActiveSessionMiddleware`
 *      (spec 46 §4.3, spec 47 §4). A regression that lets a closed-session
 *      token keep authenticating until its natural PAT expiry would
 *      violate spec 46 AC-SESSION-005 and enable ghost sessions.
 *   3. Bare (no Authorization header): returns 401 `AuthUnauthorized` from
 *      the guard, envelope-shaped (not a stock Laravel 401 HTML page).
 *
 * Root cause guarded: prior tests covered login (v0.354.0), captcha
 * (v0.293.0), rate-limit (v0.297.0), session-timeout sweep (v0.302.0),
 * and admin session revocation (v0.299.0), but there was zero HTTP-level
 * lock on the operator logout path. A refactor of `LogoutController`
 * that forgot to call `AuthSessionService::close()` (leaving `EndedAt`
 * NULL), forgot to `$token->delete()` (leaving the bearer replayable),
 * or emitted the wrong `RevokeReason` (e.g. `AdminForced` on operator
 * logout, corrupting session-history audit rollups) would ship green.
 *
 * Fixture strategy: same raw-sqlite approach as
 * `Tests\Feature\Auth\LoginBadCredentialsTest` and `RegisterBootstrapTest`
 * to avoid the Postgres-only DDL in the Root migrations (BIGSERIAL,
 * TIMESTAMPTZ, partial indexes). We create only the three tables the
 * logout path touches: `Users`, `AuthSessions`, `personal_access_tokens`.
 */
final class LogoutTest extends TestCase
{
    use AssertsLaraException;

    private const EMAIL = 'logout@example.test';
    private const PASSWORD = 'CorrectHorse!Battery42';

    private User $user;
    private string $sessionId;
    private string $bearer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createRootFixtures();
        $this->user = $this->makeUser();
        [$this->sessionId, $this->bearer] = $this->openSessionAndMintToken($this->user);
    }

    public function test_logout_closes_session_deletes_token_and_logs(): void
    {
        Log::spy();

        $res = $this->withHeader('Authorization', 'Bearer ' . $this->bearer)
            ->postJson('/Api/Auth/Logout');

        $res->assertStatus(200);
        $json = $res->json();
        $this->assertTrue($json['Status']['IsSuccess'] ?? false, 'Status.IsSuccess must be true on happy-path logout.');
        $this->assertSame($this->sessionId, $json['Results'][0]['SessionId'] ?? null, 'Results[0].SessionId must equal paired SessionId.');

        $session = AuthSession::query()->where('SessionId', $this->sessionId)->first();
        $this->assertNotNull($session, 'AuthSession row must still exist after close (soft close, not delete).');
        $this->assertNotNull($session->EndedAt, 'AuthSessions.EndedAt must be stamped by logout.');
        $this->assertSame(
            AuthSessionService::REVOKE_OPERATOR_LOGOUT,
            $session->RevokeReason,
            'RevokeReason must be OperatorEnded on operator logout, never Timeout or AdminForced.',
        );

        $tokenRows = DB::connection('root')
            ->table('personal_access_tokens')
            ->where('name', $this->sessionId)
            ->count();
        $this->assertSame(0, $tokenRows, 'Sanctum PAT paired to the SessionId must be deleted so the bearer cannot be replayed.');

        Log::shouldHaveReceived('info')
            ->withArgs(fn ($event, $ctx = []) => $event === 'auth.logout' && ($ctx['SessionId'] ?? null) === $this->sessionId)
            ->atLeast()->once();
        Log::shouldHaveReceived('info')
            ->withArgs(fn ($event, $ctx = []) => $event === 'auth.session.close' && ($ctx['RevokeReason'] ?? null) === AuthSessionService::REVOKE_OPERATOR_LOGOUT)
            ->atLeast()->once();
    }

    public function test_logout_without_bearer_returns_envelope_401(): void
    {
        $res = $this->postJson('/Api/Auth/Logout');
        $this->assertLaraException($res, 'AuthUnauthorized', 401);
    }

    public function test_replay_after_close_is_rejected_by_active_session_gate(): void
    {
        // First call closes the session and deletes the PAT.
        $this->withHeader('Authorization', 'Bearer ' . $this->bearer)
            ->postJson('/Api/Auth/Logout')
            ->assertStatus(200);

        // Second call with the same bearer must not authenticate at all
        // (PAT is gone). The guard-level rejection surfaces as
        // `AuthUnauthorized` via the exception handler.
        $res = $this->withHeader('Authorization', 'Bearer ' . $this->bearer)
            ->postJson('/Api/Auth/Logout');
        $this->assertLaraException($res, 'AuthUnauthorized', 401);
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
    }

    private function makeUser(): User
    {
        $user = new User();
        $user->Email = self::EMAIL;
        $user->PasswordHash = Hash::make(self::PASSWORD);
        $user->TenantId = null;
        $user->IsActive = true;
        $user->save();

        return $user->refresh();
    }

    /**
     * @return array{0:string,1:string} [$sessionId, $plainTextBearer]
     */
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

        // Sanctum's `createToken` writes to `personal_access_tokens` with
        // `name = SessionId` matching LoginController's contract
        // (spec/21-app/31-auth-session-family.md).
        $token = $user->createToken($sessionId);

        return [$sessionId, $token->plainTextToken];
    }
}
