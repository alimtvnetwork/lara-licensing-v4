<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Plan 06 step 20, revised Plan 10 step 11. Root DB closed-set assertion seeder.
 *
 * Roles insertion moved to `RolesSeeder` in Plan 10 step 11 to remove the
 * duplicated `REQUIRED_ROOT_ROLES` constant and make `config('lara.roles')`
 * the single source of truth. LicenseTiers, Environments, and Permissions
 * remain enum-only (no Root table exists for them); this seeder asserts
 * those catalogs are present in config so drift is caught at seed time
 * rather than at runtime, per spec/21-app/04-roles.md,
 * spec/21-app/43-license-tiers.md, spec/21-app/44-environments.md,
 * and .lovable/coding-guidelines rule 8 (no magic literals).
 *
 * FeatureCatalogSeeder is composed directly by `DatabaseSeeder::CHAIN`
 * (Plan 10 step 8), so this seeder no longer delegates to it.
 */
final class RootSeeder extends Seeder
{
    public function run(): void
    {
        $this->assertNonEmpty('roles');
        $this->assertNonEmpty('license_tiers');
        $this->assertNonEmpty('environments');
        $this->assertNonEmpty('feature_registry');

        $this->command?->line('  root.RootSeeder: closed-set config assertions passed');
    }

    private function assertNonEmpty(string $key): void
    {
        $value = config("lara.{$key}");
        if (! is_array($value) || $value === []) {
            throw new RuntimeException(
                "RootSeeder: config('lara.{$key}') must be a non-empty closed set."
            );
        }
    }
}
