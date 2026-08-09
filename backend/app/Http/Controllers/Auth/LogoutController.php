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
use App\Services\AuthSessionService;
use App\Support\ApiEnvelope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\PersonalAccessToken as SanctumToken;

/**
 * Plan 06 step 43 (login substrate). POST /Api/Auth/Logout.
 *
 * Revokes the bearer token and closes the paired AuthSessions row with
 * RevokeReason = OperatorEnded. Requires `auth:sanctum` on the route.
 */
final class LogoutController
{
    public function __construct(private readonly AuthSessionService $sessions)
    {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        $requestId = (string) $request->attributes->get('X-Request-Id', '');
        if ($user === null) {
            throw AuthException::unauthorized( 'No authenticated user.', []);
        }
        /** @var SanctumToken|null $token */
        $token = $user->currentAccessToken();
        $sessionId = $token !== null ? (string) $token->name : '';
        if ($token !== null) {
            $token->delete();
        }
        if ($sessionId !== '') {
            $this->sessions->close($sessionId, AuthSessionService::REVOKE_OPERATOR_LOGOUT);
        }
        Log::info('auth.logout', ['UserId' => (int) $user->getKey(), 'SessionId' => $sessionId]);

        return ApiEnvelope::success([['SessionId' => $sessionId]], $requestId);
    }
}
