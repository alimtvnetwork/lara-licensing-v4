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
use App\Policies\HasRolePolicy;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * RequireRoleMiddleware — RBAC gate for HTTP routes.
 *
 * Usage in routes:
 *   Route::middleware(['auth:sanctum', 'require.role:Admin|SuperAdmin'])
 *
 * Emits canonical envelopes via LaraException (AC-ERR-001):
 *   - 401 AuthInvalidCredentials when no authenticated user is bound.
 *   - 403 AuthForbidden when user has none of the required roles.
 *
 * Function bodies capped at 15 lines (coding-guidelines.md).
 */
final class RequireRoleMiddleware
{
    /** Separator used in `require.role:Role1|Role2` route params. */
    private const ROLE_SEP = '|';

    /** Error codes are catalog names, not magic strings — see config/lara.php. */
    private const ERR_UNAUTH = 'AuthInvalidCredentials';
    private const ERR_FORBID = 'AuthForbidden';

    public function __construct(private readonly HasRolePolicy $policy) {}

    public function handle(Request $request, Closure $next, string ...$roleParams): Response
    {
        $userId = $this->resolveUserId($request);
        $required = $this->parseRoles($roleParams);
        if (!$this->policy->hasAnyRole($userId, $required)) {
            Log::warning('rbac.forbidden', [
                'UserId' => $userId,
                'Path' => $request->path(),
                'RequiredRoles' => $required,
            ]);
            throw InternalException::custom(self::ERR_FORBID, 'Caller lacks required role.', [
                ['Field' => 'RequiredRoles', 'Rule' => 'AnyOf', 'Value' => implode(self::ROLE_SEP, $required)],
            ]);
        }

        return $next($request);
    }

    private function resolveUserId(Request $request): string
    {
        $user = $request->user();
        if ($user === null) {
            Log::warning('rbac.unauthenticated', ['Path' => $request->path()]);
            throw InternalException::custom(self::ERR_UNAUTH, 'Authenticated user required for role check.');
        }

        return (string) $user->getAuthIdentifier();
    }

    /**
     * @param list<string> $roleParams
     * @return list<string>
     */
    private function parseRoles(array $roleParams): array
    {
        $flat = [];
        foreach ($roleParams as $param) {
            foreach (explode(self::ROLE_SEP, $param) as $piece) {
                $trim = trim($piece);
                if ($trim !== '') { $flat[] = $trim; }
            }
        }
        if ($flat === []) {
            throw InternalException::serverError('ServerError', 'RequireRoleMiddleware invoked without role parameter.');
        }

        return array_values(array_unique($flat));
    }
}
