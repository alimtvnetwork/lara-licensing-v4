<?php

namespace App\Exceptions;

use Throwable;

final class AuthException extends LaraException
{
    public static function forbidden(string $message = 'Access denied', array $details = [], ?Throwable $previous = null): static
    {
        return static::make('AuthForbidden', $message, $details, $previous);
    }

    public static function unauthorized(string $message = 'Unauthorized', array $details = [], ?Throwable $previous = null): static
    {
        return static::make('AuthUnauthorized', $message, $details, $previous);
    }

    public static function sessionNotFound(string $message = 'Session not found', array $details = [], ?Throwable $previous = null): static
    {
        return static::make('AuthSessionNotFound', $message, $details, $previous);
    }

    public static function invalidCredentials(string $message = 'Invalid credentials', array $details = [], ?Throwable $previous = null): static
    {
        return static::make('AuthInvalidCredentials', $message, $details, $previous);
    }
}
