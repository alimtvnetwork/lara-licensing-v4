<?php

declare(strict_types=1);

/*
 | Plan 06 step 43 (login substrate). Sanctum config.
 |
 | Personal-access tokens back API auth. The token `name` column carries
 | the AuthSessions.SessionId UUID so downstream handlers can resolve the
 | active session via `request()->user()->currentAccessToken()->name`.
 |
 | Stateful domains: only the Inertia SPA served from the same origin
 | (see APP_URL) uses session cookies. All other clients present the
 | token via the Authorization: Bearer header.
 */

use Laravel\Sanctum\Sanctum;

return [
    'stateful' => explode(',', (string) env(
        'SANCTUM_STATEFUL_DOMAINS',
        sprintf('%s%s', 'localhost,localhost:8080,127.0.0.1,127.0.0.1:8000,::1', env('APP_URL') ? ',' . parse_url((string) env('APP_URL'), PHP_URL_HOST) : '')
    )),

    'guard' => ['web'],

    // Expiration is enforced application-side via AuthSessions.ExpiresAt.
    // Sanctum's own expiration is left null so the AuthSession row is the
    // single source of truth.
    'expiration' => null,

    'token_prefix' => (string) env('SANCTUM_TOKEN_PREFIX', 'lara_'),

    'middleware' => [
        'authenticate_session' => Laravel\Sanctum\Http\Middleware\AuthenticateSession::class,
        'encrypt_cookies' => Illuminate\Cookie\Middleware\EncryptCookies::class,
        'validate_csrf_token' => Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
    ],
];
