<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Support\AssertsLaraException;
use Tests\TestCase;

/**
 * Plan 10 step 15 (Pest matrix, Auth/LoginTest row). Locks the 401
 * `AuthInvalidCredentials` branch of `POST /Api/Auth/Login` for the three
 * distinct rejection reasons that `LoginController::verifyCredentials()`
 * surfaces:
 *
 *   1. Unknown email          -> Reason=UnknownEmail
 *   2. Wrong password         -> Reason=BadPassword
 *   3. Inactive user + right password -> Reason=Inactive
 *
 * All three MUST hit the canonical envelope with ErrorCode=AuthInvalidCredentials
 * and HTTP 401, and MUST NOT leak which of the three actually fired to the
 * caller (the distinguishing log line lives server-side).
 *
 * Root cause guarded: prior tests covered the captcha (428) and rate-limit
 * (429) branches added in v0.292.0 - v0.297.0 but never the base 401 path,
 * so a refactor of `verifyCredentials()` that regressed the envelope (for
 * example, throwing a stock `AuthenticationException` -> 500 or leaking a
 * "user not found" message) would ship green.
 *
 * We also lock the side-effect: every failed attempt MUST increment the
 * `auth.login.failures:<sha1(email|ip)>` cache key so the captcha gate
 * flips at the configured threshold. A regression that stops recording
 * failures would silently disable the CAPTCHA challenge.
 *
 * We stub the Root `Users` table with a raw sqlite fixture (same shape as
 * `RegisterBootstrapTest`) so this test does not require the full Root
 * migration set or Sanctum boot state; the controller path we exercise
 * short-circuits before any AuthSession write.
 */
final class LoginBadCredentialsTest extends TestCase
{
    use AssertsLaraException;

    private const EMAIL = 'known@example.test';
    private const PASSWORD = 'CorrectHorse!Battery42';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        RateLimiter::clear('auth:login|ip=127.0.0.1');
        $this->createRootUsersFixture();
    }

    public function test_unknown_email_returns_401_and_increments_failure_counter(): void
    {
        $res = $this->postJson('/Api/Auth/Login', [
            'Email' => 'nobody@example.test',
            'Password' => 'anything-goes',
        ]);

        $this->assertLaraException($res, 'AuthInvalidCredentials', 401);
        $this->assertSame(
            1,
            (int) Cache::get($this->failureKey('nobody@example.test')),
            'auth.login.failures counter must increment on unknown-email rejection.',
        );
    }

    public function test_wrong_password_for_known_user_returns_401_and_increments_counter(): void
    {
        $res = $this->postJson('/Api/Auth/Login', [
            'Email' => self::EMAIL,
            'Password' => 'WrongPassword!000',
        ]);

        $this->assertLaraException($res, 'AuthInvalidCredentials', 401);
        $this->assertSame(
            1,
            (int) Cache::get($this->failureKey(self::EMAIL)),
            'auth.login.failures counter must increment on wrong-password rejection.',
        );
    }

    public function test_inactive_user_with_right_password_returns_401_indistinguishably(): void
    {
        DB::connection('root')->table('Users')
            ->where('Email', self::EMAIL)
            ->update(['IsActive' => 0]);

        $res = $this->postJson('/Api/Auth/Login', [
            'Email' => self::EMAIL,
            'Password' => self::PASSWORD,
        ]);

        $this->assertLaraException($res, 'AuthInvalidCredentials', 401);
    }

    public function test_repeated_failures_accumulate_toward_captcha_threshold(): void
    {
        for ($i = 1; $i <= 2; $i++) {
            $this->postJson('/Api/Auth/Login', [
                'Email' => self::EMAIL,
                'Password' => 'wrong-' . $i,
            ])->assertStatus(401);
        }
        $this->assertSame(
            2,
            (int) Cache::get($this->failureKey(self::EMAIL)),
            'Repeated bad-credential attempts must accumulate a single counter, not reset it.',
        );
    }

    private function failureKey(string $email): string
    {
        return 'auth.login.failures:' . sha1(strtolower($email) . '|127.0.0.1');
    }

    private function createRootUsersFixture(): void
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
        $root->table('Users')->insert([
            'Email' => self::EMAIL,
            'PasswordHash' => Hash::make(self::PASSWORD),
            'IsActive' => 1,
        ]);
    }
}
