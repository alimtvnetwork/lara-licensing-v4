<?php

declare(strict_types=1);

/*
 | Plan 06 step 43 (login substrate). Laravel auth config.
 |
 | We use Sanctum personal-access tokens for the API. The `web` guard
 | is kept as Laravel's default for Inertia SSR sessions; the `sanctum`
 | guard is the one referenced by `auth:sanctum` in routes/api.php.
 */

return [
    'defaults' => [
        'guard' => 'web',
        'passwords' => 'users',
    ],

    'guards' => [
        'web' => [
            'driver' => 'session',
            'provider' => 'users',
        ],
        'sanctum' => [
            'driver' => 'sanctum',
            'provider' => 'users',
        ],
    ],

    'providers' => [
        'users' => [
            'driver' => 'eloquent',
            'model' => App\Models\User::class,
        ],
    ],

    'passwords' => [
        'users' => [
            'provider' => 'users',
            'table' => 'PasswordResets',
            'expire' => 60,
            'throttle' => 60,
        ],
    ],

    'password_timeout' => 10800,
];
