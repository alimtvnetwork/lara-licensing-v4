<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Plan 10 step 11. Single owner of the Root `Roles` closed set.
 *
 * Reads role names from `config('lara.roles')` (see backend/config/lara.php
 * key `roles`, spec/21-app/04-roles.md) and idempotently upserts one row per
 * name into the `root` connection's `Roles` table. The `Roles` table CHECK
 * constraint enforces the same closed set at the DB layer, so a drifted
 * config value fails the INSERT rather than silently succeeding.
 *
 * Previously this work was owned by `RootSeeder::seedRoles()` with a
 * duplicated `REQUIRED_ROOT_ROLES` constant. That duplication is now removed
 * (RootSeeder keeps only the closed-set assertions for enum-only catalogs).
 *
 * Idempotent: `INSERT ... ON CONFLICT ("RoleName") DO NOTHING`. The log line
 * distinguishes newly inserted rows from re-runs so `artisan db:seed` output
 * is grep-able.
 */
final class RolesSeeder extends Seeder
{
    private const CONN = 'root';

    private const CONFIG_KEY = 'lara.roles';

    public function run(): void
    {
        $roles = $this->resolveRoles();

        $inserted = 0;
        foreach ($roles as $roleName) {
            $affected = DB::connection(self::CONN)->affectingStatement(
                'INSERT INTO "Roles" ("RoleName") VALUES (?) ON CONFLICT ("RoleName") DO NOTHING',
                [$roleName],
            );
            $inserted += $affected;
        }

        $total = count($roles);
        $this->command?->line(
            "  root.Roles reconciled: total={$total} inserted={$inserted} source=".self::CONFIG_KEY
        );
    }

    /**
     * @return list<string>
     */
    private function resolveRoles(): array
    {
        $configured = config(self::CONFIG_KEY);
        if (! is_array($configured) || $configured === []) {
            throw new RuntimeException(
                "RolesSeeder: config('".self::CONFIG_KEY."') must be a non-empty closed set."
            );
        }

        $normalised = [];
        foreach ($configured as $value) {
            if (! is_string($value) || $value === '') {
                throw new RuntimeException(
                    "RolesSeeder: config('".self::CONFIG_KEY."') contains a non-string or empty member."
                );
            }
            $normalised[] = $value;
        }

        return array_values(array_unique($normalised));
    }
}
