<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Policies\Concerns\DelegatesToRoles;

/**
 * Plan 10 step 3. Root-DB User gates. Role assignment is SuperAdmin-only
 * to prevent Admin -> SuperAdmin privilege escalation.
 */
final class UserPolicy
{
    use DelegatesToRoles;

    public function viewAny(User $user): bool
    {
        return $this->userHasAnyRole($user, ['SuperAdmin', 'Admin']);
    }

    public function view(User $user, User $target): bool
    {
        if ((string) $user->getAttribute('UserId') === (string) $target->getAttribute('UserId')) {
            return true;
        }

        return $this->userHasAnyRole($user, ['SuperAdmin', 'Admin']);
    }

    public function create(User $user): bool
    {
        return $this->userHasAnyRole($user, ['SuperAdmin', 'Admin']);
    }

    public function update(User $user, User $target): bool
    {
        return $this->userHasAnyRole($user, ['SuperAdmin', 'Admin']);
    }

    public function assignRole(User $user, User $target): bool
    {
        return $this->userHasAnyRole($user, ['SuperAdmin']);
    }

    public function impersonate(User $user, User $target): bool
    {
        if ($this->userHasAnyRole($target, ['SuperAdmin'])) {
            return false;
        }

        return $this->userHasAnyRole($user, ['SuperAdmin', 'Admin']);
    }

    public function delete(User $user, User $target): bool
    {
        if ((string) $user->getAttribute('UserId') === (string) $target->getAttribute('UserId')) {
            return false;
        }

        return $this->userHasAnyRole($user, ['SuperAdmin']);
    }
}
