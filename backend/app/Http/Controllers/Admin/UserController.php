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
use App\Http\Requests\Admin\ImpersonateBeginRequest;
use App\Http\Requests\Admin\ImpersonateEndRequest;
use App\Http\Requests\Admin\ImpersonateForceEndRequest;
use App\Http\Requests\Admin\UserAssignRoleRequest;
use App\Http\Requests\Admin\UserIndexRequest;
use App\Http\Requests\Admin\UserStoreRequest;
use App\Http\Requests\Admin\UserUpdateRequest;
use App\Models\Reseller;
use App\Models\User;
use App\Models\UserRole;
use App\Support\ApiEnvelope;
use App\Support\EntityHasher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

/**
 * Plan 06 step 34. Admin surface for Root `Users` and `UserRoles`.
 *
 * Endpoints implemented here (spec/21-app/19-user-management.md):
 *   GET    /Api/Admin/Users                      list
 *   POST   /Api/Admin/Users                      create
 *   GET    /Api/Admin/Users/{UserId}             show
 *   PATCH  /Api/Admin/Users/{UserId}             update  (If-Match)
 *   GET    /Api/Admin/Users/{UserId}/Roles       list roles
 *   POST   /Api/Admin/Users/{UserId}/Roles       assign role
 *   DELETE /Api/Admin/Users/{UserId}/Roles/{Role} revoke role
 *
 * `POST /Users/{UserId}/Impersonate` and `POST /Impersonation/End`
 * (spec 46/47) are wired to `App\Services\ImpersonationService`.
 * Both Root-scoped and shard-scoped targets are supported as of
 * v0.231.0: Root writes the `ImpersonationIndex` row (spec 46 §4.3.5
 * unique gate for AC-IMP-004/011) and the target's home DB (Root or
 * reseller shard) writes the `AuthSessions` row via an ordered saga
 * with a compensating shard delete on Root commit failure.
 * Idempotency-Key replay (spec 47 §6) and the timeout sweep job
 * (spec 47 §4) are tracked in `spec/21-app/98-remaining-work.md`.

 */
final class UserController
{
    private const ROOT_CONNECTION = 'root';
    private const USERS_TABLE = 'Users';
    private const USER_ROLES_TABLE = 'UserRoles';
    private const ROLES_TABLE = 'Roles';
    private const UNIQUE_VIOLATION_SQLSTATE = '23505';

        /**
     * @OA\Get(
     *     path="/api/placeholder",
     *     summary="UserController index",
     *     tags={"UserController"},
     *     @OA\Response(response=200, description="Successful operation")
     * )
     */
public function index(UserIndexRequest $request): JsonResponse
    {
        $limit = $request->limit();
        $rows = User::query()
            ->orderBy('UserId')
            ->limit($limit)
            ->get();
        $projected = $rows->map(fn (User $u): array => $this->project($u))->all();

        return ApiEnvelope::success($projected, $this->requestId($request));
    }

        /**
     * @OA\Post(
     *     path="/api/placeholder",
     *     summary="UserController store",
     *     tags={"UserController"},
     *     @OA\Response(response=200, description="Successful operation")
     * )
     */
public function store(UserStoreRequest $request): JsonResponse
    {
        $payload = $request->payload();
        $this->requireTenantValidOrNull($payload['TenantId']);
        $data = [
            'Email' => $payload['Email'],
            'PasswordHash' => Hash::make($payload['Password']),
            'TenantId' => $payload['TenantId'],
            'IsActive' => $payload['IsActive'],
        ];
        $user = $this->insertUserOrConflict($data);

        return ApiEnvelope::success(
            results: [$this->project($user)],
            requestId: $this->requestId($request),
            httpCode: 201,
            message: 'Created',
        );
    }

        /**
     * @OA\Get(
     *     path="/api/placeholder",
     *     summary="UserController show",
     *     tags={"UserController"},
     *     @OA\Response(response=200, description="Successful operation")
     * )
     */
public function show(Request $request, int $userId): JsonResponse
    {
        $user = $this->requireUser($userId);

        return ApiEnvelope::success([$this->project($user)], $this->requestId($request));
    }

