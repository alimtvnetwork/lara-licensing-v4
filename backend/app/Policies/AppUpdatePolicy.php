<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\AppUpdate;
use App\Models\User;
use App\Policies\Concerns\DelegatesToRoles;

/**
 * Plan 10 step 3. Binary release gates. Publish and yank are SuperAdmin-only;
 * Admin may view the release history.
 */
final class AppUpdatePolicy
{
    use DelegatesToRoles;

    public function viewAny(User $user): bool
    {
        return $this->userHasAnyRole($user, ['SuperAdmin', 'Admin']);
    }

    public function view(User $user, AppUpdate $update): bool
    {
        return $this->viewAny($user);
    }

    public function publish(User $user): bool
    {
        return $this->userHasAnyRole($user, ['SuperAdmin']);
    }

    public function yank(User $user, AppUpdate $update): bool
    {
        return $this->userHasAnyRole($user, ['SuperAdmin']);
    }
}
