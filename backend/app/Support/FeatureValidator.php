<?php

declare(strict_types=1);

namespace App\Support;

use App\Exceptions\LaraException;
use App\Exceptions\AuthException;
use App\Exceptions\ValidationException;
use App\Exceptions\RateLimitException;
use App\Exceptions\NotFoundException;
use App\Exceptions\DomainConflictException;
use App\Exceptions\InternalException;

/**
 * Plan 06 step 31. Closed-set validator for License Feature writes.
 *
 * Enforces the registry defined in spec/21-app/45-license-features.md §2
 * and the typed-value contract in §3. Callers pass a raw array
 * `[FeatureKey => Value, ...]` and receive either the normalised map or
 * a LaraException with the correct ErrorCode:
 *
 *  - Unknown key (or forbidden synonym)  -> FeatureUnknown (400)
 *  - Value shape mismatch                -> FeatureValueInvalid (400)
 *
 * The registry itself is read from `config('lara.feature_registry')` so
 * a new key requires only a config bump + spec 45 version bump, per the
 * no-magic-literals rule.
 */
final class FeatureValidator
{
    private const VALUE_TYPE_BOOLEAN = 'Boolean';
    private const VALUE_TYPE_NUMBER = 'Number';
    private const VALUE_TYPE_STRING = 'String';
    private const STRING_MAX_LEN = 128;

    /**
     * @param array<string, mixed> $features
     * @return array<string, bool|int|float|string>
     */
    public static function normalize(array $features): array
    {
        $registry = self::readRegistry();
        $out = [];
        foreach ($features as $key => $value) {
            $spec = $registry[$key] ?? null;
            if ($spec === null) {
                self::throwUnknown((string) $key);
            }
            $out[$key] = self::assertValue((string) $key, $spec, $value);
        }

        return $out;
    }

    /**
     * @return array<string, array{ValueType:string, AllowedValues?:array<int,string>, IntegerOnly?:bool}>
     */
    private static function readRegistry(): array
    {
        $reg = (array) config('lara.feature_registry', []);
        if ($reg === []) {
            throw InternalException::serverError('ServerError',
                'Feature registry is empty; config drift.',
                [['Field' => 'config.lara.feature_registry', 'Rule' => 'Missing']],
            );
        }

        return $reg;
    }

    private static function throwUnknown(string $key): never
    {
        throw DomainConflictException::custom('FeatureUnknown',
            'FeatureKey is not part of the closed registry.',
            [['Field' => 'FeatureKey', 'Rule' => 'UnknownKey', 'Value' => $key]],
        )
    }

    /**
     * @param array{ValueType:string, AllowedValues?:array<int,string>, IntegerOnly?:bool} $spec
     */
    private static function assertValue(string $key, array $spec, mixed $value): bool|int|float|string
    {
        return match ($spec['ValueType']) {
            self::VALUE_TYPE_BOOLEAN => self::assertBoolean($key, $value),
            self::VALUE_TYPE_NUMBER => self::assertNumber($key, $spec, $value),
            self::VALUE_TYPE_STRING => self::assertString($key, $spec, $value),
            default => self::throwInvalid($key, 'ValueTypeUnknown'),
        };
    }

    private static function assertBoolean(string $key, mixed $value): bool
    {
        if (is_bool($value) === false) {
            self::throwInvalid($key, 'ExpectedBoolean');
        }

        return $value;
    }

    /**
     * @param array{ValueType:string, IntegerOnly?:bool} $spec
     */
    private static function assertNumber(string $key, array $spec, mixed $value): int|float
    {
        if (!is_int($value) && !is_float($value)) {
            self::throwInvalid($key, 'ExpectedNumber');
        }
        if (is_float($value) && !is_finite($value)) {
            self::throwInvalid($key, 'NumberNotFinite');
        }
        if (($spec['IntegerOnly'] ?? false) && !is_int($value)) {
            self::throwInvalid($key, 'ExpectedInteger');
        }

        return $value;
    }

    /**
     * @param array{ValueType:string, AllowedValues?:array<int,string>} $spec
     */
    private static function assertString(string $key, array $spec, mixed $value): string
    {
        if (is_string($value) === false) {
            self::throwInvalid($key, 'ExpectedString');
        }
        $allowed = $spec['AllowedValues'] ?? null;
        if (is_array($allowed) && !in_array($value, $allowed, true)) {
            self::throwInvalid($key, 'ValueNotInAllowedSet');
        }
        if ($allowed === null && strlen($value) > self::STRING_MAX_LEN) {
            self::throwInvalid($key, 'StringTooLong');
        }

        return $value;
    }

    private static function throwInvalid(string $key, string $rule): never
    {
        throw DomainConflictException::custom('FeatureValueInvalid',
            'FeatureValue does not match the declared ValueType.',
            [['Field' => 'FeatureValue', 'Rule' => $rule, 'Value' => $key]],
        )
    }
}
