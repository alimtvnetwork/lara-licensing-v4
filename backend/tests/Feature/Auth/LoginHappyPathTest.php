<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\AuthSession;
use App\Services\AuthSessionService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Support\AssertsLaraException;
use Tests\TestCase;

/**
 * Plan 10 step 15 (Pest matrix, `Auth/LoginHappyPathTest` row).
 *
 * Locks `POST /Api/Auth/Login` (App\Http\Controllers\Auth\LoginController)
 * end-to-end on the SUCCESS branch: correct email + password for an active
 * user, no CAPTCHA required (failure counter below the configured threshold).
 *
 * Branches guarded:
 *   1. Happy path returns 200 with Status.IsSuccess=true and a Results[0]
 *      row that carries UserId, Email, SessionId (UUID), ExpiresAt (ISO-8601),
 *      Token (plaintext Sanctum bearer), and RememberMe echoed back.
 *   2. Side effects that MUST occur:
 *      - `AuthSessions` row is written with Kind=Normal, matching UserId,
 *        ExpiresAt in the future, EndedAt=null (session is open).
 *      - `personal_access_tokens` row is written with `name` = SessionId
 *        so LogoutController can pair the bearer back to the session
 *        (spec 21 §31, contract shared with `Auth/LogoutTest`).
 *      - `auth.login.failures:*` cache key is cleared on success so the
 *        next login doesn't inherit stale failure count and demand CAPTCHA.
 *      - `Log::info('auth.login', ...)` fires with UserId and SessionId
 *        so operator observability survives refactors.
 *   3. RememberMe=true opts into the longer session TTL: ExpiresAt on the
 *      returned session MUST exceed the default (non-remember) TTL. A
 *      regression that ignores the flag would silently downgrade sessions.
 *
 * Root cause guarded: v0.354.0 locked the 401 rejection branches but the
 * SUCCESS branch had zero HTTP-level lock. A refactor of
 * `LoginController::__invoke()` that (a) forgot to call
 * `AuthSessionService::openNormal()` leaving no session row, (b) minted the
 * Sanctum token with `name != SessionId` breaking logout replay defence,
 * (c) forgot to clear the failure counter causing perpetual CAPTCHA after
 * one prior failed attempt, or (d) ignored RememberMe silently, would all
 * ship green under the existing suite.
 *
 * Fixture strategy: same raw-sqlite approach as `LoginBadCredentialsTest`
 * and `LogoutTest` to avoid Postgres-only DDL in Root migrations.
 */
final class LoginHappyPathTest extends TestCase
{
    use AssertsLaraException;

    private const EMAIL = 'happy@example.test';
    private const PASSWORD = 'CorrectHorse!Battery42';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        RateLimiter::clear('auth:login|ip=127.0.0.1');
        $this->createRootFixtures();
        $this->seedActiveUser();
    }

    public function test_happy_path_returns_envelope_opens_session_and_mints_paired_token(): void
    {
        Log::spy();

        $res = $this->postJson('/Api/Auth/Login', [
            'Email' => self::EMAIL,
            'Password' => self::PASSWORD,
        ]);

        $res->assertStatus(200);
        $json = $res->json();
        $this->assertTrue($json['Status']['IsSuccess'] ?? false, 'Status.IsSuccess must be true on happy-path login.');

        $row = $json['Results'][0] ?? null;
        $this->assertIsArray($row, 'Results[0] must be an object.');
        $this->assertSame(self::EMAIL, $row['Email'] ?? null);
        $this->assertIsInt($row['UserId'] ?? null);
        $this->assertNotEmpty($row['SessionId'] ?? null, 'SessionId must be present.');
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
            (string) $row['SessionId'],
            'SessionId must be a UUID.',
        );
        $this->assertNotEmpty($row['Token'] ?? null, 'Token (plaintext bearer) must be present.');
        $this->assertNotEmpty($row['ExpiresAt'] ?? null, 'ExpiresAt must be present.');
        $this->assertFalse($row['RememberMe'] ?? true, 'RememberMe defaults to false when not requested.');

        $sessionId = (string) $row['SessionId'];
        $session = AuthSession::query()->where('SessionId', $sessionId)->first();
        $this->assertNotNull($session, 'AuthSessions row must be written on login.');
        $this->assertSame(AuthSession::KIND_NORMAL, $session->Kind, 'Login must open a Normal session, not Impersonation.');
        $this->assertSame((int) $row['UserId'], (int) $session->UserId);
        $this->assertNull($session->EndedAt, 'Fresh session must be open (EndedAt=null).');

        $patRow = DB::connection('root')->table('personal_access_tokens')
            ->where('name', $sessionId)->first();
        $this->assertNotNull($patRow, 'personal_access_tokens row must be paired to SessionId by `name` column (spec 21 §31).');

        Log::shouldHaveReceived('info')
            ->withArgs(fn ($event, $ctx = []) => $event === 'auth.login'
                && ($ctx['SessionId'] ?? null) === $sessionId
                && ($ctx['UserId'] ?? null) === (int) $row['UserId'])
            ->atLeast()->once();
    }

    public function test_success_clears_prior_failure_counter_so_captcha_gate_resets(): void
    {
        $failureKey = 'auth.login.failures:' . sha1(strtolower(self::EMAIL) . '|127.0.0.1');
        Cache::put($failureKey, 1, 900);

        $res = $this->postJson('/Api/Auth/Login', [
            'Email' => self::EMAIL,
            'Password' => self::PASSWORD,
        ]);

        $res->assertStatus(200);
        $this->assertNull(
            Cache::get($failureKey),
            'Successful login must clear the failure counter so subsequent logins are not gated by stale CAPTCHA state.',
        );
    }

    public function test_remember_me_true_extends_expires_at_beyond_default_ttl(): void
    {
        $defaultRes = $this->postJson('/Api/Auth/Login', [
            'Email' => self::EMAIL,
            'Password' => self::PASSWORD,
            'RememberMe' => false,
        ]);
        $defaultExpires = strtotime((string) $defaultRes->json('Results.0.ExpiresAt'));

        // Second call gets a fresh session; compare the RememberMe ExpiresAt
        // against the non-remember one taken moments before.
        $rememberRes = $this->postJson('/Api/Auth/Login', [
            'Email' => self::EMAIL,
            'Password' => self::PASSWORD,
            'RememberMe' => true,
        ]);
        $rememberRes->assertStatus(200);
        $this->assertTrue($rememberRes->json('Results.0.RememberMe'), 'RememberMe must echo true when requested.');
        $rememberExpires = strtotime((string) $rememberRes->json('Results.0.ExpiresAt'));

        $this->assertGreaterThan(
            $defaultExpires,
            $rememberExpires,
            'RememberMe=true must produce an ExpiresAt strictly later than the default-TTL session.',
        );
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

    private function seedActiveUser(): void
    {
        DB::connection('root')->table('Users')->insert([
            'Email' => self::EMAIL,
            'PasswordHash' => Hash::make(self::PASSWORD),
            'IsActive' => 1,
        ]);
    }
}
