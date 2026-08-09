<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\DomainConflictException;


use App\Exceptions\LaraException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Plan 06 step 39 (binding wire-in). Machine + user binding writes for
 * `POST /Api/Portal/Verify/Final`.
 *
 * Root cause this service fixes (one sentence): `VerifyController::final`
 * was returning `MachineBindingId`/`UserBindingId` as literal `null`
 * because the tables existed but no code enforced quotas, cooldowns, or
 * INSERTed rows, so `spec/21-app/30-machine-bindings.md` AC-MB-003 /
 * AC-MB-004 / AC-MB-006 / AC-MB-007 and
 * `spec/21-app/11-api-contracts/03-verification-contracts.md` line 53
 * `LicenseUserLimit` (409) were silently unimplemented.
 *
 * Contract:
 *  - `applyMachineBinding`  runs the AC-MB-003 quota, AC-MB-004 no-op
 *                           refresh, AC-MB-006 cooldown and AC-MB-007
 *                           re-INSERT rules. Returns the active
 *                           `MachineBindingId` (existing or new). Caller
 *                           MUST run this inside a shard transaction so
 *                           the SELECT FOR UPDATE observes a stable set.
 *  - `applyUserBinding`     runs the `UserCount` cap and no-op refresh
 *                           for `UserBindings`. Same transactional rule.
 *
 * Not responsible for:
 *  - Emitting `MachineBound`/`MachineUnbound` audit rows: audit sink
 *    lands in Plan 06 audit step. This service logs at INFO / WARN with
 *    the outcome tokens from spec 30 §Observability so log-based
 *    correlation still works today.
 */
final class BindingService
{
    private const CONN = 'shard';
    private const OUTCOME_BOUND = 'Bound';
    private const OUTCOME_NO_OP_REFRESH = 'NoOpRefresh';
    private const OUTCOME_QUOTA_EXCEEDED = 'QuotaExceeded';
    private const OUTCOME_COOLDOWN_ACTIVE = 'CooldownActive';
    private const LOG_MACHINE = 'binding.machine';
    private const LOG_USER = 'binding.user';
    private const FINGERPRINT_HEAD_CHARS = 8;

    /**
     * @return int The active MachineBindingId for this (LicenseId, FingerprintHash).
     */
    public function applyMachineBinding(
        int $licenseId,
        string $fingerprintHash,
        int $maxConcurrent,
        string $requestId,
    ): int {
        $now = Carbon::now();
        $existing = $this->activeMachineRow($licenseId, $fingerprintHash);
        if ($existing !== null) {
            $this->refreshMachineLastSeen((int) $existing->MachineBindingId, $now);
            $this->logMachine(self::OUTCOME_NO_OP_REFRESH, $requestId, $licenseId, $fingerprintHash, (int) $existing->MachineBindingId);

            return (int) $existing->MachineBindingId;
        }
        $this->assertMachineCooldownClear($licenseId, $fingerprintHash, $requestId, $now);
        $this->assertMachineQuota($licenseId, $maxConcurrent, $requestId);
        $id = $this->insertMachineBinding($licenseId, $fingerprintHash, $now);
        $this->logMachine(self::OUTCOME_BOUND, $requestId, $licenseId, $fingerprintHash, $id);

        return $id;
    }

    /**
     * @return int The active UserBindingId for this (LicenseId, UserIdentifier).
     */
    public function applyUserBinding(
        int $licenseId,
        string $userIdentifier,
        int $maxUsers,
        string $requestId,
    ): int {
        $now = Carbon::now();
        $existing = $this->activeUserRow($licenseId, $userIdentifier);
        if ($existing !== null) {
            $this->refreshUserLastSeen((int) $existing->UserBindingId, $now);
            $this->logUser(self::OUTCOME_NO_OP_REFRESH, $requestId, $licenseId, (int) $existing->UserBindingId);

            return (int) $existing->UserBindingId;
        }
        $this->assertUserQuota($licenseId, $maxUsers, $requestId);
        $id = $this->insertUserBinding($licenseId, $userIdentifier, $now);
        $this->logUser(self::OUTCOME_BOUND, $requestId, $licenseId, $id);

        return $id;
    }

    private function activeMachineRow(int $licenseId, string $fingerprintHash): ?object
    {
        return DB::connection(self::CONN)
            ->table('MachineBindings')
            ->where('LicenseId', $licenseId)
            ->where('FingerprintHash', $fingerprintHash)
            ->whereNull('ReleasedAt')
            ->lockForUpdate()
            ->first();
    }

    private function refreshMachineLastSeen(int $id, Carbon $now): void
    {
        DB::connection(self::CONN)
            ->table('MachineBindings')
            ->where('MachineBindingId', $id)
            ->update(['LastSeenAt' => $now, 'UpdatedAt' => $now]);
    }

