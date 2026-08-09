<?php

declare(strict_types=1);

namespace App\Support;

use Throwable;

/**
 * Plan 11 SS-01 (step 8). Redacts sensitive scalar args from a throwable's
 * stack trace before it hits the `lara-diag` Monolog channel. Fixes the
 * v0.409.0 leak vector where `getTraceAsString()` may inline function
 * argument values (tokens, passwords) when `zend.exception_ignore_args=0`.
 *
 * Not a symptom patch: operates on the structured `getTrace()` frames so
 * key-based redaction is deterministic, then serialises ourselves. String
 * scanning of `getTraceAsString()` output would be regex-fragile and would
 * miss non-ASCII values.
 */
final class TraceRedactor
{
    private const MASK = '***REDACTED***';

    /** @return array<int, array<string,mixed>> */
    public static function redactFrames(Throwable $e): array
    {
        $keys = self::sensitiveKeys();
        $out = [];
        foreach ($e->getTrace() as $frame) {
            $out[] = self::redactFrame($frame, $keys);
        }

        return $out;
    }

    public static function redactString(Throwable $e): string
    {
        $frames = self::redactFrames($e);
        $lines = [];
        foreach ($frames as $i => $f) {
            $lines[] = self::formatFrame($i, $f);
        }
        $lines[] = '#'.count($frames).' {main}';

        return implode("\n", $lines);
    }

    /** @return list<string> */
    private static function sensitiveKeys(): array
    {
        $raw = (array) config('lara.trace_redact_keys', []);

        return array_values(array_map('strtolower', array_map('strval', $raw)));
    }

    /**
     * @param array<string,mixed> $frame
     * @param list<string> $keys
     * @return array<string,mixed>
     */
    private static function redactFrame(array $frame, array $keys): array
    {
        $args = $frame['args'] ?? null;
        unset($frame['args']);
        if (is_array($args)) {
            $frame['args'] = self::redactArgs($args, $keys);
        }

        return $frame;
    }

    /**
     * @param array<mixed> $args
     * @param list<string> $keys
     * @return array<mixed>
     */
    private static function redactArgs(array $args, array $keys): array
    {
        $out = [];
        foreach ($args as $k => $v) {
            $out[$k] = self::redactValue($k, $v, $keys, 0);
        }

        return $out;
    }

    /** @param list<string> $keys */
    private static function redactValue(int|string $key, mixed $value, array $keys, int $depth): mixed
    {
        if (is_string($key) && self::keyIsSensitive($key, $keys)) {
            return self::MASK;
        }
        if (is_array($value) && $depth < 4) {
            return self::redactNested($value, $keys, $depth + 1);
        }
        if (is_string($value) && self::looksLikeToken($value)) {
            return self::MASK;
        }
        if (is_object($value)) {
            return '<object '.$value::class.'>';
        }

        return $value;
    }

    /**
     * @param array<mixed> $arr
     * @param list<string> $keys
     * @return array<mixed>
     */
    private static function redactNested(array $arr, array $keys, int $depth): array
    {
        $out = [];
        foreach ($arr as $k => $v) {
            $out[$k] = self::redactValue($k, $v, $keys, $depth);
        }

        return $out;
    }

    /** @param list<string> $keys */
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
        // Heuristic: long, no whitespace, high-entropy-ish charset. Kept
        // conservative to avoid masking normal error messages.
        if (strlen($v) < 32) {
            return false;
        }
        if (preg_match('/\s/', $v) === 1) {
            return false;
        }

        return preg_match('/^[A-Za-z0-9_\-\.\/=+]+$/', $v) === 1;
    }

    /** @param array<string,mixed> $frame */
    private static function formatFrame(int $i, array $frame): string
    {
        $file = (string) ($frame['file'] ?? '[internal]');
        $line = isset($frame['line']) ? (string) $frame['line'] : '?';
        $call = self::formatCall($frame);
        $args = self::formatArgs($frame['args'] ?? []);

        return sprintf('#%d %s(%s): %s(%s)', $i, $file, $line, $call, $args);
    }

    /** @param array<string,mixed> $frame */
    private static function formatCall(array $frame): string
    {
        $class = (string) ($frame['class'] ?? '');
        $type = (string) ($frame['type'] ?? '');
        $fn = (string) ($frame['function'] ?? '{closure}');

        return $class.$type.$fn;
    }

    /** @param array<mixed> $args */
    private static function formatArgs(array $args): string
    {
        $parts = [];
        foreach ($args as $a) {
            $parts[] = self::scalarRepr($a);
        }

        return implode(', ', $parts);
    }

    private static function scalarRepr(mixed $a): string
    {
        if ($a === self::MASK) {
            return self::MASK;
        }
        if (is_string($a)) {
            return "'".addslashes(mb_substr($a, 0, 120))."'";
        }
        if (is_scalar($a) || $a === null) {
            return var_export($a, true);
        }
        if (is_array($a)) {
            return 'Array('.count($a).')';
        }

        return gettype($a);
    }
}
