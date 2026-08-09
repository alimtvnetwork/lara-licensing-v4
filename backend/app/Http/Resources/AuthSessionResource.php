<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Http\Resources\Concerns\FormatsPascalCase;
use App\Models\AuthSession;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Plan 10 step 4. AuthSessions wire shape. Never emits raw refresh
 * tokens; only session metadata for the sessions panel and revocation UI
 * (see spec/21-app/46-impersonation.md §3).
 *
 * @mixin AuthSession
 */
final class AuthSessionResource extends JsonResource
{
    use FormatsPascalCase;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var AuthSession $s */
        $s = $this->resource;

        return [
            'SessionId' => (string) $s->getAttribute('SessionId'),
            'UserId' => (int) $s->getAttribute('UserId'),
            'Kind' => (string) $s->getAttribute('Kind'),
            'ImpersonatorUserId' => $s->getAttribute('ImpersonatorUserId') === null
                ? null
                : (int) $s->getAttribute('ImpersonatorUserId'),
            'ParentSessionId' => $s->getAttribute('ParentSessionId'),
            'CreatedAt' => optional($s->getAttribute('CreatedAt'))->toIso8601String(),
            'ExpiresAt' => optional($s->getAttribute('ExpiresAt'))->toIso8601String(),
            'EndedAt' => optional($s->getAttribute('EndedAt'))->toIso8601String(),
            'RevokeReason' => $s->getAttribute('RevokeReason'),
            'IsActive' => $s->isActive(),
        ];
    }
}

