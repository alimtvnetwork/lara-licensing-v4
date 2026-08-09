<?php

namespace App\Support;

use JsonException;

/**
 * Canonicalizes JSON request bodies for idempotency hashing per
 * spec/21-app/29-idempotency-lifecycle.md v1.0.0 §Canonicalization.
 *
 * Rules:
 *  - Parse JSON body (empty body treated as `null`).
 *  - Sort object keys ascending by Unicode code-point (PascalCase preserved).
 *  - Preserve array order.
 *  - Retain null-valued keys.
 *  - Serialize with no whitespace, UTF-8, no BOM.
 *  - SHA-256 the bytes, lowercase hex.
 */
final class IdempotencyCanonicalizer
{
    public static function hashBody(string $rawBody): string
    {
        return hash('sha256', self::canonicalize($rawBody));
    }

    public static function canonicalize(string $rawBody): string
    {
        if ($rawBody === '') {
            return 'null';
        }
        try {
            $decoded = json_decode($rawBody, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            // Idempotency layer does not decide InvalidJson; let the handler produce
            // the ValidationFailed / InvalidJson envelope. Hash the raw bytes so a
            // retry with the SAME malformed body still hits the same slot.
            return 'raw:' . $rawBody;
        }

        return self::encodeCanonical($decoded);
    }

    /** @param mixed $value */
    private static function encodeCanonical($value): string
    {
        if (is_array($value)) {
            return self::encodeArrayOrObject($value);
        }

        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** @param array<mixed> $value */
    private static function encodeArrayOrObject(array $value): string
    {
        if (self::isList($value)) {
            $parts = array_map([self::class, 'encodeCanonical'], $value);

            return '[' . implode(',', $parts) . ']';
        }
        ksort($value, SORT_STRING);
        $pairs = [];
        foreach ($value as $key => $child) {
            $pairs[] = json_encode((string) $key, JSON_UNESCAPED_UNICODE) . ':' . self::encodeCanonical($child);
        }

        return '{' . implode(',', $pairs) . '}';
    }

    /** @param array<mixed> $value */
    private static function isList(array $value): bool
    {
        return array_is_list($value);
    }
}
