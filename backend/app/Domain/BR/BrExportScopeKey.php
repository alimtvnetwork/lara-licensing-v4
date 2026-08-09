<?php

declare(strict_types=1);

namespace App\Domain\BR;

/**
 * Plan 14 step 9. Closed-set of Export/Import scope flags matching
 * spec 26 §11 "Request Body" and §05 scope catalog (SC-A..H).
 *
 * The 8 boolean scope keys carried by the Export request body. Their
 * SC-* mapping is authoritative here so `BrExportRequest` and
 * `BrExportService` cannot drift.
 */
enum BrExportScopeKey: string
{
    case Schema          = 'schema';          // SC-A
    case ClosedSets      = 'closedSets';      // SC-B
    case Features        = 'features';        // SC-C
    case Licenses        = 'licenses';        // SC-D
    case Rbac            = 'rbac';            // SC-E
    case SecretsEnvelope = 'secretsEnvelope'; // SC-G
    case Files           = 'files';           // SC-H

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
