<?php

declare(strict_types=1);

namespace App\Services\BR;

use App\Exceptions\InternalException;


use App\Domain\BR\BrFlagId;
use App\Domain\BR\BrFlagValue;
use App\Exceptions\LaraException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Plan 14 step 4. Controller-layer feature-flag gate for the
 * Backup/Restore module.
 *
 * Contract:
 *  - `valueOf(flag)` reads the tri-state value from Root.FeatureFlags.
 *    Missing rows resolve to `off` (default per spec 25) and emit a
 *    warning so operators can detect drift between the migration seed
 *    and runtime.
 *  - `assertEnabled(flag)` throws `LaraException` with code
 *    `FeatureNotAvailable` BEFORE the caller acquires any advisory
 *    lock, satisfying INV-BR-MG-3. The 503 `ModuleDisabled` envelope
 *    reserved by spec 25 §"Kill Switch" will be layered on top of
 *    this service by the endpoint controllers in later Plan 14 steps.
 *  - `assertKillSwitchOff()` short-circuits every BR endpoint when
 *    `br.kill-switch=on`; endpoints MUST call this first.
 *
 * Function bodies capped at 15 lines. No magic strings: flag IDs live
 * in `BrFlagId`, values in `BrFlagValue`, error code in the shared
 * catalogue.
 */
final class BrFeatureFlagService
{
    private const CONN = 'root';
    private const TABLE = 'FeatureFlags';
    private const ERR_DISABLED = 'FeatureNotAvailable';

    /** @var array<string, BrFlagValue> per-request memoisation */
    private array $cache = [];

    public function valueOf(BrFlagId $flag): BrFlagValue
    {
        $flagId = $flag->value;
        if (isset($this->cache[$flagId])) {
            return $this->cache[$flagId];
        }
        $row = DB::connection(self::CONN)
            ->table(self::TABLE)
            ->where('FlagId', $flagId)
            ->value('Value');
        $value = $this->coerce($flagId, $row);
        $this->cache[$flagId] = $value;

        return $value;
    }

    public function isEnabled(BrFlagId $flag): bool
    {
        return $this->valueOf($flag)->isEnabled();
    }

    public function isShadow(BrFlagId $flag): bool
    {
        return $this->valueOf($flag)->isShadow();
    }

    /**
     * INV-BR-MG-3 gate: MUST run before any advisory-lock acquisition.
     * @throws LaraException code=FeatureNotAvailable (501).
     */
    public function assertEnabled(BrFlagId $flag): void
    {
        $value = $this->valueOf($flag);
        if ($value->isOff()) {
            throw InternalException::custom(self::ERR_DISABLED,
                "Feature flag '{$flag->value}' is disabled in this environment.",
                [['Field' => 'FeatureFlag', 'Rule' => 'Disabled', 'Value' => $flag->value]],
            );
        }
    }

    /** BR kill-switch gate. When on, every BR endpoint MUST short-circuit. */
    public function assertKillSwitchOff(): void
    {
        $value = $this->valueOf(BrFlagId::KillSwitch);
        if (! $value->isOff()) {
            throw InternalException::custom(self::ERR_DISABLED,
                'Backup/Restore module is halted by kill-switch.',
                [['Field' => 'FeatureFlag', 'Rule' => 'KillSwitchOn', 'Value' => BrFlagId::KillSwitch->value]],
                null,
                ['Retry-After' => '60'],
            );
        }
    }

    public function forgetCache(): void
    {
        $this->cache = [];
    }

    private function coerce(string $flagId, mixed $raw): BrFlagValue
    {
        if ($raw === null) {
            Log::warning('br.feature_flag.missing_row', ['FlagId' => $flagId, 'FallbackTo' => BrFlagValue::Off->value]);

            return BrFlagValue::Off;
        }
        $value = BrFlagValue::tryFrom((string) $raw);
        if ($value === null) {
            Log::error('br.feature_flag.invalid_value', ['FlagId' => $flagId, 'RawValue' => (string) $raw]);

            return BrFlagValue::Off;
        }

        return $value;
    }
}
