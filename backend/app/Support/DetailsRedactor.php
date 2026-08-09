<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Plan 11 step 33: redact sensitive fields inside a LaraException `$details`
 * payload before it is (a) written to the failure envelope returned to the
 * caller AND (b) logged to the `lara` / `lara-diag` channels.
 *
 * Root cause this closes: `LaraException::make(..., $details)` accepts an
 * arbitrary array. Call sites in FormRequests and services routinely pass
 * `{Field: 'password', Value: <raw>}` items (see backend/app/Http/Requests
 * FormRequest validation helpers). Without redaction the raw `Value` echoes
 * into the JSON envelope AND into the `lara.exception` structured log,
 * duplicating the leak vector TraceRedactor closes for stack-frame args
 * (spec/03-error-manage §4.2 "no PII/credential may cross the API surface
 * or the log surface" and AC-ERR-005 in spec/21-app/12-error-taxonomy.md).
 *
 * Not a symptom patch: operates on the canonical detail item shape
 *   {Field: string, Rule: string, Value?: scalar, Message?: string, ...}
 * matched against the closed-set `lara.trace_redact_keys` (same list the
 * stack-frame redactor consumes), and recurses into arbitrary nested
 * arrays with a depth cap. Non-array details are returned unchanged.
 *
 * Deliberately shares the sensitive-key list with TraceRedactor so a new
 * secret category is added in exactly one place (config/lara.php line 596).
 */
final class DetailsRedactor
{
    private const MASK = '***REDACTED***';
    private const MAX_DEPTH = 4;

    /**
     * @param  array<mixed>  $details
     * @return array<mixed>
     */
    public static function redact(array $details): array
    {
        if ($details === []) {
            return $details;
        }
        $keys = self::sensitiveKeys();
        if ($keys === []) {
            return $details;
        }

        return self::walk($details, $keys, 0);
    }

    /** @return list<string> */
    private static function sensitiveKeys(): array
    {
        $raw = (array) config('lara.trace_redact_keys', []);

        return array_values(array_map('strtolower', array_map('strval', $raw)));
    }

    /**
     * @param  array<mixed>  $arr
     * @param  list<string>  $keys
     * @return array<mixed>
     */
    private static function walk(array $arr, array $keys, int $depth): array
    {
        // Canonical validation-detail shape: an assoc item with a `Field`
        // naming the offending input plus a sibling `Value` (raw input) and
        // optional `Message`. When the Field name matches a sensitive
        // substring, mask BOTH Value and Message: the Message often echoes
        // the raw value ("password 'hunter2' is too short").
        if (self::isSensitiveDetailItem($arr, $keys)) {
            return self::maskDetailItem($arr);
        }

        $out = [];
        foreach ($arr as $k => $v) {
            $out[$k] = self::redactValue($k, $v, $keys, $depth);
        }

        return $out;
    }

    /**
     * @param  array<mixed>  $item
     * @param  list<string>  $keys
     */
    private static function isSensitiveDetailItem(array $item, array $keys): bool
    {
        $field = $item['Field'] ?? null;
        if (! is_string($field) || $field === '') {
            return false;
        }

        return self::keyIsSensitive($field, $keys);
    }

    /**
     * @param  array<mixed>  $item
     * @return array<mixed>
     */
    private static function maskDetailItem(array $item): array
    {
        if (array_key_exists('Value', $item)) {
            $item['Value'] = self::MASK;
        }
        if (array_key_exists('Message', $item) && is_string($item['Message'])) {
            $item['Message'] = self::MASK;
        }

        return $item;
    }

    /** @param  list<string>  $keys */
    private static function redactValue(int|string $key, mixed $value, array $keys, int $depth): mixed
    {
        // Redact by key name at any nesting level (arbitrary detail payloads
        // may include `access_token`, `cookie`, etc. as bare keys).
        if (is_string($key) && self::keyIsSensitive($key, $keys)) {
            return is_array($value) ? self::MASK : self::MASK;
        }
        if (is_array($value)) {
            if ($depth >= self::MAX_DEPTH) {
                return self::MASK; // never leak below the depth cap
            }

            return self::walk($value, $keys, $depth + 1);
        }
        if (is_string($value) && self::looksLikeToken($value)) {
            return self::MASK;
        }

        return $value;
    }

    /** @param  list<string>  $keys */
    private static function keyIsSensitive(string $key, array $keys): bool
    {
        $lower = strtolower($key);
        foreach ($keys as $needle) {
            if ($needle !== '' && str_contains($lower, $needle)) {
                return true;
            }
        }

        return false;
    }

    private static function looksLikeToken(string $v): bool
    {
        // Same heuristic contract as TraceRedactor::looksLikeToken so both
        // surfaces agree on what "looks like a credential".
        if (strlen($v) < 32) {
            return false;
        }
        if (preg_match('/\s/', $v) === 1) {
            return false;
        }

        return preg_match('/^[A-Za-z0-9_\-\.\/=+]+$/', $v) === 1;
    }
}
