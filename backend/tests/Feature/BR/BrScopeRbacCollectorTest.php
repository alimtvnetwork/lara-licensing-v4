<?php

declare(strict_types=1);

namespace Tests\Feature\BR;

use App\Exceptions\LaraException;
use App\Services\BR\BrScopeRbacCollector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Plan 14 step 18 contract tests for the SC-E RBAC collector.
 *
 * Locks:
 *  - JSONL is canonical: keys sorted lexicographically per row, LF-terminated,
 *    UTF-8; UserRole rows first (ascending EmailLower, RoleName), then
 *    CasbinRule rows (ascending Ptype, V0, V1, V2, V3, V4, V5).
 *  - `ContentHash = sha256(Jsonl)`; `UserRoleCount`, `CasbinRuleCount`,
 *    `BootstrapPresent` mirror the manifest slot (spec 26 §07,
 *    INV-BR-MS-2 anchor).
 *  - Any Root RBAC table unreadable => `BackupStorageFailure` at
 *    `/scope/rbac` rule `RbacCatalogUnreadable`.
 *  - A UserRoles row referencing a RoleId absent from the Roles snapshot
 *    => `BackupCorrupt` at `/scope/rbac/userRoles/<UserRoleId>` rule
 *    `UserRoleRoleIdUnknown`.
 *  - Empty identity graph => empty JSONL, zero counts, hash `sha256('')`,
 *    `BootstrapPresent = false`.
 *  - Seeded SuperAdmin user flips `BootstrapPresent = true`.
 */
final class BrScopeRbacCollectorTest extends TestCase
{
    use RefreshDatabase;

    private const REQUEST_ID = 'req-sc-e-0001';
    private const EMPTY_SHA256 = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';

    public function test_empty_identity_graph_yields_empty_jsonl_and_zero_counts(): void
    {
        DB::connection('root')->table('UserRoles')->delete();
        DB::connection('root')->table('Users')->delete();
        DB::connection('root')->table('CasbinRules')->delete();

        $out = app(BrScopeRbacCollector::class)->collect(self::REQUEST_ID);

        $this->assertSame('scope/rbac.jsonl.zst', $out['RelPath']);
        $this->assertSame('', $out['Jsonl']);
        $this->assertSame(self::EMPTY_SHA256, $out['ContentHash']);
        $this->assertSame(0, $out['UserRoleCount']);
        $this->assertSame(0, $out['CasbinRuleCount']);
        $this->assertFalse($out['BootstrapPresent']);
    }

    public function test_determinism_across_calls(): void
    {
        $a = app(BrScopeRbacCollector::class)->collect(self::REQUEST_ID);
        $b = app(BrScopeRbacCollector::class)->collect(self::REQUEST_ID);
        $this->assertSame($a['Jsonl'], $b['Jsonl']);
        $this->assertSame($a['ContentHash'], $b['ContentHash']);
    }

    public function test_super_admin_membership_flips_bootstrap_present(): void
    {
        DB::connection('root')->table('UserRoles')->delete();
        DB::connection('root')->table('Users')->delete();

        $roleId = (int) DB::connection('root')->table('Roles')->where('RoleName', 'SuperAdmin')->value('RoleId');
        $userId = DB::connection('root')->table('Users')->insertGetId([
            'Email' => 'boot@example.com',
            'PasswordHash' => 'x',
            'IsActive' => true,
        ]);
        DB::connection('root')->table('UserRoles')->insert([
            'UserId' => $userId,
            'RoleId' => $roleId,
        ]);

        $out = app(BrScopeRbacCollector::class)->collect(self::REQUEST_ID);

        $this->assertTrue($out['BootstrapPresent']);
        $this->assertSame(1, $out['UserRoleCount']);
        $this->assertStringContainsString('"EmailLower":"boot@example.com"', $out['Jsonl']);
        $this->assertStringContainsString('"RoleName":"SuperAdmin"', $out['Jsonl']);
    }

    public function test_soft_deleted_user_is_excluded(): void
    {
        DB::connection('root')->table('UserRoles')->delete();
        DB::connection('root')->table('Users')->delete();

        $roleId = (int) DB::connection('root')->table('Roles')->where('RoleName', 'Admin')->value('RoleId');
        $userId = DB::connection('root')->table('Users')->insertGetId([
            'Email' => 'gone@example.com',
            'PasswordHash' => 'x',
            'IsActive' => true,
            'DeletedAt' => now(),
        ]);
        DB::connection('root')->table('UserRoles')->insert([
            'UserId' => $userId,
            'RoleId' => $roleId,
        ]);

        $out = app(BrScopeRbacCollector::class)->collect(self::REQUEST_ID);
        $this->assertSame(0, $out['UserRoleCount']);
        $this->assertFalse($out['BootstrapPresent']);
    }

    public function test_unreadable_roles_table_raises_backup_storage_failure(): void
    {
        Schema::connection('root')->rename('Roles', 'RolesBackupTmp');
        try {
            app(BrScopeRbacCollector::class)->collect(self::REQUEST_ID);
            $this->fail('expected BackupStorageFailure');
        } catch (LaraException $e) {
            $this->assertSame('BackupStorageFailure', $e->errorCode);
            $this->assertSame('/scope/rbac', $e->violations[0]['Field']);
            $this->assertSame('RbacCatalogUnreadable', $e->violations[0]['Rule']);
        } finally {
            Schema::connection('root')->rename('RolesBackupTmp', 'Roles');
        }
    }

    public function test_seed_casbin_rules_are_included_and_sorted(): void
    {
        // Migration `2026_07_21_000008_seed_br_casbin_policies` seeded the
        // BR closed-set rows; the collector must surface them ordered.
        $out = app(BrScopeRbacCollector::class)->collect(self::REQUEST_ID);
        $this->assertGreaterThan(0, $out['CasbinRuleCount']);
        $lines = array_values(array_filter(explode("\n", $out['Jsonl'])));
        $casbinLines = array_values(array_filter(
            array_map(static fn (string $l) => json_decode($l, true), $lines),
            static fn (array $r) => ($r['RowType'] ?? '') === 'CasbinRule',
        ));
        $prev = null;
        foreach ($casbinLines as $r) {
            $key = [$r['Ptype'], $r['V0'], $r['V1'], (string) $r['V2'], (string) $r['V3']];
            if ($prev !== null) {
                $this->assertLessThanOrEqual(0, $prev <=> $key, 'CasbinRule rows must be sorted ascending');
            }
            $prev = $key;
        }
    }
}