    private function assertMachineCooldownClear(int $licenseId, string $fingerprintHash, string $requestId, Carbon $now): void
    {
        $cooldown = DB::connection(self::CONN)
            ->table('MachineBindings')
            ->where('LicenseId', $licenseId)
            ->where('FingerprintHash', $fingerprintHash)
            ->whereNotNull('RebindCooldownUntil')
            ->where('RebindCooldownUntil', '>', $now)
            ->orderByDesc('RebindCooldownUntil')
            ->first();
        if ($cooldown === null) {
            return;
        }
        $retryAfter = max(1, Carbon::parse((string) $cooldown->RebindCooldownUntil)->diffInSeconds($now));
        $this->logMachine(self::OUTCOME_COOLDOWN_ACTIVE, $requestId, $licenseId, $fingerprintHash, null);
        throw DomainConflictException::custom('MachineRebindCooldownActive',
            'Rebind cooldown active for this fingerprint.',
            [['Field' => 'FingerprintHash', 'Rule' => 'CooldownActive', 'RetryAfterSeconds' => $retryAfter]],
        )
    }

    private function assertMachineQuota(int $licenseId, int $maxConcurrent, string $requestId): void
    {
        $current = DB::connection(self::CONN)
            ->table('MachineBindings')
            ->where('LicenseId', $licenseId)
            ->whereNull('ReleasedAt')
            ->count();
        if ($current >= $maxConcurrent) {
            $this->logMachine(self::OUTCOME_QUOTA_EXCEEDED, $requestId, $licenseId, null, null);
            throw DomainConflictException::custom('LicenseMachineLimit',
                'License concurrent machine binding limit reached.',
                [['Field' => 'MachineBindings', 'Rule' => 'QuotaExceeded', 'CurrentCount' => $current, 'MaxCount' => $maxConcurrent]],
            )
        }
    }

    private function insertMachineBinding(int $licenseId, string $fingerprintHash, Carbon $now): int
    {
        return (int) DB::connection(self::CONN)
            ->table('MachineBindings')
            ->insertGetId([
                'LicenseId' => $licenseId,
                'FingerprintHash' => $fingerprintHash,
                'FirstSeenAt' => $now,
                'LastSeenAt' => $now,
                'CreatedAt' => $now,
                'UpdatedAt' => $now,
            ], 'MachineBindingId');
    }

    private function activeUserRow(int $licenseId, string $userIdentifier): ?object
    {
        return DB::connection(self::CONN)
            ->table('UserBindings')
            ->where('LicenseId', $licenseId)
            ->where('UserIdentifier', $userIdentifier)
            ->where('IsReleased', false)
            ->lockForUpdate()
            ->first();
    }

    private function refreshUserLastSeen(int $id, Carbon $now): void
    {
        DB::connection(self::CONN)
            ->table('UserBindings')
            ->where('UserBindingId', $id)
            ->update(['LastSeenAt' => $now, 'UpdatedAt' => $now]);
    }

    private function assertUserQuota(int $licenseId, int $maxUsers, string $requestId): void
    {
        $current = DB::connection(self::CONN)
            ->table('UserBindings')
            ->where('LicenseId', $licenseId)
            ->where('IsReleased', false)
            ->count();
        if ($current >= $maxUsers) {
            $this->logUser(self::OUTCOME_QUOTA_EXCEEDED, $requestId, $licenseId, null);
            throw DomainConflictException::custom('LicenseUserLimit',
                'License user binding limit reached.',
                [['Field' => 'UserBindings', 'Rule' => 'QuotaExceeded', 'CurrentCount' => $current, 'MaxCount' => $maxUsers]],
            )
        }
    }

    private function insertUserBinding(int $licenseId, string $userIdentifier, Carbon $now): int
    {
        return (int) DB::connection(self::CONN)
            ->table('UserBindings')
            ->insertGetId([
                'LicenseId' => $licenseId,
                'UserIdentifier' => $userIdentifier,
                'FirstSeenAt' => $now,
                'LastSeenAt' => $now,
                'IsReleased' => false,
                'CreatedAt' => $now,
                'UpdatedAt' => $now,
            ], 'UserBindingId');
    }

    private function logMachine(string $outcome, string $requestId, int $licenseId, ?string $fingerprintHash, ?int $bindingId): void
    {
        $level = in_array($outcome, [self::OUTCOME_QUOTA_EXCEEDED, self::OUTCOME_COOLDOWN_ACTIVE], true) ? 'warning' : 'info';
        $context = [
            'requestId' => $requestId,
            'licenseId' => $licenseId,
            'outcome' => $outcome,
            'machineBindingId' => $bindingId,
            'fingerprintHead' => $fingerprintHash === null ? null : substr($fingerprintHash, 0, self::FINGERPRINT_HEAD_CHARS),
        ];
        Log::log($level, self::LOG_MACHINE, $context);
    }

    private function logUser(string $outcome, string $requestId, int $licenseId, ?int $bindingId): void
    {
        $level = $outcome === self::OUTCOME_QUOTA_EXCEEDED ? 'warning' : 'info';
        Log::log($level, self::LOG_USER, [
            'requestId' => $requestId,
            'licenseId' => $licenseId,
            'outcome' => $outcome,
            'userBindingId' => $bindingId,
        ]);
    }

    public static function defaultMaxConcurrent(): int
    {
        return (int) Config::get('lara.binding_defaults.MaxConcurrentBindings', 1);
    }

    public static function defaultMaxUsers(): int
    {
        return (int) Config::get('lara.binding_defaults.MaxUserCount', 1);
    }

    public static function rebindCooldownMinutes(): int
    {
        return (int) Config::get('lara.binding_defaults.RebindCooldownMinutes', 15);
    }
}
