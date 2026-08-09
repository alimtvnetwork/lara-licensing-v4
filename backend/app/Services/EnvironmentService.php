<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\InternalException;


use App\Exceptions\LaraException;
use Illuminate\Support\Facades\Config;

/**
 * Plan 06 step 42. Centralized environment closed-set validator.
 *
 * Root cause this service closes (one sentence): environment ordinal
 * resolution is duplicated across `Portal\VerifyController` and
 * `Admin\LicenseController`, so any drift in the closed set silently
 * violates AC-LENV-004 in one call path while remaining correct in the
 * other.
 *
 * Normative source: spec/21-app/44-environments.md v1.0.0 §2 (closed set
 * `Production`, `Staging`, `Development`) and §3 (gate 2 opaque integer
 * ordinals). Ordinals are 1-based positions into
 * `config('lara.environments')`. An `Environments` catalog table is a
 * later Plan 06 substrate; when it lands, replace the config lookup with
 * a DB query without changing this service's public shape.
 *
 * Public contract:
 *  - `ordinalToName(int)` -> string, or throws `ValidationFailed`
 *    with `Rule=MembershipRequired` (AC-LENV-002).
 *  - `nameToOrdinal(string)` -> int, or throws `ServerConfigurationError`
 *    (data invariant: a persisted name outside the closed set is a
 *    server bug, not a client fault).
 *  - `assertMatch(licensedName, requestedOrdinal)` throws
 *    `EnvironmentMismatch` with the opaque `<Requested>/<Licensed>`
 *    Details.Value per AC-API-VER-010. Never leaks the licensed name in
 *    the exception payload.
 *  - `ordinalMax()` -> int, for validator `max:` rules.
 */
final class EnvironmentService
{
    private const CONFIG_KEY = 'lara.environments';
    private const ERROR_MEMBERSHIP = 'ValidationFailed';
    private const ERROR_CONFIG = 'ServerConfigurationError';
    private const ERROR_MISMATCH = 'EnvironmentMismatch';
    private const RULE_MEMBERSHIP = 'MembershipRequired';
    private const RULE_MISMATCH = 'Mismatch';
    private const FIELD_ENVIRONMENT_ID = 'EnvironmentId';
    private const FIELD_ENVIRONMENT = 'Environment';

    /** @return list<string> */
    private function catalog(): array
    {
        $env = (array) Config::get(self::CONFIG_KEY, []);
        $out = [];
        foreach ($env as $name) {
            if (is_string($name) && $name !== '') {
                $out[] = $name;
            }
        }
        if ($out === []) {
            throw InternalException::custom(self::ERROR_CONFIG,
                'Environments closed set is not configured.',
                [['Field' => 'Environments', 'Rule' => 'Empty']],
            );
        }

        return $out;
    }

    public function ordinalMax(): int
    {
        return count($this->catalog());
    }

    public function ordinalToName(int $ordinal): string
    {
        $catalog = $this->catalog();
        if ($ordinal < 1 || $ordinal > count($catalog)) {
            throw InternalException::custom(self::ERROR_MEMBERSHIP,
                'EnvironmentId is not a member of the closed set.',
                [['Field' => self::FIELD_ENVIRONMENT_ID, 'Rule' => self::RULE_MEMBERSHIP]],
            );
        }

        return $catalog[$ordinal - 1];
    }

    public function nameToOrdinal(string $name): int
    {
        foreach ($this->catalog() as $index => $configured) {
            if ($configured === $name) {
                return $index + 1;
            }
        }
        throw InternalException::custom(self::ERROR_CONFIG,
            'Persisted environment name is not in the configured closed set.',
            [],
        );
    }

    /**
     * Throws `EnvironmentMismatch` when the requested ordinal does not
     * match the licensed name. Details carry only opaque integer
     * ordinals per AC-API-VER-010; the licensed name is never included.
     */
    public function assertMatch(string $licensedName, int $requestedOrdinal): void
    {
        $licensedOrdinal = $this->nameToOrdinal($licensedName);
        if ($licensedOrdinal === $requestedOrdinal) {
            return;
        }
        throw InternalException::custom(self::ERROR_MISMATCH,
            'Requested environment does not match license environment.',
            [[
                'Field' => self::FIELD_ENVIRONMENT,
                'Rule' => self::RULE_MISMATCH,
                'Value' => $requestedOrdinal . '/' . $licensedOrdinal,
            ]],
        );
    }
}
