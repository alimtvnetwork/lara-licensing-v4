<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\LoginCaptcha;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * v0.293.0 regression guard.
 *
 * Root cause guarded: v0.292.0 modernized login introduced LoginCaptchaRequired
 * (428) and LoginCaptchaInvalid (401) throws in Auth\LoginController without
 * registering the codes in config/lara.php `error_http_status`, so
 * LaraException::resolveStatus threw InvalidArgumentException and every real
 * captcha rejection surfaced as an un-enveloped 500 instead of the canonical
 * 428/401 envelope. This test drives both branches through the HTTP kernel.
 */
final class LoginCaptchaEnvelopeTest extends TestCase
{
    public function test_captcha_required_after_threshold_returns_428_envelope(): void
    {
        Cache::flush();
        // Seed the (Email + IP) failure counter above the configured threshold.
        $threshold = (int) config('lara.login_captcha.required_after_failed_attempts', 2);
        $ip = '127.0.0.1';
        $email = 'noone@example.test';
        $key = 'auth.login.failures:' . sha1(strtolower($email) . '|' . $ip);
        Cache::put($key, $threshold, 900);

        $res = $this->postJson(
            '/Api/Auth/Login',
            ['Email' => $email, 'Password' => 'whatever'],
            ['X-Request-Id' => 'test-captcha-required'],
        );

        $res->assertStatus(428);
        $json = $res->json();
        $this->assertSame(false, $json['Status']['IsSuccess'] ?? true);
        $this->assertSame('LoginCaptchaRequired', $json['Attributes']['Error']['ErrorCode'] ?? null);
        $this->assertSame([], $json['Results']);
    }

    public function test_invalid_captcha_answer_returns_401_envelope(): void
    {
        Cache::flush();
        $challenge = LoginCaptcha::issue();

        $res = $this->postJson(
            '/Api/Auth/Login',
            [
                'Email' => 'noone@example.test',
                'Password' => 'whatever',
                'CaptchaChallengeId' => $challenge['ChallengeId'],
                'CaptchaAnswer' => 'not-the-answer',
            ],
            ['X-Request-Id' => 'test-captcha-invalid'],
        );

        $res->assertStatus(401);
        $json = $res->json();
        $this->assertSame('LoginCaptchaInvalid', $json['Attributes']['Error']['ErrorCode'] ?? null);
        $this->assertSame([], $json['Results']);
    }
}