        /**
     * @OA\Patch(
     *     path="/api/placeholder",
     *     summary="UserController update",
     *     tags={"UserController"},
     *     @OA\Response(response=200, description="Successful operation")
     * )
     */
public function update(UserUpdateRequest $request, int $userId): JsonResponse
    {
        $patch = $request->patch();
        $user = DB::connection(self::ROOT_CONNECTION)->transaction(
            fn (): User => $this->applyUpdate($request, $userId, $patch)
        );

        return ApiEnvelope::success([$this->project($user)], $this->requestId($request));
    }

        /**
     * @OA\Get(
     *     path="/api/placeholder",
     *     summary="UserController listRoles",
     *     tags={"UserController"},
     *     @OA\Response(response=200, description="Successful operation")
     * )
     */
public function listRoles(Request $request, int $userId): JsonResponse
    {
        $this->requireUser($userId);
        $rows = DB::connection(self::ROOT_CONNECTION)
            ->table(self::USER_ROLES_TABLE . ' as ur')
            ->join(self::ROLES_TABLE . ' as r', 'ur.RoleId', '=', 'r.RoleId')
            ->where('ur.UserId', $userId)
            ->orderBy('r.RoleName')
            ->get(['r.RoleName', 'ur.CreatedAt']);

        return ApiEnvelope::success($this->projectRoles($rows), $this->requestId($request));
    }

        /**
     * @OA\Post(
     *     path="/api/placeholder",
     *     summary="UserController assignRole",
     *     tags={"UserController"},
     *     @OA\Response(response=200, description="Successful operation")
     * )
     */
public function assignRole(UserAssignRoleRequest $request, int $userId): JsonResponse
    {
        $roleName = $request->roleName();
        $this->requireKnownRole($roleName);
        $this->requireUser($userId);
        $roleId = $this->requireRoleId($roleName);
        $this->insertRoleOrConflict($userId, $roleId, $roleName);
        $this->auditRole($request, $userId, $roleName, 'RoleGranted');

        return ApiEnvelope::success(
            results: [['UserId' => $userId, 'RoleName' => $roleName]],
            requestId: $this->requestId($request),
            httpCode: 201,
            message: 'Created',
        );
    }

        /**
     * @OA\Delete(
     *     path="/api/placeholder",
     *     summary="UserController revokeRole",
     *     tags={"UserController"},
     *     @OA\Response(response=200, description="Successful operation")
     * )
     */
public function revokeRole(Request $request, int $userId, string $roleName): JsonResponse
    {
        $this->requireUser($userId);
        $this->requireKnownRole($roleName);
        $this->assertNotLastAdmin($userId, $roleName);
        $roleId = $this->requireRoleId($roleName);
        $deleted = DB::connection(self::ROOT_CONNECTION)
            ->table(self::USER_ROLES_TABLE)
            ->where('UserId', $userId)
            ->where('RoleId', $roleId)
            ->delete();
        if ($deleted === 0) {
            throw NotFoundException::custom('ResourceRoleNotAssigned', 'Role is not assigned to this user.', [['Field' => 'Role', 'Rule' => 'NotAssigned', 'Value' => $roleName]]);
        }
        $this->auditRole($request, $userId, $roleName, 'RoleRevoked');

        return ApiEnvelope::success([['UserId' => $userId, 'RoleName' => $roleName]], $this->requestId($request));
    }

        /**
     * @OA\Post(
     *     path="/api/placeholder",
     *     summary="UserController impersonate",
     *     tags={"UserController"},
     *     @OA\Response(response=200, description="Successful operation")
     * )
     */
public function impersonate(ImpersonateBeginRequest $request, int $userId): JsonResponse
    {
        $reason = $request->reason();
        $operator = $this->requireAuthenticated($request);
        $parent = $this->requireParentSession($request, $operator);
        $target = $this->requireUser($userId);
        /** @var \App\Services\ImpersonationService $svc */
        $svc = app(\App\Services\ImpersonationService::class);
        $payload = $svc->begin($operator, $parent, $target, $reason, $this->requestId($request));

        return ApiEnvelope::success(
            results: [$payload],
            requestId: $this->requestId($request),
            httpCode: 201,
            message: 'Created',
        );
    }

