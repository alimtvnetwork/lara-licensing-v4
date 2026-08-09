<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\License;
use App\Models\User;
use App\Policies\Concerns\DelegatesToRoles;

/**
 * Plan 10 step 3. Gates for shard-DB License rows.
 * Admin+SuperAdmin: full control. Reseller: scoped to owned rows.
 * EndUser: no direct mutation, may view own bound license.
 */
final class LicensePolicy
{
    use DelegatesToRoles;

    public function viewAny(User $user): bool
    {
        return $this->userHasAnyRole($user, ['SuperAdmin', 'Admin', 'Reseller']);
    }

    public function view(User $user, License $license): bool
    {
        if ($this->userHasAnyRole($user, ['SuperAdmin', 'Admin'])) {
            return true;
        }

        return $this->userHasAnyRole($user, ['Reseller'])
            && (string) $license->getAttribute('ResellerId') === (string) $user->getAttribute('ResellerId');
    }

    public function create(User $user): bool
    {
        return $this->userHasAnyRole($user, ['SuperAdmin', 'Admin', 'Reseller']);
    }

    public function update(User $user, License $license): bool
    {
        return $this->view($user, $license);
    }

    public function revoke(User $user, License $license): bool
    {
        if ($this->userHasAnyRole($user, ['SuperAdmin', 'Admin'])) {
            return true;
        }

        return $this->view($user, $license);
    }

    public function delete(User $user, License $license): bool
    {
        return $this->userHasAnyRole($user, ['SuperAdmin', 'Admin']);
    }
}
