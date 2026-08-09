<?php

declare(strict_types=1);

namespace App\Domain\BR;

/**
 * Closed-set of archive kinds per spec 26 §07 `archiveKind` enum.
 * Values MUST match the JSON schema `enum: ["export","snapshot"]`.
 */
enum BrArchiveKind: string
{
    case Export   = 'export';
    case Snapshot = 'snapshot';
}
