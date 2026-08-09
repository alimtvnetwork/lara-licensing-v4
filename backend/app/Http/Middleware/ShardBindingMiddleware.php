<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Exceptions\InternalException;


use App\Db\ShardResolver;
use App\Exceptions\LaraException;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * ShardBindingMiddleware — resolves the authenticated user's Reseller
 * (`Users.TenantId`) to a `ResellerSlug` on Root and binds the shard
 * connection (alias `shard`) via `ShardResolver` before controllers run.
 *
 * Spec: spec/23-app-db/10-reseller-shard-split-db.md §Routing Rules
 * (every reseller-scoped request runs against its own shard). Plan 06
 * step 27 pairs this with `require.role:Reseller`.
 *
 * Errors:
 *   - Unauthenticated -> 401 AuthInvalidCredentials.
 *   - User has no TenantId (Root-only staff) hitting reseller route
 *     -> 403 AuthForbidden with Details.RequiredScope=ResellerTenant.
 *   - Tenant row missing / no shard route -> 404 ResellerNotFound.
 */
final class ShardBindingMiddleware
{
    private const ROOT = 'root';
    private const ERR_UNAUTH = 'AuthInvalidCredentials';
    private const ERR_FORBID = 'AuthForbidden';
    private const ERR_NOTFOUND = 'ResellerNotFound';

    public function __construct(private readonly ShardResolver $resolver) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user === null) {
            throw InternalException::custom(self::ERR_UNAUTH, 'Authenticated user required for shard binding.');
        }
        [$resellerId, $slug] = $this->resolveReseller($user);
        $this->resolver->bind($slug);
        $request->attributes->set('ResellerId', $resellerId);
        $request->attributes->set('ResellerSlug', $slug);

        return $next($request);
    }

    /**
     * @return array{0:int,1:string}
     */
    private function resolveReseller(object $user): array
    {
        $tenantId = $this->readTenantId($user);
        $row = DB::connection(self::ROOT)
            ->table('Resellers')
            ->where('ResellerId', $tenantId)
            ->value('ResellerSlug');
        if ($row === null) {
            throw InternalException::custom(self::ERR_NOTFOUND, 'No Reseller row for user tenant.', [
                ['Field' => 'TenantId', 'Rule' => 'Exists', 'Value' => (string) $tenantId],
            ]);
        }

        return [$tenantId, (string) $row];
    }

    private function readTenantId(object $user): int
    {
        $tenantId = $user->TenantId ?? null;
        if ($tenantId === null || (int) $tenantId <= 0) {
            throw InternalException::custom(self::ERR_FORBID, 'User is not scoped to a Reseller tenant.', [
                ['Field' => 'RequiredScope', 'Rule' => 'Equals', 'Value' => 'ResellerTenant'],
            ]);
        }

        return (int) $tenantId;
    }
}
