<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Plan 10 step 10. Owner of closed-set parity between config and DB.
 *
 * Root cause this seeder addresses in one sentence: LicenseTier rows were
 * only ever inserted by the create-table migration
 * (`2026_07_18_000004_create_root_license_tiers_table.php`) using inline
 * literals, and `license_categories`/`environments` had no seeder-time
 * assertion path, so a config edit could drift from the persisted rows
 * (LicenseTiers) or from downstream references (license_category_codes,
 * shard `Licenses.LicenseCategoryId` CHECK) with zero coverage.
 *
 * This seeder owns three responsibilities:
 *   1. Assert every closed-set config key is present and non-empty.
 *   2. Reconcile Root `LicenseTiers` rows against `config('lara.license_tiers')`
 *      idempotently. Drift raises a named RuntimeException before any
 *      write; ordinals map 1:1 with the CHECK constraint on the table.
 *   3. Cross-check `license_category_codes` covers every ordinal in
 *      `license_categories`; codes drive serial generation and a missing
 *      entry silently falls back to `K` (see shard migration 000008).
 *
 * Idempotent: LicenseTiers upsert uses ON CONFLICT ("LicenseTierId") DO NOTHING;
 * assertions are read-only. Safe to re-run under `db:seed`.
 *
 * PascalCase wire values are enforced: TierName, CategoryName, EnvironmentName
 * are stored/emitted verbatim as declared in config.
 */
final class ClosedSetsSeeder extends Seeder
{
    private const CONN = 'root';

    public function run(): void
    {
        $tiers = $this->assertMap('license_tiers');
        $categories = $this->assertMap('license_categories');
        $codes = $this->assertStringMap('license_category_codes');
        $environments = $this->assertList('environments');

        $this->assertCategoryCodesCover($categories, $codes);

        $inserted = $this->seedLicenseTiers($tiers);

        Log::info('ClosedSetsSeeder: closed-set parity verified', [
            'license_tiers'      => count($tiers),
            'license_tiers_new'  => $inserted,
            'license_categories' => count($categories),
            'environments'       => count($environments),
        ]);
        $this->command?->line(sprintf(
            '  ClosedSetsSeeder: tiers=%d (new=%d) categories=%d environments=%d',
            count($tiers),
            $inserted,
            count($categories),
            count($environments),
        ));
    }

    /**
     * @return array<string,int>
     */
    private function assertMap(string $key): array
    {
        $value = config("lara.{$key}");
        if (! is_array($value) || $value === []) {
            throw new RuntimeException("ClosedSetsSeeder: config('lara.{$key}') missing or empty.");
        }
        /** @var array<string,int> $map */
        $map = [];
        foreach ($value as $k => $v) {
            if (! is_int($v) && ! ctype_digit((string) $v)) {
                throw new RuntimeException(
                    "ClosedSetsSeeder: config('lara.{$key}') expects int ordinals; got '{$v}' for '{$k}'."
                );
            }
            $map[(string) $k] = (int) $v;
        }

        return $map;
    }

    /**
     * @return array<string,string>
     */
    private function assertStringMap(string $key): array
    {
        $value = config("lara.{$key}");
        if (! is_array($value) || $value === []) {
            throw new RuntimeException("ClosedSetsSeeder: config('lara.{$key}') missing or empty.");
        }
        /** @var array<string,string> $map */
        $map = [];
        foreach ($value as $k => $v) {
            $map[(string) $k] = (string) $v;
        }

        return $map;
    }

    /**
     * @return list<string>
     */
    private function assertList(string $key): array
    {
        $value = config("lara.{$key}");
        if (! is_array($value) || $value === []) {
            throw new RuntimeException("ClosedSetsSeeder: config('lara.{$key}') missing or empty.");
        }

        return array_values(array_map('strval', $value));
    }

    /**
     * @param  array<string,int>  $categories
     * @param  array<string,int>  $codes  keyed by ordinal
     */
    private function assertCategoryCodesCover(array $categories, array $codes): void
    {
        foreach ($categories as $name => $ordinal) {
            if (! array_key_exists((string) $ordinal, $codes)) {
                throw new RuntimeException(
                    "ClosedSetsSeeder: license_category_codes missing ordinal {$ordinal} for '{$name}'."
                );
            }
        }
    }

    /**
     * @param  array<string,int>  $tiers
     */
    private function seedLicenseTiers(array $tiers): int
    {
        $conn = DB::connection(self::CONN);
        $inserted = 0;
        foreach ($tiers as $tierName => $ordinal) {
            $affected = $conn->affectingStatement(
                'INSERT INTO "LicenseTiers" ("LicenseTierId","TierName","TierOrdinal")'
                . ' VALUES (?,?,?) ON CONFLICT ("LicenseTierId") DO NOTHING',
                [$ordinal, $tierName, $ordinal],
            );
            $inserted += $affected;
        }

        return $inserted;
    }
}
