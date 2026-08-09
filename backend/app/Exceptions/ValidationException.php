<?php

namespace App\Exceptions;

use Throwable;

final class ValidationException extends LaraException
{
    /**
     * @param array<int, array{Field:string, Rule:string, Value?:string}> $details
     */
    public static function validationFailed(string $message = 'Validation failed', array $details = [], ?Throwable $previous = null): static
    {
        return static::make('ValidationFailed', $message, $details, $previous);
    }
}
