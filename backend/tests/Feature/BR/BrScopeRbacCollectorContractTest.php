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
 * Plan 14 companion contract tests for SC-E RBAC (spec 26 §05, §07,
 * INV-BR-MS-2). Locks that `scope/rbac.jsonl.zst` is byte-exact,
 * canonically ordered, deterministic, and that every declared failure
 * / empty-shard path raises the right closed-set code.
 *
 * Complements `BrScopeRbacCollectorTest`: this file focuses on the
 * on-the-wire byte shape (per-row key order, LF terminator, UTF-8,
 * no CRLF), cross-user ordering, `UserRoleRoleIdUnknown` corruption,
 * and unreadable branches for `Users`, `UserRoles`, and `CasbinRules`.
 */
final class BrScopeRbacCollectorContractTest extends TestCase
{
    use RefreshDatabase;

    private const REQ = 'req-sc-e-contract';
    private const EMPTY_SHA256 = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';

    /** @return array{int,int,int} [roleAdminId, roleSuperId, roleViewerId] */
    private function seedFreshGraph(): array
    {
        DB::connection('root')->table('UserRoles')->delete();
        DB::connection('root')->table('Users')->delete();
        DB::connection('root')->table('CasbinRules')->delete();
        $admin = (int) DB::connection('root')->table('Roles')->where('RoleName', 'Admin')->value('RoleId');
        $super = (int) DB::connection('root')->table('Roles')->where('RoleName', 'SuperAdmin')->value('RoleId');
        $viewer = (int) DB::connection('root')->table('Roles')->where('RoleName', 'Viewer')->value('RoleId');

        return [$admin, $super, $viewer];
    }

    public function test_user_role_row_is_byte_exact_with_sorted_keys_and_lf_terminator(): void
    {
        [$admin] = $this->seedFreshGraph();
        $uid = DB::connection('root')->table('Users')->insertGetId([
            'Email' => 'Alice@Example.com', 'PasswordHash' => 'x', 'IsActive' => true,
        ]);
        DB::connection('root')->table('UserRoles')->insert(['UserId' => $uid, 'RoleId' => $admin]);

        $out = app(BrScopeRbacCollector::class)->collect(self::REQ);
        $expected = '{"EmailLower":"alice@example.com","IsActive":true,"RoleName":"Admin","RowType":"UserRole"}' . "\n";
        $this->assertSame($expected, $out['Jsonl']);
        $this->assertStringEndsWith("\n", $out['Jsonl']);
        $this->assertStringNotContainsString("\r", $out['Jsonl']);
        $this->assertSame(hash('sha256', $out['Jsonl']), $out['ContentHash']);
    }

    public function test_casbin_row_is_byte_exact_with_sorted_keys_and_null_v_columns(): void
    {
        $this->seedFreshGraph();
        DB::connection('root')->table('CasbinRules')->insert([
            'Ptype' => 'p', 'V0' => 'Admin', 'V1' => '/api/x', 'V2' => 'GET', 'V3' => 'allow',
        ]);
        $out = app(BrScopeRbacCollector::class)->collect(self::REQ);
        $expected = '{"Ptype":"p","RowType":"CasbinRule","V0":"Admin","V1":"/api/x","V2":"GET","V3":"allow","V4":null,"V5":null}' . "\n";
        $this->assertSame($expected, $out['Jsonl']);
        $this->assertSame(1, $out['CasbinRuleCount']);
        $this->assertSame(0, $out['UserRoleCount']);
    }

    public function test_user_roles_are_sorted_by_email_then_role_name(): void
    {
        [$admin, $super, $viewer] = $this->seedFreshGraph();
        $charlie = DB::connection('root')->table('Users')->insertGetId(['Email' => 'charlie@example.com', 'PasswordHash' => 'x', 'IsActive' => true]);
        $alice = DB::connection('root')->table('Users')->insertGetId(['Email' => 'alice@example.com', 'PasswordHash' => 'x', 'IsActive' => true]);
        $bob = DB::connection('root')->table('Users')->insertGetId(['Email' => 'bob@example.com', 'PasswordHash' => 'x', 'IsActive' => true]);
        DB::connection('root')->table('UserRoles')->insert([
            ['UserId' => $charlie, 'RoleId' => $viewer],
            ['UserId' => $alice, 'RoleId' => $super],
            ['UserId' => $alice, 'RoleId' => $admin],
            ['UserId' => $bob, 'RoleId' => $admin],
        ]);

        $out = app(BrScopeRbacCollector::class)->collect(self::REQ);
        $lines = array_values(array_filter(explode("\n", $out['Jsonl'])));
        $keys = array_map(static function (string $l) {
            $r = json_decode($l, true, flags: JSON_THROW_ON_ERROR);

            return $r['EmailLower'] . '|' . $r['RoleName'];
        }, $lines);
        $this->assertSame([
            'alice@example.com|Admin',
            'alice@example.com|SuperAdmin',
            'bob@example.com|Admin',
            'charlie@example.com|Viewer',
        ], $keys);
        $this->assertSame(4, $out['UserRoleCount']);
        $this->assertTrue($out['BootstrapPresent']);
    }

