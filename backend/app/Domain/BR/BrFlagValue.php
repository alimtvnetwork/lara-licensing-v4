<?php

declare(strict_types=1);

namespace App\Domain\BR;

/**
 * Tri-state feature flag value per
 * spec/26-backup-restore/25-migration-and-rollout.md §"Feature Flags":
 *  - Off:    endpoint short-circuits before lock acquisition (INV-BR-MG-3).
 *  - Shadow: writes route to dry-run branch; observability rows tagged
 *            `mode=shadow`; returns 202 ShadowAccepted (INV-BR-MG-4).
 *  - On:     normal production behaviour.
 */
enum BrFlagValue: string
{
    case Off    = 'off';
    case Shadow = 'shadow';
    case On     = 'on';

    public function isEnabled(): bool
    {
        return $this === self::On;
    }

    public function isShadow(): bool
    {
        return $this === self::Shadow;
    }

    public function isOff(): bool
    {
        return $this === self::Off;
    }
}
