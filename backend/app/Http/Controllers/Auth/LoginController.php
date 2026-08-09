<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Exceptions\LaraException;
use App\Exceptions\AuthException;
use App\Exceptions\ValidationException;
use App\Exceptions\RateLimitException;
use App\Exceptions\NotFoundException;
use App\Exceptions\DomainConflictException;
use App\Exceptions\InternalException;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;
use App\Services\AuthSessionService;
use App\Support\ApiEnvelope;
use App\Support\LoginCaptcha;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

/**
 * Plan 06 step 43 (login substrate) + Plan 09 login modernization.
 * POST /Api/Auth/Login.
 *
 * Verifies credentials, opens an AuthSessions.Normal row, mints a Sanctum
 * personal-access token whose `name` column carries the AuthSessions.SessionId
 * UUID. Expiration is enforced by AuthSessions.ExpiresAt, not Sanctum.
 *
 * Modernization additions:
 *   - RememberMe: opt-in longer session TTL (see AuthSessionService).
 *   - CAPTCHA: required after N consecutive failures per (Email + IP);
 *     verified via LoginCaptcha (HMAC-signed, stateless).
 *
 * Failure modes:
 *   AuthInvalidCredentials (401)  unknown email OR wrong password OR IsActive=false
 *   LoginCaptchaRequired    (428)  attempt threshold reached; client must present a captcha
 *   LoginCaptchaInvalid     (401)  captcha signature/expiry/answer wrong
 *
 * We DO NOT differentiate "unknown user" vs "wrong password"; the log line
 * carries the distinction.
 */
final class LoginController
{
    public function __construct(private readonly AuthSessionService $sessions)
    {
    }

    public function __invoke(LoginRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $email = (string) $validated['Email'];
        $password = (string) $validated['Password'];
        $rememberMe = (bool) ($validated['RememberMe'] ?? false);
        $captchaId = (string) ($validated['CaptchaChallengeId'] ?? '');
        $captchaAnswer = (string) ($validated['CaptchaAnswer'] ?? '');
        $requestId = (string) $request->attributes->get('X-Request-Id', '');

        $this->enforceCaptcha($email, $request, $captchaId, $captchaAnswer, $requestId);

        try {
            $user = $this->verifyCredentials($email, $password, $requestId);
        } catch (LaraException $failure) {
            $this->recordFailure($email, $request);
            throw $failure;
        }

        $this->clearFailures($email, $request);
        $session = $this->sessions->openNormal($user, $rememberMe);
        $token = $user->createToken($session->SessionId, ['*'], $session->ExpiresAt)->plainTextToken;

        Log::info('auth.login', [
            'UserId' => (int) $user->getKey(),
            'SessionId' => $session->SessionId,
            'RememberMe' => $rememberMe,
        ]);

        return ApiEnvelope::success([[
            'UserId' => (int) $user->getKey(),
            'Email' => (string) $user->Email,
            'SessionId' => $session->SessionId,
            'ExpiresAt' => $session->ExpiresAt->toIso8601String(),
            'Token' => $token,
            'RememberMe' => $rememberMe,
        ]], $requestId);
    }

    private function enforceCaptcha(string $email, Request $request, string $captchaId, string $captchaAnswer, string $requestId): void
    {
        $threshold = (int) config('lara.login_captcha.required_after_failed_attempts', 2);
        $failures = (int) Cache::get($this->failureKey($email, $request), 0);
        $required = $failures >= $threshold;

        if ($captchaId !== '' || $captchaAnswer !== '') {
            LoginCaptcha::verify($captchaId, $captchaAnswer);
            Log::info('auth.login.captcha_ok', ['RequestId' => $requestId]);

            return;
        }

        if ($required) {
            Log::info('auth.login.captcha_required', ['RequestId' => $requestId, 'Failures' => $failures]);
            throw ValidationException::custom('LoginCaptchaRequired', 'Captcha check required. Request a challenge.', []);
        }
    }

    private function verifyCredentials(string $email, string $password, string $requestId): User
    {
        $user = User::query()->where('Email', $email)->first();
        $ok = $user !== null && (bool) $user->IsActive && Hash::check($password, (string) $user->PasswordHash);
        if ($ok === false) {
            Log::warning('auth.login.rejected', [
                'RequestId' => $requestId,
                'Email' => $email,
                'Reason' => $user === null ? 'UnknownEmail' : (((bool) $user->IsActive) ? 'BadPassword' : 'Inactive'),
            ]);
            throw AuthException::invalidCredentials( 'Invalid email or password.', []);
        }

        return $user;
    }

    private function recordFailure(string $email, Request $request): void
    {
        $window = (int) config('lara.login_captcha.failure_window_seconds', 900);
        $key = $this->failureKey($email, $request);
        $current = (int) Cache::get($key, 0);
        Cache::put($key, $current + 1, $window);
    }

    private function clearFailures(string $email, Request $request): void
    {
        Cache::forget($this->failureKey($email, $request));
    }

    private function failureKey(string $email, Request $request): string
    {
        $ip = (string) ($request->ip() ?? 'unknown');

        return 'auth.login.failures:' . sha1(strtolower($email) . '|' . $ip);
    }
}
