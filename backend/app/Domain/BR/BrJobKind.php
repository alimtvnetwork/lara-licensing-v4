<?php

declare(strict_types=1);

namespace App\Domain\BR;

/**
 * Plan 14 step 9. Closed-set of BR job kinds.
 *
 * Values MUST match the `BackupJobKind` PostgreSQL enum members from
 * migration 1 (`create_root_br_enums`) and are consumed by
 * `BackupJobs.Kind`. Also referenced by workers (spec 15 §"Job Kinds").
 *
 * Backfill kinds (`BrBackfillKind::*->value`) are added on top of this
 * enum in migration 11; they live in `BrBackfillKind` so this enum
 * stays scoped to the client-facing job kinds (Export/Import/Restore/
 * SnapshotCreate/SnapshotRestore) that endpoints 11..14 enqueue.
 */
enum BrJobKind: string
{
    case Export           = 'Export';
    case Import           = 'Import';
    case Restore          = 'Restore';
    case SnapshotCreate   = 'SnapshotCreate';
    case SnapshotRestore  = 'SnapshotRestore';
}
