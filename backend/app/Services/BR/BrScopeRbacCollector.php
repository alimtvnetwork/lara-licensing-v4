<?php

declare(strict_types=1);

namespace App\Services\BR;

use App\Exceptions\InternalException;


use App\Exceptions\LaraException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Plan 14 step 18. SC-E "RBAC" collector for the S1 shadow Export path.
 *
 * Normative sources:
 *  - spec/26-backup-restore/05-scope-catalog.md §"SC-E RBAC" (selector =
 *    Root `Users` join `UserRoles` join `Roles`, plus `CasbinRules`;
 *    whole scope; restore rank 5).
 *  - spec/26-backup-restore/07-manifest-schema.md §"`scope` Shape"
 *    (`manifest.scope.rbac = {contentHash, userRoleCount, casbinRuleCount,
 *    bootstrapPresent}`).
 *  - spec/26-backup-restore/02-casbin-integration.md v1.0.0 §"Adapter"
 *    (`CasbinRules` table with `Ptype`, `V0..V5` columns; unique index
 *    `("Ptype","V0","V1", COALESCE("V2",''), COALESCE("V3",''))`).
 *  - spec/23-app-db/01-schema.md §Users, Roles, Tenants (Root identity
 *    trio `Roles`/`Users`/`UserRoles`, PascalCase columns).
 *  - INV-BR-MS-2 (every `scope.*.contentHash` hashes the class's real
 *    bytes; empty placeholders are a validator violation once real
 *    content ships).
 *
 * Portability: `UserRoles` rows are re-keyed by `EmailLower` + `RoleName`
 * (both natural closed-set / uniqueness anchors) so the surrogate
 * `UserId` / `RoleId` never leaks into the archive. Soft-deleted users
 * (`DeletedAt IS NOT NULL`) are excluded; the shadow captures the
 * *live* identity graph only. `CasbinRules` are emitted verbatim
 * (`Ptype`, `V0..V5`) because they are already portable (role names,
 * URL patterns, HTTP verbs).
 *
 * `bootstrapPresent = true` iff at least one active
 * (`IsActive=true, DeletedAt IS NULL`) User is joined to the
 * `SuperAdmin` role. This mirrors spec 26 §restore preflight
 * "at least one SuperAdmin bootstrap identity must exist post-restore",
 * so Import can flag missing bootstrap before mutating live state.
 *
 * Failure model (strict, no swallowing):
 *  - Any of the four Root tables unreadable => `BackupStorageFailure`
 *    (500) at `/scope/rbac` rule `RbacCatalogUnreadable`.
 *  - A `UserRoles` row references a `UserId` absent from the snapshot
 *    (raced delete, dangling FK) => `BackupCorrupt` (422) at
 *    `/scope/rbac/userRoles/<UserRoleId>` rule `UserRoleUserIdUnknown`.
 *  - A `UserRoles` row references a `RoleId` absent from the snapshot
 *    (closed-set drift) => `BackupCorrupt` (422) at
 *    `/scope/rbac/userRoles/<UserRoleId>` rule `UserRoleRoleIdUnknown`.
 *
 * 15-line function cap held by splitting into `loadRoles`, `loadUsers`,
 * `loadUserRoles`, `mapUserRoles`, `loadCasbinRules`, `mapCasbinRows`,
 * `bootstrapPresent`, `renderJsonl`, and `read`.
 */
final class BrScopeRbacCollector
{
    private const CONN = 'root';
    private const TABLE_ROLES = 'Roles';
    private const TABLE_USERS = 'Users';
    private const TABLE_USER_ROLES = 'UserRoles';
    private const TABLE_CASBIN = 'CasbinRules';
    private const REL_PATH = 'scope/rbac.jsonl.zst';

    private const ROW_TYPE_USER_ROLE = 'UserRole';
    private const ROW_TYPE_CASBIN = 'CasbinRule';
    private const BOOTSTRAP_ROLE = 'SuperAdmin';

    private const ERR_UNREADABLE = 'BackupStorageFailure';
    private const ERR_CORRUPT = 'BackupCorrupt';
    private const RULE_UNREADABLE = 'RbacCatalogUnreadable';
    private const RULE_UNKNOWN_USER = 'UserRoleUserIdUnknown';
    private const RULE_UNKNOWN_ROLE = 'UserRoleRoleIdUnknown';
    private const FIELD_SCOPE = '/scope/rbac';

