<?php

declare(strict_types=1);

namespace App\Http\Resources\Concerns;

use DateTimeInterface;

/**
 * Plan 10 step 4. Shared PascalCase helpers for JsonResource output.
 * Mirrors the existing controller projections (see
 * `Admin\LicenseController::isoOrEmpty`) so wire parity holds byte-for-byte
 * per spec/03/verify-contracts and the PascalCase JSON key rule
 * (`.lovable/coding-guidelines.md`).
 */
trait FormatsPascalCase
{
    protected function isoOrEmpty(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d\TH:i:s\Z');
        }

        return (string) $value;
    }

    protected function isoOrNull(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d\TH:i:s\Z');
        }

        return (string) $value;
    }
}
