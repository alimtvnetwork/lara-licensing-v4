<?php

namespace App\Exceptions;

use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Domain exception carrying an ErrorCode from spec/21-app/12-error-taxonomy.md.
 *
 * Every throw MUST reference a code present in config('lara.error_codes').
 * The global exception handler (bootstrap/app.php, Plan 06 step 6) maps
 * this to the canonical response envelope via ApiEnvelope::failure.
 *
 * ACs locked here:
 *  - AC-ERR-001: ErrorCode is a closed-set value.
 *  - AC-ERR-003: `errorId` (uuid v4) is generated once at throw time and
 *                logged, so operators can correlate a caller-visible
 *                RequestId with a server-side stack trace without leaking
 *                secret-bearing attributes into the response.
 */
class LaraException extends RuntimeException
{
    /** @var array<string, array{status:int}> resolved once, cached statically */
    private static array $codeIndex = [];

    /**
     * @param array<int, array{Field:string, Rule:string, Value?:string}> $details
     * @param array<string,string> $headers extra response headers (e.g. Retry-After)
     */
    public function __construct(
        public readonly string $errorCode,
        public readonly int $httpStatus,
        string $message,
        public readonly string $errorId,
        public readonly array $details = [],
        ?Throwable $previous = null,
        public readonly array $headers = [],
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * Preferred factory. Looks up the canonical HTTP status from
     * config('lara.error_http_status'). Callers cannot pick their own
     * status: the code -> status binding is normative (AC-ERR-001).
     *
     * @param array<int, array{Field:string, Rule:string, Value?:string}> $details
     * @param array<string,string> $headers
     */
    public static function make(string $errorCode, string $message, array $details = [], ?Throwable $previous = null, array $headers = []): static
    {
        $status = self::resolveStatus($errorCode);

        return new static($errorCode, $status, $message, self::newErrorId(), $details, $previous, $headers);
    }


    private static function resolveStatus(string $errorCode): int
    {
        if (self::$codeIndex === []) {
            self::$codeIndex = (array) config('lara.error_http_status', []);
        }
        $entry = self::$codeIndex[$errorCode] ?? null;
        if ($entry === null) {
            throw new InvalidArgumentException("Unknown ErrorCode '{$errorCode}'; add it to config/lara.php error_http_status.");
        }

        return (int) $entry['status'];
    }

    private static function newErrorId(): string
    {
        // uuid v4 without extra dependency
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);

        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20, 12);
    }

    public function getCategory(): string
    {
        $base = class_basename(static::class);
        if (str_ends_with($base, 'Exception')) {
            $cat = substr($base, 0, -9);

            return $cat === 'Lara' ? 'Internal' : $cat;
        }

        return 'Internal';
    }
}