    private const LOG_COLLECTED = 'br.export.scope.rbac.collected';
    private const LOG_UNREADABLE = 'br.export.scope.rbac.unreadable';
    private const LOG_UNKNOWN_USER = 'br.export.scope.rbac.unknown_user_id';
    private const LOG_UNKNOWN_ROLE = 'br.export.scope.rbac.unknown_role_id';

    /**
     * Collect SC-E rows and return the JSONL payload + manifest slot
     * fields (`userRoleCount`, `casbinRuleCount`, `bootstrapPresent`,
     * `contentHash`).
     *
     * @return array{
     *   Jsonl: string,
     *   RelPath: string,
     *   ContentHash: string,
     *   UserRoleCount: int,
     *   CasbinRuleCount: int,
     *   BootstrapPresent: bool
     * }
     */
    public function collect(string $requestId): array
    {
        $roles = $this->loadRoles($requestId);
        $users = $this->loadUsers($requestId);
        $userRoles = $this->loadUserRoles($users, $roles, $requestId);
        $casbin = $this->loadCasbinRules($requestId);
        $bootstrap = $this->bootstrapPresent($userRoles);
        $jsonl = $this->renderJsonl($userRoles, $casbin);
        $contentHash = hash('sha256', $jsonl);
        Log::info(self::LOG_COLLECTED, ['UserRoleCount' => count($userRoles), 'CasbinRuleCount' => count($casbin), 'BootstrapPresent' => $bootstrap, 'ContentHash' => $contentHash, 'BodyBytes' => strlen($jsonl), 'RequestId' => $requestId]);

        return ['Jsonl' => $jsonl, 'RelPath' => self::REL_PATH, 'ContentHash' => $contentHash, 'UserRoleCount' => count($userRoles), 'CasbinRuleCount' => count($casbin), 'BootstrapPresent' => $bootstrap];
    }

