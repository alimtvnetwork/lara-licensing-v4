<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\AuthSession;
use App\Models\User;
use App\Policies\Concerns\DelegatesToRoles;

/**
 * Plan 10 step 3. AuthSession gates. Users may view/revoke their own
 * sessions; Admin+SuperAdmin may revoke any.
 */
final class AuthSessionPolicy
{
    use DelegatesToRoles;

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, AuthSession $session): bool
    {
        if ((string) $session->getAttribute('UserId') === (string) $user->getAttribute('UserId')) {
            return true;
        }

        return $this->userHasAnyRole($user, ['SuperAdmin', 'Admin']);
    }

    public function revoke(User $user, AuthSession $session): bool
    {
        return $this->view($user, $session);
    }
}
