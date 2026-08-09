<?php

declare(strict_types=1);

namespace App\Services\BR;

use App\Exceptions\InternalException;


use App\Contracts\BR\SecretsProviderContract;
use App\Domain\BR\BrCryptoConstants;
use App\Exceptions\LaraException;
use Illuminate\Support\Facades\Config;

/**
 * Plan 14 step 8. Default SecretsProviderContract binding.
 *
 * Reads a base64url-encoded 32-byte KEK from
 * `config('br.kek_material')[SecretsRef]`. In production this array
 * is hydrated from the process env (never checked into source). In
 * tests, callers stub it with `Config::set('br.kek_material', ...)`.
 *
 * On any lookup / length / decode failure this throws
 * `InternalException::custom('BackupCorrupt', ..., [Field=/encryption/epoch,
 * Rule=KekUnresolved])` so callers get a spec-conformant error.
 */
final class ConfigSecretsProvider implements SecretsProviderContract
{
    private const CONFIG_KEY = 'br.kek_material';

    public function getKekMaterial(string $ref): string
    {
        $map = Config::get(self::CONFIG_KEY, [])
        $encoded = is_array($map) ? ($map[$ref] ?? null) : null;
        if (!is_string($encoded) || $encoded === '') {
            throw $this->fail(BrCryptoConstants::RULE_KEK_UNRESOLVED, $ref);
        }
        $raw = self::base64UrlDecode($encoded);
        if ($raw === false || strlen($raw) !== BrCryptoConstants::KEK_LEN) {
            throw $this->fail(BrCryptoConstants::RULE_KEK_LEN, $ref);
        }

        return $raw;
    }

    /** @return false|string */
    private static function base64UrlDecode(string $s)
    {
        $pad = strlen($s) % 4;
        if ($pad !== 0) {
            $s .= str_repeat('=', 4 - $pad);
        }

        return base64_decode(strtr($s, '-_', '+/'), true);
    }

    private function fail(string $rule, string $ref): LaraException
    {
        return InternalException::custom(BrCryptoConstants::ERR_BACKUP_CORRUPT,
            'kek material unavailable',
            [['Field' => BrCryptoConstants::PTR_EPOCH, 'Rule' => $rule, 'Value' => $ref]]
        );
    }
}
