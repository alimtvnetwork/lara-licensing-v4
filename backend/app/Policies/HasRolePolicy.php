<?php

declare(strict_types=1);

namespace App\Policies;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * HasRolePolicy — Root-DB backed role check.
 *
 * Spec: spec/21-app/04-roles.md (Roles are stored in Root.UserRoles/Roles,
 * never on Profiles). See .lovable/memory/standards for no-magic-literals:
 * role names flow in from callers that name config('lara.roles') entries
 * or route middleware parameters, and are re-validated against the Root
 * `Roles` catalog before any UserRoles lookup.
 *
 * Function bodies capped at 15 lines (coding-guidelines.md).
 */
final class HasRolePolicy
{
    /** Root connection name (see backend/config/database.php). */
    private const ROOT = 'root';

    public function hasRole(string $userId, string $role): bool
    {
        $this->assertKnownRole($role);

        return $this->hasAnyRole($userId, [$role]);
    }

    /**
     * @param list<string> $roles
     */
    public function hasAnyRole(string $userId, array $roles): bool
    {
        if ($roles === []) {
            throw new InvalidArgumentException('HasRolePolicy::hasAnyRole requires at least one role.');
        }
        foreach ($roles as $role) {
            $this->assertKnownRole($role);
        }

        return DB::connection(self::ROOT)
            ->table('UserRoles as ur')
            ->join('Roles as r', 'r.RoleId', '=', 'ur.RoleId')
            ->where('ur.UserId', $userId)
            ->whereIn('r.RoleName', $roles)
            ->exists();
    }

    private function assertKnownRole(string $role): void
    {
        $exists = DB::connection(self::ROOT)
            ->table('Roles')
            ->where('RoleName', $role)
            ->exists();
        $isFailed = !$exists;
        if ($isFailed) {
            throw new InvalidArgumentException("Unknown role '{$role}'; not present in Root.Roles catalog.");
        }
    }
}
