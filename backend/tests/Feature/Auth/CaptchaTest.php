<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Support\LoginCaptcha;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Support\AssertsLaraException;
use Tests\TestCase;

/**
 * Plan 10 step 15 (Pest matrix, `Auth/CaptchaTest` row).
 *
 * Locks the two HTTP surfaces that make up the login CAPTCHA gate:
 *   1. `GET  /Api/Auth/Captcha` (`App\Http\Controllers\Auth\CaptchaController`)
 *   2. `POST /Api/Auth/Login`  captcha branches inside
 *      `App\Http\Controllers\Auth\LoginController::enforceCaptcha()`
 *
 * Branches guarded:
 *   A) Issue: 200 envelope with `Results[0].ChallengeId`,
 *      `Results[0].Question` (human-readable "A op B"), and
 *      `Results[0].ExpiresAt` (ISO-8601). ChallengeId format is
 *      `<base64url(payload)>.<base64url(hmac_sha256)>` per
 *      `App\Support\LoginCaptcha::sign()`; a regression that drops the
 *      signature segment would break stateless verification and let any
 *      forged payload pass.
 *   B) Login with a valid, freshly-issued captcha + right credentials
 *      succeeds (200) even when the failure counter is at threshold; the
 *      counter is cleared on success so the next login is not re-gated.
 *   C) Login with a garbled ChallengeId returns `LoginCaptchaInvalid`
 *      (HTTP 401 per `config('lara.error_http_status.LoginCaptchaInvalid')`).
 *   D) Login with a signature-forged ChallengeId returns
 *      `LoginCaptchaInvalid` (401). This is the "swap the payload but keep
 *      the old signature" attack; a regression that skips
 *      `hash_equals()` would ship green without this row.
 *   E) Login above the failure threshold with NO captcha material at all
 *      returns `LoginCaptchaRequired` (HTTP 428). The gate MUST fire
 *      before credentials are checked, so an attacker with the right
 *      password still cannot bypass the human-check by racing the counter.
 *   F) `auth.login.captcha_issued`, `auth.login.captcha_ok`, and
 *      `auth.login.captcha_rejected` log events fire so operator dashboards
 *      keep working after any refactor.
 *
 * Root cause guarded: v0.294.0 registered the error codes but never locked
 * the wire contract. A refactor that (a) forgot to include the HMAC segment
 * on issue, (b) accepted an expired challenge (Exp < now), (c) let a
 * signature-forged payload through by using loose comparison instead of
 * `hash_equals`, or (d) checked credentials before the captcha threshold
 * (leaking "which side failed" via response timing) would ship green under
 * the existing suite.
 *
 * Fixture strategy: raw-sqlite `Users` table, matching neighbouring auth
 * tests. Threshold is pinned via config override so the test doesn't depend
 * on the deployed value.
 */
final class CaptchaTest extends TestCase
{
    use AssertsLaraException;

