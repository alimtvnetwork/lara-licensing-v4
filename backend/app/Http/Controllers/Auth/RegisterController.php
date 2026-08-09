<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Exceptions\AuthException;


use App\Exceptions\LaraException;
use App\Http\Requests\Auth\RegisterRequest;
use App\Models\User;
use App\Services\AuthSessionService;
use App\Support\ApiEnvelope;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

/**
 * Bootstrap registration: POST /Api/Auth/Register.
 *
 * The first successful call creates a Root user and grants the SuperAdmin
 * role. Every subsequent call is rejected with AuthRegistrationClosed
 * (403); further user provisioning must flow through the authenticated
 * Admin surface (POST /Api/Admin/Users, /Api/Admin/Users/{UserId}/Roles).
 *
 * Race safety: the whole flow runs inside a Root transaction with an
 * EXCLUSIVE lock on the "Users" table, so two concurrent requests cannot
 * both observe an empty table and both create a SuperAdmin.
 */
final class RegisterController
{
    private const ROOT_CONNECTION = 'root';
    private const USERS_TABLE = 'Users';
    private const ROLES_TABLE = 'Roles';
    private const USER_ROLES_TABLE = 'UserRoles';
    private const SUPER_ADMIN_ROLE = 'SuperAdmin';

    public function __construct(private readonly AuthSessionService $sessions)
    {
    }

    public function __invoke(RegisterRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $email = (string) $validated['Email'];
        $password = (string) $validated['Password'];
        $requestId = (string) $request->attributes->get('X-Request-Id', '');

        $user = DB::connection(self::ROOT_CONNECTION)->transaction(
            fn (): User => $this->bootstrapSuperAdmin($email, $password, $requestId)
        );

        $session = $this->sessions->openNormal($user);
        $token = $user->createToken($session->SessionId, ['*'], $session->ExpiresAt)->plainTextToken;

        Log::info('auth.register.super_admin_created', [
            'RequestId' => $requestId,
            'UserId' => (int) $user->getKey(),
            'SessionId' => $session->SessionId,
        ]);

        return ApiEnvelope::success(
            results: [[
                'UserId' => (int) $user->getKey(),
                'Email' => (string) $user->Email,
                'SessionId' => $session->SessionId,
                'ExpiresAt' => $session->ExpiresAt->toIso8601String(),
                'Token' => $token,
                'Roles' => [self::SUPER_ADMIN_ROLE],
            ]],
            requestId: $requestId,
            httpCode: 201,
            message: 'Created',
        );
    }

    private function bootstrapSuperAdmin(string $email, string $password, string $requestId): User
    {
        $this->lockUsersTable();
        $this->assertNoUsersExist($requestId);

        $now = Carbon::now();
        $user = new User();
        $user->setConnection(self::ROOT_CONNECTION);
        $user->Email = $email;
        $user->PasswordHash = Hash::make($password);
        $user->TenantId = null;
        $user->IsActive = true;
        $user->CreatedAt = $now;
        $user->UpdatedAt = $now;
        $user->save();

        $this->assignSuperAdminRole((int) $user->getKey(), $now);

        return $user;
    }

    private function lockUsersTable(): void
    {
        $conn = DB::connection(self::ROOT_CONNECTION);
        // pgsql supports EXCLUSIVE table locks; sqlite (used in test
        // fixtures) serialises writes on the connection so the lock is
        // redundant there. Guard by driver so both paths work.
        if ($conn->getDriverName() !== 'pgsql') {
            return;
        }
        $conn->statement('LOCK TABLE "' . self::USERS_TABLE . '" IN EXCLUSIVE MODE');
    }


    private function assertNoUsersExist(string $requestId): void
    {
        $count = (int) DB::connection(self::ROOT_CONNECTION)
            ->table(self::USERS_TABLE)
            ->count();
        if ($count === 0) {
            return;
        }
        Log::warning('auth.register.closed', ['RequestId' => $requestId, 'ExistingUserCount' => $count]);
        throw AuthException::custom('AuthRegistrationClosed',
            'Registration is closed. Ask a SuperAdmin to create your account.',
            [['Field' => 'Registration', 'Rule' => 'Closed']],
        )
    }

    private function assignSuperAdminRole(int $userId, Carbon $now): void
    {
        $roleId = (int) DB::connection(self::ROOT_CONNECTION)
            ->table(self::ROLES_TABLE)
            ->where('RoleName', self::SUPER_ADMIN_ROLE)
            ->value('RoleId');
        if ($roleId === 0) {
            throw AuthException::custom('AuthRegistrationClosed',
                'SuperAdmin role is not seeded. Run db:seed before registering.',
                [['Field' => 'Roles', 'Rule' => 'Unseeded', 'Value' => self::SUPER_ADMIN_ROLE]],
            )
        }
        DB::connection(self::ROOT_CONNECTION)
            ->table(self::USER_ROLES_TABLE)
            ->insert([
                'UserId' => $userId,
                'RoleId' => $roleId,
                'CreatedAt' => $now,
            ]);
    }
}
