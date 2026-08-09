<?php

namespace App\Exceptions;

use Throwable;

final class NotFoundException extends LaraException
{
    public static function notFound(string $errorCode, string $message = 'Not Found', array $details = [], ?Throwable $previous = null): static
    {
        return static::make($errorCode, $message, $details, $previous);
    }
}
