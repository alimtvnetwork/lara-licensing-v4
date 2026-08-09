<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\AuthSession;
use App\Support\RequestContext;
use Illuminate\Http\Request;

/**
 * Plan 06 (spec 47 §5, AC-IMP-007). Merges impersonation lineage into
 * every audit payload emitted while the caller is authenticated with a
 * Sanctum token whose paired `AuthSessions` row has `Kind = Impersonation`.
 *
 * Root cause this helper exists:
 *   Individual handlers were writing `AuditLogs.PayloadJson` without
 *   propagating the operator identity, so audits performed under an
 *   impersonation token dropped `ImpersonatorUserId` and violated
 *   spec 46 AC-IMP-007. This centralises the merge so every writer
 *   gets the invariant for free.
 *
 * Behaviour: pure read. Returns a NEW payload with `ImpersonatorUserId`
 * and `ImpersonationSessionId` merged when applicable, otherwise the
 * original payload. Never throws: audit enrichment MUST NOT fail the
 * underlying mutation.
 */
final class AuditEnrichment
{
    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public static function enrich(Request $request, array $payload): array
    {
        $payload = array_merge(RequestContext::extract($request), $payload);
        $session = self::resolveSession($request);
        if ($session === null || $session->Kind !== AuthSession::KIND_IMPERSONATION) {
            return $payload;
        }
        $payload['ImpersonatorUserId'] = (int) ($session->ImpersonatorUserId ?? 0);
        $payload['ImpersonationSessionId'] = (string) $session->SessionId;

        return $payload;
    }

    private static function resolveSession(Request $request): ?AuthSession
    {
        $token = $request->user()?->currentAccessToken();
        $sessionId = $token !== null ? (string) $token->name : '';
        if ($sessionId === '') {
            return null;
        }

        return AuthSession::query()->where('SessionId', $sessionId)->first();
    }
}
