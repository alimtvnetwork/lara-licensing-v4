<?php

/**
 * Plan 09 step: password recovery mail transport.
 *
 * Root cause fixed: `Mail::send(...)` requires a `mail.php` config; the
 * Laravel install did not ship one, so wiring `Mail::to(...)` would have
 * failed at `Mailer::getDriver`. Defaults are conservative for a hosted
 * deployment that has not yet pointed at an SMTP provider: the `log`
 * mailer writes the fully rendered message to `storage/logs/laravel.log`
 * (visible via `docker logs` / cPanel error log) so the recovery flow
 * is exercisable end-to-end today and can be flipped to `smtp` later
 * with zero code change (env-only).
 *
 * Do NOT rely on this file to talk to Lovable Emails; that is a Node/TSR
 * runtime concern. Laravel here uses standard SMTP/log/array drivers.
 */

return [
    'default' => env('MAIL_MAILER', 'log'),

    'mailers' => [
        'smtp' => [
            'transport' => 'smtp',
            'url' => env('MAIL_URL'),
            'host' => env('MAIL_HOST', '127.0.0.1'),
            'port' => (int) env('MAIL_PORT', 2525),
            'encryption' => env('MAIL_ENCRYPTION', 'tls'),
            'username' => env('MAIL_USERNAME'),
            'password' => env('MAIL_PASSWORD'),
            'timeout' => null,
            'local_domain' => env('MAIL_EHLO_DOMAIN'),
        ],
        'log' => [
            'transport' => 'log',
            'channel' => env('MAIL_LOG_CHANNEL'),
        ],
        'array' => [
            'transport' => 'array',
        ],
        'failover' => [
            'transport' => 'failover',
            'mailers' => ['smtp', 'log'],
        ],
    ],

    'from' => [
        'address' => env('MAIL_FROM_ADDRESS', 'no-reply@lara.local'),
        'name' => env('MAIL_FROM_NAME', 'Licensing Portal'),
    ],
];
