<?php

namespace App\Exceptions;

use Throwable;

final class DomainConflictException extends LaraException
{
    public static function conflict(string $errorCode, string $message = 'Conflict', array $details = [], ?Throwable $previous = null): static
    {
        return static::make($errorCode, $message, $details, $previous);
    }
}