        /**
     * @OA\Post(
     *     path="/api/placeholder",
     *     summary="UserController endImpersonation",
     *     tags={"UserController"},
     *     @OA\Response(response=200, description="Successful operation")
     * )
     */
public function endImpersonation(ImpersonateEndRequest $request): JsonResponse
    {
        $endReason = $request->endReason();
        $caller = $this->requireAuthenticated($request);
        $session = $this->requireActiveImpersonationForCaller($request, $caller);
        /** @var \App\Services\ImpersonationService $svc */
        $svc = app(\App\Services\ImpersonationService::class);
        $payload = $svc->end($caller, $session, $endReason, $this->requestId($request));

        return ApiEnvelope::success([$payload], $this->requestId($request));
    }

    /**
     * Plan 06 step 43 (forceEnd wire-in). Admin-only termination of an
     * arbitrary active impersonation session by `SessionId` (spec 47 §2).
     * The route sits under `Api/Admin/Impersonation/*` so the Admin RBAC
     * gate and `Idempotency-Key` middleware already apply.
     */
        /**
     * @OA\Post(
     *     path="/api/placeholder",
     *     summary="UserController forceEndImpersonation",
     *     tags={"UserController"},
     *     @OA\Response(response=200, description="Successful operation")
     * )
     */
public function forceEndImpersonation(ImpersonateForceEndRequest $request): JsonResponse
    {
        $sessionId = $request->sessionId();
        $admin = $this->requireAuthenticated($request);
        /** @var \App\Services\ImpersonationService $svc */
        $svc = app(\App\Services\ImpersonationService::class);
        $payload = $svc->forceEnd($admin, $sessionId, $this->requestId($request));

        return ApiEnvelope::success([$payload], $this->requestId($request));
    }




    private function requireAuthenticated(Request $request): User
    {
        $u = $request->user();
        if (($u instanceof User) === false) {
            throw AuthException::unauthorized( 'Authenticated user required.', [['Field' => 'Authorization', 'Rule' => 'Missing']]);
        }

        return $u;
    }

    private function requireParentSession(Request $request, User $operator): \App\Models\AuthSession
    {
        $token = $request->user()?->currentAccessToken();
        $sessionId = $token !== null ? (string) $token->name : '';
        if ($sessionId === '') {
            throw DomainConflictException::custom('ImpersonationParentSessionInvalid', 'No parent Normal session found on caller token.', [['Field' => 'ParentSessionId', 'Rule' => 'MissingOnToken']]);
        }
        /** @var \App\Services\AuthSessionService $svc */
        $svc = app(\App\Services\AuthSessionService::class);
        $active = $svc->findActive($sessionId);
        if ($active === null) {
            throw DomainConflictException::custom('ImpersonationParentSessionInvalid', 'Parent session is expired or ended.', [['Field' => 'ParentSessionId', 'Rule' => 'NotActive', 'Value' => $sessionId]]);
        }

        return $active;
    }


    private function requireActiveImpersonationForCaller(Request $request, User $caller): \App\Models\AuthSession
    {
        $token = $request->user()?->currentAccessToken();
        $sessionIdFromToken = $token !== null ? (string) $token->name : '';
        $indexRow = \App\Models\ImpersonationIndex::query()
            ->whereNull('EndedAt')
            ->where(function ($q) use ($caller, $sessionIdFromToken): void {
                $q->where('ImpersonatorUserId', (int) $caller->getKey());
                if ($sessionIdFromToken !== '') {
                    $q->orWhere('SessionId', $sessionIdFromToken);
                }
            })
            ->orderByDesc('StartedAt')
            ->first();
        if ($indexRow === null) {
            throw NotFoundException::notFound('UserNotFound', 'No active impersonation session for this caller.', [['Field' => 'SessionId', 'Rule' => 'NotActive']]);
        }
        $stub = new \App\Models\AuthSession();
        $stub->SessionId = (string) $indexRow->SessionId;

        return $stub;
    }




