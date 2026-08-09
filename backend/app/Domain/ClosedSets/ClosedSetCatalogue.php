<?php

declare(strict_types=1);

namespace App\Domain\ClosedSets;

use RuntimeException;

/**
 * BE-authoritative catalogue of the app's closed sets.
 *
 * Root cause this class addresses in one sentence: SC-B (spec
 * 26 §05) mandates a byte-real capture of every closed-set at
 * Export time, but the app has no `public.closed_sets` table
 * (closed sets live in config + FE `src/lib/closed-sets.ts`),
 * so a single BE-side catalogue is required or the collector
 * would have to reach into the FE bundle at Export time.
 *
 * The catalogue mirrors the FE registry (5 sets, same ordering
 * per spec 24/19-component-select.md §2). Sources per set:
 *   - LicenseCategory: config `lara.license_categories`
 *   - LicenseTier:     config `lara.license_tiers`
 *   - Environment:     config `lara.environments`
 *   - AppRole:         config `lara.roles`
 *   - QuotaRequestStatus: literal, per spec 21/42
 *     "Quota state machine order". No config surface for this
 *     enum exists yet; hard-coded here so SC-B is deterministic
 *     and identical across environments. Any change requires a
 *     spec 21/42 amendment in the same commit.
 *
 * The catalogue is deterministic: `all()` returns sets in a
 * fixed order, each set's values are pre-sorted by ordinal
 * ascending. Feeding the same config produces identical bytes;
 * `BrScopeClosedSetsCollector::collect` hashes over those bytes
 * so INV-BR-MS-3 (deterministic Merkle root) holds.
 */
final class ClosedSetCatalogue
{
    public const SET_LICENSE_CATEGORY = 'LicenseCategory';
    public const SET_LICENSE_TIER = 'LicenseTier';
    public const SET_ENVIRONMENT = 'Environment';
    public const SET_APP_ROLE = 'AppRole';
    public const SET_QUOTA_REQUEST_STATUS = 'QuotaRequestStatus';

    /** @return list<string> */
    public const SET_ORDER = [
        self::SET_APP_ROLE,
        self::SET_ENVIRONMENT,
        self::SET_LICENSE_CATEGORY,
        self::SET_LICENSE_TIER,
        self::SET_QUOTA_REQUEST_STATUS,
    ];

    private const CFG_LICENSE_CATEGORIES = 'lara.license_categories';
    private const CFG_LICENSE_TIERS = 'lara.license_tiers';
    private const CFG_ENVIRONMENTS = 'lara.environments';
    private const CFG_ROLES = 'lara.roles';

    /**
     * Quota state-machine literal, mirrors spec 21/42.
     * @var list<string>
     */
    private const QUOTA_REQUEST_STATUS_ORDER = [
        'Pending', 'Approved', 'Denied', 'Cancelled',
    ];

    /**
     * Return every closed set, sorted by set id ascending.
     * Each value is `{Ordinal, ValueKey}` with Ordinal >= 1.
     *
     * @return list<array{SetId:string, Values:list<array{Ordinal:int, ValueKey:string}>}>
     */
    public function all(): array
    {
        $sets = [
            self::SET_LICENSE_CATEGORY => $this->fromOrdinalMap(self::CFG_LICENSE_CATEGORIES),
            self::SET_LICENSE_TIER => $this->fromOrdinalMap(self::CFG_LICENSE_TIERS),
            self::SET_ENVIRONMENT => $this->fromOrderedList(self::CFG_ENVIRONMENTS),
            self::SET_APP_ROLE => $this->fromOrderedList(self::CFG_ROLES),
            self::SET_QUOTA_REQUEST_STATUS => $this->fromLiteralList(self::QUOTA_REQUEST_STATUS_ORDER),
        ];
        $out = [];
        foreach (self::SET_ORDER as $setId) {
            $out[] = ['SetId' => $setId, 'Values' => $sets[$setId]];
        }

        return $out;
    }

    /**
     * @return list<array{Ordinal:int, ValueKey:string}>
     */
    private function fromOrdinalMap(string $cfgKey): array
    {
        $value = config($cfgKey);
        if (!is_array($value) || $value === []) {
            throw new RuntimeException("ClosedSetCatalogue: config('{$cfgKey}') missing or empty.");
        }
        $out = [];
        foreach ($value as $key => $ordinal) {
            $out[] = ['Ordinal' => (int) $ordinal, 'ValueKey' => (string) $key];
        }
        usort($out, static fn ($a, $b) => $a['Ordinal'] <=> $b['Ordinal']);

        return $out;
    }

    /**
     * @return list<array{Ordinal:int, ValueKey:string}>
     */
    private function fromOrderedList(string $cfgKey): array
    {
        $value = config($cfgKey);
        if (!is_array($value) || $value === []) {
            throw new RuntimeException("ClosedSetCatalogue: config('{$cfgKey}') missing or empty.");
        }

        return $this->fromLiteralList(array_values(array_map('strval', $value)));
    }

    /**
     * @param  list<string>  $values
     * @return list<array{Ordinal:int, ValueKey:string}>
     */
    private function fromLiteralList(array $values): array
    {
        $out = [];
        foreach ($values as $i => $v) {
            $out[] = ['Ordinal' => $i + 1, 'ValueKey' => $v];
        }

        return $out;
    }
}
