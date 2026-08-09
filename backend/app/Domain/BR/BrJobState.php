<?php

declare(strict_types=1);

namespace App\Domain\BR;

/**
 * Plan 14 step 10. Closed-set of BR job states.
 *
 * Values MUST match the `BackupJobState` PostgreSQL enum from
 * migration 1 (`create_root_br_enums`). Consumed by
 * `App\Services\BR\BrJobDispatcher` on every transition, so a typo
 * cannot slip past the enum -> DB check-constraint bridge.
 */
enum BrJobState: string
{
    case Queued     = 'Queued';
    case Running    = 'Running';
    case Succeeded  = 'Succeeded';
    case Failed     = 'Failed';
    case Cancelled  = 'Cancelled';
}
