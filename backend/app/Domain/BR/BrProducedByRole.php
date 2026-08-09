<?php

declare(strict_types=1);

namespace App\Domain\BR;

/**
 * Closed-set of role values allowed inside `manifest.producedBy.role`
 * per spec 26 §07 (JSON schema `producedBy.role.enum`). Kept as a
 * backed enum so the validator references `BrProducedByRole::cases()`
 * instead of a magic string array.
 */
enum BrProducedByRole: string
{
    case SuperAdmin = 'super_admin';
    case Admin      = 'admin';
    case Operator   = 'operator';
    case Auditor    = 'auditor';
    case User       = 'user';
    case Deputy     = 'deputy';
}
