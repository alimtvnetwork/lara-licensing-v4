<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuthSession;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Ramsey\Uuid\Uuid;

/**
 * Plan 06 step 43 (login substrate). AuthSessions write path.
 *
 * Owns the lifecycle of Root `AuthSessions` rows for real user logins
 * (Kind = Normal). Impersonation rows are opened by ImpersonationService
 * (Plan 06 step 43 wire-in) and reuse this service's `close()` for the
 * final EndedAt/RevokeReason stamp.
 *
 * Contracts locked here:
 *  - Every Normal session row is created inside a transaction so a
 *    Sanctum token cannot exist without a matching AuthSession row.
 *  - SessionId is a UUID v4 minted here; it is written verbatim into the
 *    personal_access_tokens.name column by LoginController so the token
 *    -> session pointer is stable (spec/21-app/31-auth-session-family.md).
 *  - ExpiresAt is derived from `config('lara.normal_session_ttl_minutes')`;
 *    Sanctum's own token expiration is disabled so this is single truth.
 *  - Close() is idempotent: closing an already-closed row is a no-op that
 *    logs `auth.session.close_noop` at info level.
 */
final class AuthSessionService
{
    public const REVOKE_OPERATOR_LOGOUT = 'OperatorEnded';
    public const REVOKE_TIMEOUT = 'Timeout';
    public const REVOKE_ADMIN_FORCED = 'AdminForced';

    public function openNormal(User $user, bool $rememberMe = false): AuthSession
    {
        $ttl = $rememberMe
            ? (int) config('lara.remember_me_ttl_minutes', 43200)
            : (int) config('lara.normal_session_ttl_minutes', 480);
        $sessionId = Uuid::uuid4()->toString();
        $now = Carbon::now();

        return DB::connection('root')->transaction(function () use ($user, $sessionId, $now, $ttl, $rememberMe): AuthSession {
            $row = new AuthSession();
            $row->SessionId = $sessionId;
            $row->UserId = (int) $user->getKey();
            $row->Kind = AuthSession::KIND_NORMAL;
            $row->ImpersonatorUserId = null;
            $row->ParentSessionId = null;
            $row->CreatedAt = $now;
            $row->ExpiresAt = $now->copy()->addMinutes($ttl);
            $row->EndedAt = null;
            $row->RevokeReason = null;
            $row->save();
            Log::info('auth.session.open', [
                'SessionId' => $sessionId,
                'UserId' => (int) $user->getKey(),
                'Kind' => AuthSession::KIND_NORMAL,
                'RememberMe' => $rememberMe,
                'TtlMinutes' => $ttl,
            ]);

            return $row;
        });
    }

    public function close(string $sessionId, string $revokeReason): void
    {
        $session = AuthSession::query()->where('SessionId', $sessionId)->first();
        if ($session === null) {
            Log::warning('auth.session.close_missing', ['SessionId' => $sessionId]);

            return;
        }
        if ($session->EndedAt !== null) {
            Log::info('auth.session.close_noop', ['SessionId' => $sessionId]);

            return;
        }
        $session->EndedAt = Carbon::now();
        $session->RevokeReason = $this->normalizeReason($revokeReason);
        $session->save();
        $this->revokeTokensForSession($sessionId);
        Log::info('auth.session.close', ['SessionId' => $sessionId, 'RevokeReason' => $session->RevokeReason]);
    }

    /**
     * Plan 06 step 45. Defence-in-depth: delete every Sanctum PAT whose
     * `name` column is the SessionId. Called from `close()`, from the
     * impersonation `closePair` path, and from the timeout sweep so a
     * bearer paired to a closed session cannot survive to its natural
     * `expires_at`. Silent no-op when zero rows match.
     */
    public function revokeTokensForSession(string $sessionId): int
    {
        $deleted = DB::connection('root')
            ->table('personal_access_tokens')
            ->where('name', $sessionId)
            ->delete();
        if ($deleted > 0) {
            Log::info('auth.session.tokens_revoked', ['SessionId' => $sessionId, 'DeletedTokens' => $deleted]);
        }

        return $deleted;
    }

    private function normalizeReason(string $reason): string
    {
        $reasons = (array) config('lara.impersonation_end_reasons', []);

        return in_array($reason, $reasons, true) ? $reason : self::REVOKE_OPERATOR_LOGOUT;
    }


    public function findActive(string $sessionId): ?AuthSession
    {
        $row = AuthSession::query()->where('SessionId', $sessionId)->first();
        if ($row === null || ! $row->isActive()) {
            return null;
        }

        return $row;
    }
}