    public function test_unknown_role_id_raises_backup_corrupt(): void
    {
        [$admin] = $this->seedFreshGraph();
        $uid = DB::connection('root')->table('Users')->insertGetId(['Email' => 'x@e.com', 'PasswordHash' => 'x', 'IsActive' => true]);
        $urId = DB::connection('root')->table('UserRoles')->insertGetId(['UserId' => $uid, 'RoleId' => $admin]);
        DB::connection('root')->statement('ALTER TABLE "UserRoles" DROP CONSTRAINT IF EXISTS "UserRoles_RoleId_fkey"');
        DB::connection('root')->table('UserRoles')->where('UserRoleId', $urId)->update(['RoleId' => 999999]);

        try {
            app(BrScopeRbacCollector::class)->collect(self::REQ);
            $this->fail('expected BackupCorrupt');
        } catch (LaraException $e) {
            $this->assertSame('BackupCorrupt', $e->errorCode);
            $this->assertSame('/scope/rbac/userRoles/' . $urId, $e->violations[0]['Field']);
            $this->assertSame('UserRoleRoleIdUnknown', $e->violations[0]['Rule']);
        }
    }

    public function test_unreadable_users_table_raises_backup_storage_failure(): void
    {
        Schema::connection('root')->rename('Users', 'UsersBackupTmp');
        try {
            app(BrScopeRbacCollector::class)->collect(self::REQ);
            $this->fail('expected BackupStorageFailure');
        } catch (LaraException $e) {
            $this->assertSame('BackupStorageFailure', $e->errorCode);
            $this->assertSame('/scope/rbac', $e->violations[0]['Field']);
            $this->assertSame('RbacCatalogUnreadable', $e->violations[0]['Rule']);
        } finally {
            Schema::connection('root')->rename('UsersBackupTmp', 'Users');
        }
    }

    public function test_unreadable_user_roles_table_raises_backup_storage_failure(): void
    {
        Schema::connection('root')->rename('UserRoles', 'UserRolesBackupTmp');
        try {
            app(BrScopeRbacCollector::class)->collect(self::REQ);
            $this->fail('expected BackupStorageFailure');
        } catch (LaraException $e) {
            $this->assertSame('BackupStorageFailure', $e->errorCode);
            $this->assertSame('RbacCatalogUnreadable', $e->violations[0]['Rule']);
        } finally {
            Schema::connection('root')->rename('UserRolesBackupTmp', 'UserRoles');
        }
    }

    public function test_unreadable_casbin_rules_table_raises_backup_storage_failure(): void
    {
        Schema::connection('root')->rename('CasbinRules', 'CasbinRulesBackupTmp');
        try {
            app(BrScopeRbacCollector::class)->collect(self::REQ);
            $this->fail('expected BackupStorageFailure');
        } catch (LaraException $e) {
            $this->assertSame('BackupStorageFailure', $e->errorCode);
            $this->assertSame('RbacCatalogUnreadable', $e->violations[0]['Rule']);
        } finally {
            Schema::connection('root')->rename('CasbinRulesBackupTmp', 'CasbinRules');
        }
    }

    public function test_empty_shard_hash_matches_sha256_of_empty_string(): void
    {
        $this->seedFreshGraph();
        $out = app(BrScopeRbacCollector::class)->collect(self::REQ);
        $this->assertSame('', $out['Jsonl']);
        $this->assertSame(self::EMPTY_SHA256, $out['ContentHash']);
        $this->assertSame(hash('sha256', ''), $out['ContentHash']);
        $this->assertFalse($out['BootstrapPresent']);
    }

    public function test_user_role_rows_precede_casbin_rows_in_jsonl(): void
    {
        [$admin] = $this->seedFreshGraph();
        $uid = DB::connection('root')->table('Users')->insertGetId(['Email' => 'z@e.com', 'PasswordHash' => 'x', 'IsActive' => true]);
        DB::connection('root')->table('UserRoles')->insert(['UserId' => $uid, 'RoleId' => $admin]);
        DB::connection('root')->table('CasbinRules')->insert(['Ptype' => 'p', 'V0' => 'Admin', 'V1' => '/api/y', 'V2' => 'GET', 'V3' => 'allow']);

        $out = app(BrScopeRbacCollector::class)->collect(self::REQ);
        $lines = array_values(array_filter(explode("\n", $out['Jsonl'])));
        $this->assertCount(2, $lines);
        $this->assertSame('UserRole', json_decode($lines[0], true)['RowType']);
        $this->assertSame('CasbinRule', json_decode($lines[1], true)['RowType']);
    }

    public function test_utf8_email_is_preserved_without_ascii_escapes(): void
    {
        [$admin] = $this->seedFreshGraph();
        $uid = DB::connection('root')->table('Users')->insertGetId(['Email' => 'josé@example.com', 'PasswordHash' => 'x', 'IsActive' => true]);
        DB::connection('root')->table('UserRoles')->insert(['UserId' => $uid, 'RoleId' => $admin]);
        $out = app(BrScopeRbacCollector::class)->collect(self::REQ);
        $this->assertStringContainsString('"EmailLower":"josé@example.com"', $out['Jsonl']);
        $this->assertStringNotContainsString('\\u00e9', $out['Jsonl']);
    }
}