    private function requireTenantValidOrNull(?int $tenantId): void
    {
        if ($tenantId === null) {
            return;
        }
        $result = \App\Support\DbQuery::run(
            fn() => Reseller::query()->whereKey($tenantId)->exists(),
            "Validating Reseller tenantId: {$tenantId}"
        );
        if ($result->isFailed) {
            throw NotFoundException::notFound('ResellerNotFound',
                'TenantId does not reference an existing Reseller.',
                [['Field' => 'TenantId', 'Rule' => 'NotFound', 'Value' => (string) $tenantId]],
            );
        }
    }

    /**
     * @param array{Email:string, PasswordHash:string, TenantId:?int, IsActive:bool} $data
     */
    private function insertUserOrConflict(array $data): User
    {
        try {
            $user = new User();
            $user->Email = $data['Email'];
            $user->PasswordHash = $data['PasswordHash'];
            $user->TenantId = $data['TenantId'];
            $user->IsActive = $data['IsActive'];
            $user->save();

            return $user;
        } catch (\Illuminate\Database\QueryException $e) {
            if ($e->getCode() === self::UNIQUE_VIOLATION_SQLSTATE) {
                throw DomainConflictException::custom('UserConflict', 'A user with this Email already exists.', [['Field' => 'Email', 'Rule' => 'Unique']], $e);
            }
            throw $e;
        }
    }

    /**
     * @param array<string, mixed> $patch
     */
    private function applyUpdate(Request $request, int $userId, array $patch): User
    {
        $user = $this->requireUserForUpdate($userId);
        $this->enforceIfMatch($request, $user);
        if (array_key_exists('Email', $patch)) {
            $user->Email = (string) $patch['Email'];
        }
        if (array_key_exists('IsActive', $patch)) {
            $user->IsActive = (bool) $patch['IsActive'];
        }
        if (array_key_exists('TenantId', $patch)) {
            $this->requireTenantValidOrNull($patch['TenantId'] === null ? null : (int) $patch['TenantId']);
            $user->TenantId = $patch['TenantId'] === null ? null : (int) $patch['TenantId'];
        }
        $user->UpdatedAt = Carbon::now();
        $user->save();

        return $user;
    }

    private function requireUser(int $userId): User
    {
        $u = User::query()->whereKey($userId)->first();
        if ($u === null) {
            throw NotFoundException::notFound('UserNotFound', 'User not found.', [['Field' => 'UserId', 'Rule' => 'NotFound', 'Value' => (string) $userId]]);
        }

        return $u;
    }

    private function requireUserForUpdate(int $userId): User
    {
        $u = User::query()->whereKey($userId)->lockForUpdate()->first();
        if ($u === null) {
            throw NotFoundException::notFound('UserNotFound', 'User not found.', [['Field' => 'UserId', 'Rule' => 'NotFound', 'Value' => (string) $userId]]);
        }

        return $u;
    }

    private function enforceIfMatch(Request $request, User $user): void
    {
        $header = (string) $request->attributes->get('lara.if_match', '');
        $current = EntityHasher::hashSingleResource($this->project($user), $this->requestId($request));
        if (EntityHasher::ifMatchMatches($header, $current) === false) {
            throw ValidationException::custom('PreconditionFailed', 'The user was modified since it was last read. Re-fetch and retry.', [['Field' => 'If-Match', 'Rule' => 'ETagMismatch']]);
        }
    }

