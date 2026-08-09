<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Reseller;
use App\Models\User;
use App\Policies\Concerns\DelegatesToRoles;

/**
 * Plan 10 step 3. Root-DB Reseller gates. Admin+SuperAdmin manage all;
 * a Reseller principal may view its own tenant row.
 */
final class ResellerPolicy
{
    use DelegatesToRoles;

    public function viewAny(User $user): bool
    {
        return $this->userHasAnyRole($user, ['SuperAdmin', 'Admin']);
    }

    public function view(User $user, Reseller $reseller): bool
    {
        if ($this->userHasAnyRole($user, ['SuperAdmin', 'Admin'])) {
            return true;
        }

        return $this->userHasAnyRole($user, ['Reseller'])
            && (string) $reseller->getAttribute('ResellerId') === (string) $user->getAttribute('ResellerId');
    }

    public function create(User $user): bool
    {
        return $this->userHasAnyRole($user, ['SuperAdmin', 'Admin']);
    }

    public function update(User $user, Reseller $reseller): bool
    {
        return $this->userHasAnyRole($user, ['SuperAdmin', 'Admin']);
    }

    public function delete(User $user, Reseller $reseller): bool
    {
        return $this->userHasAnyRole($user, ['SuperAdmin']);
    }
}