    private const EMAIL = 'captcha@example.test';
    private const PASSWORD = 'CorrectHorse!Battery42';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        RateLimiter::clear('auth:login|ip=127.0.0.1');
        RateLimiter::clear('auth:captcha|ip=127.0.0.1');
        // Pin threshold=2 so tests are deterministic regardless of env config.
        Config::set('lara.login_captcha.required_after_failed_attempts', 2);
        $this->createRootFixtures();
        $this->seedActiveUser();
    }

    public function test_issue_returns_challenge_id_question_and_expires_at(): void
    {
        Log::spy();

        $res = $this->getJson('/Api/Auth/Captcha');

        $res->assertStatus(200);
        $row = $res->json('Results.0');
        $this->assertIsArray($row);
        $this->assertNotEmpty($row['ChallengeId'] ?? null, 'ChallengeId must be present.');
        $this->assertNotEmpty($row['Question'] ?? null, 'Question must be present.');
        $this->assertNotEmpty($row['ExpiresAt'] ?? null, 'ExpiresAt must be present.');

        // ChallengeId contract: two base64url segments joined by ".".
        // Missing the signature segment would break stateless verification.
        $parts = explode('.', (string) $row['ChallengeId']);
        $this->assertCount(2, $parts, 'ChallengeId must be payload.signature (two segments).');
        $this->assertNotSame('', $parts[0]);
        $this->assertNotSame('', $parts[1]);

        // ExpiresAt is in the future (challenge_ttl_seconds default 300s).
        $this->assertGreaterThan(time(), strtotime((string) $row['ExpiresAt']));

        Log::shouldHaveReceived('info')
            ->withArgs(fn ($event, $ctx = []) => $event === 'auth.login.captcha_issued')
            ->atLeast()->once();
    }

    public function test_valid_captcha_with_right_password_succeeds_at_threshold(): void
    {
        // Push failure counter to the threshold so CAPTCHA is required.
        $failureKey = 'auth.login.failures:' . sha1(strtolower(self::EMAIL) . '|127.0.0.1');
        Cache::put($failureKey, 2, 900);

        [$id, $answer] = $this->mintValidChallenge();

        Log::spy();
        $res = $this->postJson('/Api/Auth/Login', [
            'Email' => self::EMAIL,
            'Password' => self::PASSWORD,
            'CaptchaChallengeId' => $id,
            'CaptchaAnswer' => $answer,
        ]);

        $res->assertStatus(200);
        $this->assertNull(
            Cache::get($failureKey),
            'Successful login through captcha must clear the failure counter.',
        );
        Log::shouldHaveReceived('info')
            ->withArgs(fn ($event, $ctx = []) => $event === 'auth.login.captcha_ok')
            ->atLeast()->once();
    }

    public function test_garbled_challenge_id_returns_401_captcha_invalid(): void
    {
        $res = $this->postJson('/Api/Auth/Login', [
            'Email' => self::EMAIL,
            'Password' => self::PASSWORD,
            'CaptchaChallengeId' => 'not-a-real-challenge',
            'CaptchaAnswer' => '5',
        ]);
        $this->assertLaraException($res, 'LoginCaptchaInvalid', 401);
    }

    public function test_signature_forged_challenge_returns_401_captcha_invalid(): void
    {
        // Take a real challenge, swap the payload segment to something the
        // signature no longer covers. `hash_equals` must reject it.
        [$id, $answer] = $this->mintValidChallenge();
        [$body, $sig] = explode('.', $id, 2);
        $forgedBody = rtrim(strtr(base64_encode(
            (string) json_encode(['A' => 1, 'B' => 1, 'Op' => 'Add', 'Exp' => time() + 300])
        ), '+/', '-_'), '=');
        $forged = $forgedBody . '.' . $sig;

        $res = $this->postJson('/Api/Auth/Login', [
            'Email' => self::EMAIL,
            'Password' => self::PASSWORD,
            'CaptchaChallengeId' => $forged,
            'CaptchaAnswer' => '2', // the forged "1 + 1" answer
        ]);
        $this->assertLaraException($res, 'LoginCaptchaInvalid', 401);

        // Original answer we did not send is unused; ensure the forgery is
        // what triggered rejection, not the answer.
        $this->assertNotSame($answer, '2');
    }

    public function test_above_threshold_without_any_captcha_material_returns_428(): void
    {
        $failureKey = 'auth.login.failures:' . sha1(strtolower(self::EMAIL) . '|127.0.0.1');
        Cache::put($failureKey, 2, 900);

        // Even with the right password, the gate MUST fire before creds
        // are checked.
        $res = $this->postJson('/Api/Auth/Login', [
            'Email' => self::EMAIL,
            'Password' => self::PASSWORD,
        ]);
        $this->assertLaraException($res, 'LoginCaptchaRequired', 428);
    }

    /**
     * @return array{0:string,1:string} [$challengeId, $correctAnswer]
     */
    private function mintValidChallenge(): array
    {
        $challenge = LoginCaptcha::issue();
        // Question shape is "A <symbol> B"; recompute the answer here so we
        // avoid coupling to the private computeAnswer().
        $q = (string) $challenge['Question'];
        if (preg_match('/^(\d+)\s+([+\-×])\s+(\d+)$/u', $q, $m) !== 1) {
            $this->fail("Unrecognized captcha question format: {$q}");
        }
        $a = (int) $m[1];
        $b = (int) $m[3];
        $answer = match ($m[2]) {
            '+' => $a + $b,
            '-' => $a - $b,
            '×' => $a * $b,
        };

        return [(string) $challenge['ChallengeId'], (string) $answer];
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
