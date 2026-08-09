<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\QuotaRequest;
use App\Models\User;
use App\Policies\Concerns\DelegatesToRoles;

/**
 * Plan 10 step 3. QuotaRequest gates. Resellers create/cancel their own;
 * Admin+SuperAdmin approve or deny.
 */
final class QuotaRequestPolicy
{
    use DelegatesToRoles;

    public function viewAny(User $user): bool
    {
        return $this->userHasAnyRole($user, ['SuperAdmin', 'Admin', 'Reseller']);
    }

    public function view(User $user, QuotaRequest $request): bool
    {
        if ($this->userHasAnyRole($user, ['SuperAdmin', 'Admin'])) {
            return true;
        }

        return $this->userHasAnyRole($user, ['Reseller'])
            && (string) $request->getAttribute('ResellerId') === (string) $user->getAttribute('ResellerId');
    }

    public function create(User $user): bool
    {
        return $this->userHasAnyRole($user, ['Reseller']);
    }

    public function cancel(User $user, QuotaRequest $request): bool
    {
        return $this->view($user, $request)
            && $this->userHasAnyRole($user, ['Reseller']);
    }

    public function approve(User $user, QuotaRequest $request): bool
    {
        return $this->userHasAnyRole($user, ['SuperAdmin', 'Admin']);
    }

    public function deny(User $user, QuotaRequest $request): bool
    {
        return $this->userHasAnyRole($user, ['SuperAdmin', 'Admin']);
    }
}