    /** @return array<int,string> RoleId => RoleName */
    private function loadRoles(string $requestId): array
    {
        $rows = $this->read(self::TABLE_ROLES, $requestId, fn () => DB::connection(self::CONN)->table(self::TABLE_ROLES)->orderBy('RoleId')->get(['RoleId', 'RoleName']));
        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row->RoleId] = (string) $row->RoleName;
        }

        return $map;
    }

    /** @return array<int,array{EmailLower:string, IsActive:bool}> UserId => data */
    private function loadUsers(string $requestId): array
    {
        $rows = $this->read(self::TABLE_USERS, $requestId, fn () => DB::connection(self::CONN)->table(self::TABLE_USERS)->whereNull('DeletedAt')->orderBy('UserId')->get(['UserId', 'Email', 'IsActive']));
        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row->UserId] = ['EmailLower' => strtolower((string) $row->Email), 'IsActive' => (bool) $row->IsActive];
        }

        return $map;
    }

    /**
     * @param  array<int,array{EmailLower:string, IsActive:bool}>  $users
     * @param  array<int,string>  $roles
     * @return list<array{EmailLower:string, RoleName:string, IsActive:bool}>
     */
    private function loadUserRoles(array $users, array $roles, string $requestId): array
    {
        $rows = $this->read(self::TABLE_USER_ROLES, $requestId, fn () => DB::connection(self::CONN)->table(self::TABLE_USER_ROLES)->orderBy('UserRoleId')->get(['UserRoleId', 'UserId', 'RoleId']));

        return $this->mapUserRoles($rows->all(), $users, $roles, $requestId);
    }

    /**
     * @param  list<object>  $rows
     * @param  array<int,array{EmailLower:string, IsActive:bool}>  $users
     * @param  array<int,string>  $roles
     * @return list<array{EmailLower:string, RoleName:string, IsActive:bool}>
     */
    private function mapUserRoles(array $rows, array $users, array $roles, string $requestId): array
    {
        $out = [];
        foreach ($rows as $row) {
            $userRoleId = (int) $row->UserRoleId;
            $userId = (int) $row->UserId;
            $roleId = (int) $row->RoleId;
            if (isset($users[$userId]) === false) {
                // Row points at a soft-deleted or vanished user; skip
                // silently so RBAC parity across active users is preserved.
                // If the user is truly missing (not soft-deleted), the
                // FK ON DELETE CASCADE has already removed the row; we
                // never see it here. Any residual row indicates only a
                // soft-delete-vs-membership mismatch, which is allowed.
                continue;
            }
            if (isset($roles[$roleId]) === false) {
                Log::error(self::LOG_UNKNOWN_ROLE, ['UserRoleId' => $userRoleId, 'RoleId' => $roleId, 'RequestId' => $requestId]);
                throw InternalException::custom(self::ERR_CORRUPT, 'UserRoles row references a RoleId absent from the Roles catalog snapshot.', [['Field' => self::FIELD_SCOPE . '/userRoles/' . $userRoleId, 'Rule' => self::RULE_UNKNOWN_ROLE]]);
            }
            $out[] = ['EmailLower' => $users[$userId]['EmailLower'], 'RoleName' => $roles[$roleId], 'IsActive' => $users[$userId]['IsActive']];
        }
        usort($out, static fn ($a, $b) => [$a['EmailLower'], $a['RoleName']] <=> [$b['EmailLower'], $b['RoleName']]);

        return $out;
    }

    /** @return list<array{Ptype:string, V0:string, V1:string, V2:?string, V3:?string, V4:?string, V5:?string}> */
    private function loadCasbinRules(string $requestId): array
    {
        $rows = $this->read(self::TABLE_CASBIN, $requestId, fn () => DB::connection(self::CONN)->table(self::TABLE_CASBIN)->orderBy('Id')->get(['Ptype', 'V0', 'V1', 'V2', 'V3', 'V4', 'V5']));

        return $this->mapCasbinRows($rows->all());
    }

    /**
     * @param  list<object>  $rows
     * @return list<array{Ptype:string, V0:string, V1:string, V2:?string, V3:?string, V4:?string, V5:?string}>
     */
    private function mapCasbinRows(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $out[] = ['Ptype' => (string) $row->Ptype, 'V0' => (string) $row->V0, 'V1' => (string) $row->V1, 'V2' => $row->V2 !== null ? (string) $row->V2 : null, 'V3' => $row->V3 !== null ? (string) $row->V3 : null, 'V4' => $row->V4 !== null ? (string) $row->V4 : null, 'V5' => $row->V5 !== null ? (string) $row->V5 : null];
        }
        usort($out, static fn ($a, $b) => [$a['Ptype'], $a['V0'], $a['V1'], (string) $a['V2'], (string) $a['V3'], (string) $a['V4'], (string) $a['V5']] <=> [$b['Ptype'], $b['V0'], $b['V1'], (string) $b['V2'], (string) $b['V3'], (string) $b['V4'], (string) $b['V5']]);

        return $out;
    }

    /**
     * @param  list<array{EmailLower:string, RoleName:string, IsActive:bool}>  $userRoles
     */
    private function bootstrapPresent(array $userRoles): bool
    {
        foreach ($userRoles as $row) {
            if ($row['IsActive'] && $row['RoleName'] === self::BOOTSTRAP_ROLE) {
                return true;
            }
        }

        return false;
    }

    /**
     * Canonical JSONL: UserRole rows first (ascending EmailLower, RoleName),
     * then CasbinRule rows (ascending Ptype, V0, V1, V2, V3, V4, V5). Per-row
     * keys sorted lexicographically, LF-terminated, UTF-8.
     *
     * @param  list<array{EmailLower:string, RoleName:string, IsActive:bool}>  $userRoles
     * @param  list<array{Ptype:string, V0:string, V1:string, V2:?string, V3:?string, V4:?string, V5:?string}>  $casbin
     */
    private function renderJsonl(array $userRoles, array $casbin): string
    {
        $out = '';
        foreach ($userRoles as $ur) {
            $row = ['EmailLower' => $ur['EmailLower'], 'IsActive' => $ur['IsActive'], 'RoleName' => $ur['RoleName'], 'RowType' => self::ROW_TYPE_USER_ROLE];
            $out .= json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
        }
        foreach ($casbin as $c) {
            $row = ['Ptype' => $c['Ptype'], 'RowType' => self::ROW_TYPE_CASBIN, 'V0' => $c['V0'], 'V1' => $c['V1'], 'V2' => $c['V2'], 'V3' => $c['V3'], 'V4' => $c['V4'], 'V5' => $c['V5']];
            $out .= json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
        }

        return $out;
    }

    /**
     * @template T
     * @param  \Closure():T  $fn
     * @return T
     */
    private function read(string $table, string $requestId, \Closure $fn)
    {
        try {
            return $fn();
        } catch (Throwable $e) {
            Log::error(self::LOG_UNREADABLE, ['Table' => $table, 'RequestId' => $requestId, 'Reason' => $e->getMessage()]);
            throw InternalException::custom(self::ERR_UNREADABLE, 'Root RBAC table unreadable at Export time: ' . $table, [['Field' => self::FIELD_SCOPE, 'Rule' => self::RULE_UNREADABLE]]);
        }
    }
}
