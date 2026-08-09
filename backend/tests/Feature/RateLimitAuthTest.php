<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * v0.297.0 regression guard for RateLimitAuthMiddleware.
 *
 * Root cause guarded: unthrottled `/Api/Auth/ForgotPassword` and `/Api/Auth/Login`
 * allowed unbounded credential stuffing and password-reset mail flooding after
 * v0.296.0 wired real mail delivery. Locks: exceeding the bucket cap emits
 * canonical `RateLimited` (429) envelope with `Retry-After`, and per-email
 * key blocks even when IP rotates within the same window.
 */
final class RateLimitAuthTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        RateLimiter::clear('auth:forgot|ip=127.0.0.1');
        RateLimiter::clear('auth:login|ip=127.0.0.1');
    }

    public function test_forgot_password_returns_429_with_retry_after_after_bucket_exhausted(): void
    {
        $max = (int) config('lara.auth_rate_limits.forgot.max_attempts');
        $this->assertGreaterThan(0, $max);
        for ($i = 0; $i < $max; $i++) {
            $this->postJson('/Api/Auth/ForgotPassword', ['Email' => 'nobody@example.test'])
                ->assertStatus(200);
        }
        $res = $this->postJson('/Api/Auth/ForgotPassword', ['Email' => 'nobody@example.test']);
        $res->assertStatus(429);
        $this->assertNotEmpty($res->headers->get('Retry-After'));
        $json = $res->json();
        $this->assertSame('RateLimited', $json['Attributes']['Error']['ErrorCode'] ?? null);
        $this->assertSame([], $json['Results']);
    }

    public function test_login_bucket_blocks_email_across_rotating_ips(): void
    {
        $max = (int) config('lara.auth_rate_limits.login.max_attempts');
        $email = 'victim@example.test';
        for ($i = 0; $i < $max; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.' . ($i + 1)])
                ->postJson('/Api/Auth/Login', ['Email' => $email, 'Password' => 'x']);
        }
        $res = $this->withServerVariables(['REMOTE_ADDR' => '10.9.9.9'])
            ->postJson('/Api/Auth/Login', ['Email' => $email, 'Password' => 'x']);
        $res->assertStatus(429);
        $this->assertSame('RateLimited', $res->json('Attributes.Error.ErrorCode'));
    }
}
