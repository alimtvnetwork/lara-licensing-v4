<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Exceptions\LaraException;
use App\Exceptions\AuthException;
use App\Exceptions\ValidationException;
use App\Exceptions\RateLimitException;
use App\Exceptions\NotFoundException;
use App\Exceptions\DomainConflictException;
use App\Exceptions\InternalException;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * v0.297.0. Rate limit for the unauthenticated auth surface.
 *
 * Root cause this middleware exists: routes `/Api/Auth/Login`,
 * `/Api/Auth/Captcha`, `/Api/Auth/ForgotPassword`, `/Api/Auth/ResetPassword`,
 * and `/Api/Auth/Register` shipped with zero throttle, so credential stuffing,
 * CAPTCHA-oracle probing, and mail-flood enumeration against v0.296.0's
 * PasswordResetMail were all unbounded. `RateLimited` (429) was already
 * registered in config/lara.php but never emitted.
 *
 * Contract: pass the bucket name as middleware parameter (e.g.
 * `rate.auth:login`). Bucket config lives in `lara.auth_rate_limits`. Key is
 * `<bucket>|ip=<ip>|email=<lower>` when the request carries an Email field so
 * per-account attempts and per-IP attempts are both bounded (whichever hits
 * first wins). Emits `Retry-After` header and canonical envelope.
 */
final class RateLimitAuthMiddleware
{
    public function handle(Request $request, Closure $next, string $bucket): Response
    {
        $config = (array) config('lara.auth_rate_limits.' . $bucket, []);
        $max = (int) ($config['max_attempts'] ?? 0);
        $decay = (int) ($config['decay_seconds'] ?? 0);
        if ($max <= 0 || $decay <= 0) {
            return $next($request);
        }
        foreach ($this->keys($request, $bucket) as $key) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                $retryAfter = RateLimiter::availableIn($key);
                Log::info('auth.rate_limit.blocked', ['Bucket' => $bucket, 'Key' => $key, 'RetryAfter' => $retryAfter]);
                throw RateLimitException::rateLimited(
                    'Too many attempts. Try again later.',
                    [['Field' => 'Bucket', 'Rule' => 'RateLimited', 'Value' => $bucket]],
                    headers: ['Retry-After' => (string) $retryAfter],
                );
            }
            RateLimiter::hit($key, $decay);
        }

        return $next($request);
    }

    /** @return array<int,string> */
    private function keys(Request $request, string $bucket): array
    {
        $ip = (string) ($request->ip() ?? '0.0.0.0');
        $keys = ['auth:' . $bucket . '|ip=' . $ip];
        $email = strtolower(trim((string) $request->input('Email', '')));
        if ($email !== '') {
            $keys[] = 'auth:' . $bucket . '|email=' . hash('sha256', $email);
        }

        return $keys;
    }
}
