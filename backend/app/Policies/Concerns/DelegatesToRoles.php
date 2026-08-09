<?php

declare(strict_types=1);

namespace App\Policies\Concerns;

use App\Models\User;
use App\Policies\HasRolePolicy;

/**
 * Plan 10 step 3. Shared helper: resolve `HasRolePolicy` and answer
 * role-membership questions. Each Policy method stays under the 15-line
 * body cap (coding-guidelines.md).
 */
trait DelegatesToRoles
{
    private function roles(): HasRolePolicy
    {
        return app(HasRolePolicy::class);
    }

    /**
     * @param list<string> $roles
     */
    protected function userHasAnyRole(User $user, array $roles): bool
    {
        $userId = (string) ($user->getAttribute('UserId') ?? '');
        if ($userId === '') {
            return false;
        }

        return $this->roles()->hasAnyRole($userId, $roles);
    }
}
