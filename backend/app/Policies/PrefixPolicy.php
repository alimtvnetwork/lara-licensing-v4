<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Prefix;
use App\Models\User;
use App\Policies\Concerns\DelegatesToRoles;

/**
 * Plan 10 step 3. Prefix catalog gates. Admin+SuperAdmin manage; Reseller
 * views its scoped prefix rows.
 */
final class PrefixPolicy
{
    use DelegatesToRoles;

    public function viewAny(User $user): bool
    {
        return $this->userHasAnyRole($user, ['SuperAdmin', 'Admin', 'Reseller']);
    }

    public function view(User $user, Prefix $prefix): bool
    {
        if ($this->userHasAnyRole($user, ['SuperAdmin', 'Admin'])) {
            return true;
        }

        return $this->userHasAnyRole($user, ['Reseller'])
            && (string) $prefix->getAttribute('ResellerId') === (string) $user->getAttribute('ResellerId');
    }

    public function create(User $user): bool
    {
        return $this->userHasAnyRole($user, ['SuperAdmin', 'Admin']);
    }

    public function update(User $user, Prefix $prefix): bool
    {
        return $this->userHasAnyRole($user, ['SuperAdmin', 'Admin']);
    }

    public function delete(User $user, Prefix $prefix): bool
    {
        return $this->userHasAnyRole($user, ['SuperAdmin', 'Admin']);
    }
}