    private function requireKnownRole(string $roleName): void
    {
        $result = \App\Support\DbQuery::run(
            fn() => DB::connection(self::ROOT_CONNECTION)
                ->table(self::ROLES_TABLE)
                ->where('RoleName', $roleName)
                ->exists(),
            "Checking RoleName: {$roleName}"
        );
        if ($result->isFailed) {
            throw ValidationException::custom('ValidationInvalidRole', 'Unknown RoleName.', [['Field' => 'RoleName', 'Rule' => 'ClosedSet', 'Value' => $roleName]]);
        }
    }

    private function requireRoleId(string $roleName): int
    {
        $id = DB::connection(self::ROOT_CONNECTION)
            ->table(self::ROLES_TABLE)
            ->where('RoleName', $roleName)
            ->value('RoleId');
        if ($id === null) {
            throw ValidationException::custom('ValidationInvalidRole', 'Unknown RoleName.', [['Field' => 'RoleName', 'Rule' => 'ClosedSet', 'Value' => $roleName]]);
        }

        return (int) $id;
    }

    private function insertRoleOrConflict(int $userId, int $roleId, string $roleName): void
    {
        try {
            DB::connection(self::ROOT_CONNECTION)->table(self::USER_ROLES_TABLE)->insert([
                'UserId' => $userId,
                'RoleId' => $roleId,
                'CreatedAt' => Carbon::now(),
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
            if ($e->getCode() === self::UNIQUE_VIOLATION_SQLSTATE) {
                throw DomainConflictException::custom('ResourceRoleAlreadyAssigned', 'Role already assigned to this user.', [['Field' => 'RoleName', 'Rule' => 'AlreadyAssigned', 'Value' => $roleName]], $e);
            }
            throw $e;
        }
    }

    /**
     * Spec 19 §last-admin: refuse to remove the final Admin role globally.
     */
    private function assertNotLastAdmin(int $userId, string $roleName): void
    {
        if ($roleName !== 'Admin') {
            return;
        }
        $adminCount = (int) DB::connection(self::ROOT_CONNECTION)
            ->table(self::USER_ROLES_TABLE . ' as ur')
            ->join(self::ROLES_TABLE . ' as r', 'ur.RoleId', '=', 'r.RoleId')
            ->where('r.RoleName', 'Admin')
            ->count();
        if ($adminCount <= 1) {
            throw DomainConflictException::custom('AuthzLastAdminProtected', 'Cannot revoke the last Admin role.', [['Field' => 'RoleName', 'Rule' => 'LastAdmin', 'Value' => $roleName]]);
        }
    }

    private function auditRole(Request $request, int $userId, string $roleName, string $action): void
    {
        $payload = ['RoleName' => $roleName, 'TargetUserId' => $userId];
        \App\Support\AuditWriter::write($request, $action, 'Users', $userId, $payload);
        Log::info('user.role.' . strtolower($action), [
            'action' => $action,
            'userId' => $userId,
            'roleName' => $roleName,
            'actorUserId' => $this->actorId($request),
            'requestId' => $this->requestId($request),
        ]);
    }

    private function actorId(Request $request): ?int
    {
        $id = $request->user()?->getAuthIdentifier();

        return is_numeric($id) ? (int) $id : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function project(User $u): array
    {
        // Plan 10 step 4 (wave 2): delegate wire shape to UserResource.
        // PasswordHash stays suppressed via the model's $hidden list.
        return (new \App\Http\Resources\UserResource($u))->resolve();
    }

    /**
     * @param \Illuminate\Support\Collection<int, object> $rows
     * @return array<int, array<string, mixed>>
     */
    private function projectRoles(\Illuminate\Support\Collection $rows): array
    {
        return $rows->map(fn (object $r): array => [
            'RoleName' => (string) $r->RoleName,
            'CreatedAt' => $this->isoOrEmpty($r->CreatedAt),
        ])->all();
    }

    private function isoOrEmpty(mixed $v): string
    {
        if ($v === null) {
            return '';
        }
        if ($v instanceof \DateTimeInterface) {
            return $v->format('Y-m-d\TH:i:s\Z');
        }

        return (string) $v;
    }

    private function requestId(Request $request): string
    {
        return (string) ($request->headers->get('X-Request-Id') ?? '');
    }
}
