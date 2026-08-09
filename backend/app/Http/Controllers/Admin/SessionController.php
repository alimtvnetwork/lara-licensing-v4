<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Exceptions\LaraException;
use App\Exceptions\AuthException;
use App\Exceptions\ValidationException;
use App\Exceptions\RateLimitException;
use App\Exceptions\NotFoundException;
use App\Exceptions\DomainConflictException;
use App\Exceptions\InternalException;
use App\Http\Requests\Admin\SessionIndexRequest;
use App\Models\AuthSession;
use App\Models\User;
use App\Services\AuthSessionService;
use App\Support\ApiEnvelope;
use App\Support\AuditWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * v0.298.0 (spec 31 + spec 47 §"AdminForced").
 *
 * Admin surface over Root `AuthSessions`. Lists active + recent sessions
 * for a target user and revokes any session by SessionId with
 * RevokeReason=AdminForced. Delegates the state transition to
 * AuthSessionService::close so the token-family invariant (Sanctum PAT
 * deleted alongside the row) stays a single write path.
 *
 * Endpoints:
 *   GET    /Api/Admin/Users/{UserId}/Sessions?Limit=&IncludeEnded=
 *   DELETE /Api/Admin/Sessions/{SessionId}
 */
final class SessionController
{

    public function __construct(private readonly AuthSessionService $sessions) {}

        /**
     * @OA\Get(
     *     path="/api/placeholder",
     *     summary="SessionController index",
     *     tags={"SessionController"},
     *     @OA\Response(response=200, description="Successful operation")
     * )
     */
public function index(SessionIndexRequest $request, int $userId): JsonResponse
    {
        $this->requireUser($userId);
        $limit = $request->limit();
        $includeEnded = $request->includeEnded();
        $q = AuthSession::query()->where('UserId', $userId);
        if ($includeEnded === false) {
            $q->whereNull('EndedAt')->where('ExpiresAt', '>', now());
        }
        $rows = $q->orderByDesc('CreatedAt')->limit($limit)->get();
        $projected = $rows->map(fn (AuthSession $s): array => $this->project($s))->all();
        Log::info('admin.sessions.index', [
            'UserId' => $userId,
            'IncludeEnded' => $includeEnded,
            'Limit' => $limit,
            'Returned' => count($projected),
        ]);

        return ApiEnvelope::success($projected, $this->requestId($request), extraAttributes: [
            'Count' => count($projected),
            'Limit' => $limit,
        ]);
    }

        /**
     * @OA\Delete(
     *     path="/api/placeholder",
     *     summary="SessionController destroy",
     *     tags={"SessionController"},
     *     @OA\Response(response=200, description="Successful operation")
     * )
     */
public function destroy(Request $request, string $sessionId): JsonResponse
    {
        $session = AuthSession::query()->where('SessionId', $sessionId)->first();
        if ($session === null) {
            throw AuthException::sessionNotFound( 'Session not found.', 404);
        }
        if ($session->EndedAt !== null) {
            throw AuthException::custom('AuthSessionAlreadyClosed', 'Session already ended.', 409);
        }
        $this->sessions->close($sessionId, AuthSessionService::REVOKE_ADMIN_FORCED);
        AuditWriter::write($request, 'AuthSessionRevoked', 'AuthSessions', $sessionId, [
            'SessionId' => $sessionId,
            'TargetUserId' => (int) $session->UserId,
            'Kind' => (string) $session->Kind,
            'RevokeReason' => AuthSessionService::REVOKE_ADMIN_FORCED,
        ]);

        return ApiEnvelope::success(
            results: [['SessionId' => $sessionId, 'RevokeReason' => AuthSessionService::REVOKE_ADMIN_FORCED]],
            requestId: $this->requestId($request),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function project(AuthSession $s): array
    {
        return (new \App\Http\Resources\AuthSessionResource($s))->resolve();
    }


    private function requireUser(int $userId): void
    {
        if (! User::query()->whereKey($userId)->exists()) {
            throw NotFoundException::notFound('UserNotFound', 'User not found.', 404);
        }
    }


    private function requestId(Request $request): string
    {
        return (string) ($request->headers->get('X-Request-Id') ?? '');
    }
}
