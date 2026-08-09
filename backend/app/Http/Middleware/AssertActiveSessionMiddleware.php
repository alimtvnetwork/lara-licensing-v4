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
use App\Services\AuthSessionService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\PersonalAccessToken as SanctumToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Plan 06 step 45 (session-lifetime gate).
 *
 * Root cause this middleware exists: Sanctum's `auth:sanctum` guard accepts
 * a bearer token whenever `personal_access_tokens.expires_at` is still in
 * the future, but `AuthSessions.EndedAt` can be stamped before that expiry
 * by `LogoutController`, `ImpersonationTimeoutSweepCommand`, or an admin
 * `AdminForced` end. Without this gate, a token whose paired session was
 * force-closed keeps calling authenticated endpoints until its natural
 * expiry, silently violating spec 46 §4.3 and spec 47 §4.
 *
 * Contract: MUST run after `auth:sanctum`. Reads the SessionId from
 * `currentAccessToken()->name` (LoginController/ImpersonationService both
 * stamp the UUID there) and rejects with `AuthUnauthorized` when the
 * paired AuthSessions row is closed or expired.
 */
final class AssertActiveSessionMiddleware
{
    public function __construct(private readonly AuthSessionService $sessions)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user === null) {
            throw AuthException::unauthorized( 'No authenticated user.', []);
        }
        /** @var SanctumToken|null $token */
        $token = $user->currentAccessToken();
        $sessionId = $token !== null ? (string) $token->name : '';
        if ($sessionId === '') {
            Log::warning('auth.session.gate.token_without_session', ['UserId' => (int) $user->getKey()]);
            throw AuthException::unauthorized( 'Access token has no paired session.', []);
        }
        if ($this->sessions->findActive($sessionId) === null) {
            Log::info('auth.session.gate.rejected', ['UserId' => (int) $user->getKey(), 'SessionId' => $sessionId]);
            throw AuthException::unauthorized( 'Session is closed or expired.', [['Field' => 'SessionId', 'Rule' => 'NotActive', 'Value' => $sessionId]]);
        }
        $request->attributes->set('AuthSessionId', $sessionId);

        return $next($request);
    }
}
