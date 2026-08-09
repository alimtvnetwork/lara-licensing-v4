<?php

declare(strict_types=1);

namespace App\Services\BR;

use App\Exceptions\DomainConflictException;
use App\Exceptions\InternalException;


use App\Contracts\BR\SecretsProviderContract;
use App\Domain\BR\BrCryptoConstants;
use App\Exceptions\LaraException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Plan 14 step 8. KEK epoch resolver.
 *
 * Reads `root.BrKekEpochs` (migration 7) and returns the epoch that a
 * caller must use to seal (Active only) or unseal (Active or Retired,
 * never Pending). Historical KEKs are read-only once retired
 * (INV-BR-EK-5).
 *
 * Contract:
 *  - `resolveActive()` returns the single row where State='Active';
 *    throws `BackupCorrupt` /encryption/epoch when absent.
 *  - `resolveForUnseal(int $epoch)` returns the row where Epoch=$epoch
 *    and State ∈ {Active, Retired}; throws `BackupCorrupt`
 *    /encryption/epoch (Rule=KekUnresolved) when absent, or
 *    `BackupKeyEpochRetired` when the row exists but is Pending.
 *  - `materialFor(array $epoch)` calls the SecretsProvider.
 *
 * Function bodies capped at 15 lines. No magic strings: every column,
 * pointer, and rule id comes from BrCryptoConstants or self::*.
 */
final class BrKekService
{
    private const CONN         = 'root';
    private const TABLE        = 'BrKekEpochs';
    private const STATE_ACTIVE  = 'Active';
    private const STATE_RETIRED = 'Retired';
    private const STATE_PENDING = 'Pending';

    public function __construct(private readonly SecretsProviderContract $secrets) {}

    /** @return array{Epoch:int, Kid:string, State:string, SecretsRef:string} */
    public function resolveActive(): array
    {
        $row = DB::connection(self::CONN)->table(self::TABLE)
            ->where('State', self::STATE_ACTIVE)
            ->select(['Epoch', 'Kid', 'State', 'SecretsRef'])
            ->first();
        if ($row === null) {
            Log::error('br.kek.no_active_epoch', ['State' => self::STATE_ACTIVE]);
            throw $this->corrupt(BrCryptoConstants::RULE_KEK_UNRESOLVED, self::STATE_ACTIVE);
        }

        return (array) $row;
    }

    /** @return array{Epoch:int, Kid:string, State:string, SecretsRef:string} */
    public function resolveForUnseal(int $epoch): array
    {
        $row = DB::connection(self::CONN)->table(self::TABLE)
            ->where('Epoch', $epoch)
            ->select(['Epoch', 'Kid', 'State', 'SecretsRef'])
            ->first();
        if ($row === null) {
            throw $this->corrupt(BrCryptoConstants::RULE_KEK_UNRESOLVED, (string) $epoch);
        }
        $state = (string) $row->State;

        return $this->assertUnsealable($state, $epoch, (array) $row);
    }

    public function materialFor(array $epochRow): string
    {
        $ref = (string) ($epochRow['SecretsRef'] ?? '');
        if ($ref === '') {
            throw $this->corrupt(BrCryptoConstants::RULE_KEK_UNRESOLVED, 'missing SecretsRef');
        }

        return $this->secrets->getKekMaterial($ref);
    }

    /**
     * @param array{Epoch:int, Kid:string, State:string, SecretsRef:string} $row
     * @return array{Epoch:int, Kid:string, State:string, SecretsRef:string}
     */
    private function assertUnsealable(string $state, int $epoch, array $row): array
    {
        if ($state === self::STATE_ACTIVE || $state === self::STATE_RETIRED) {
            return $row;
        }
        // Pending: exists in the ledger but never activated. Refuse.
        Log::warning('br.kek.epoch_not_unsealable', ['Epoch' => $epoch, 'State' => $state]);
        throw DomainConflictException::custom(BrCryptoConstants::ERR_KEK_EPOCH_RETIRED,
            'kek epoch not usable for unseal',
            [['Field' => BrCryptoConstants::PTR_EPOCH, 'Rule' => BrCryptoConstants::RULE_KEK_RETIRED, 'Value' => (string) $epoch]]
        );
    }

    private function corrupt(string $rule, string $value): LaraException
    {
        return InternalException::custom(BrCryptoConstants::ERR_BACKUP_CORRUPT,
            'kek epoch unresolved',
            [['Field' => BrCryptoConstants::PTR_EPOCH, 'Rule' => $rule, 'Value' => $value]]
        );
    }
}
