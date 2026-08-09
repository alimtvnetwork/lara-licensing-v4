<?php

namespace App\Exceptions;

use Throwable;

final class RateLimitException extends LaraException
{
    public static function rateLimited(int $retryAfterSeconds, string $message = 'Too Many Requests', array $details = [], ?Throwable $previous = null): static
    {
        return static::make('RateLimited', $message, $details, $previous, ['Retry-After' => (string) $retryAfterSeconds]);
    }
}
