<?php

use Monolog\Handler\NullHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Processor\PsrLogMessageProcessor;

/*
|--------------------------------------------------------------------------
| Logging configuration
|--------------------------------------------------------------------------
|
| Plan 11 step 6 (Coding-guidelines + Error-manage integration): this file
| exists primarily to define the `lara-diag` channel that Plan 11 step 7
| writes to when gated stack-trace logging is enabled for `LaraException`
| and generic `Throwable` renderers. Keeping the sink dedicated (not
| multiplexed into `stack`) means grepping `storage/logs/lara-diag-*.log`
| by `ErrorId` (see spec/03-error-manage runbook, step 37) returns only
| domain exception traces, not per-request access noise.
|
| Retention is 14 days by default (`LARA_DIAG_DAYS`). The channel is safe
| to leave enabled in production because stack traces are only written
| when `LARA_DIAG_STACK=true` (step 7 gate) and the same ErrorId is
| echoed into the response `Attributes.ErrorId` for 5xx envelopes.
*/

return [

    'default' => env('LOG_CHANNEL', 'stack'),

    'deprecations' => [
        'channel' => env('LOG_DEPRECATIONS_CHANNEL', 'null'),
        'trace' => (bool) env('LOG_DEPRECATIONS_TRACE', false),
    ],

    'channels' => [

        'stack' => [
            'driver' => 'stack',
            'channels' => explode(',', (string) env('LOG_STACK', 'single')),
            'ignore_exceptions' => false,
        ],

        'single' => [
            'driver' => 'single',
            'path' => storage_path('logs/laravel.log'),
            'level' => env('LOG_LEVEL', 'debug'),
            'replace_placeholders' => true,
        ],

        'daily' => [
            'driver' => 'daily',
            'path' => storage_path('logs/laravel.log'),
            'level' => env('LOG_LEVEL', 'debug'),
            'days' => (int) env('LOG_DAILY_DAYS', 14),
            'replace_placeholders' => true,
        ],

        // Plan 11 step 6: dedicated diagnostic sink for LaraException and
        // generic Throwable stack traces. Step 7 writes here via
        // Log::channel('lara-diag'). Step 8 grep-asserts that response
        // bodies never contain the `Trace` key even though this channel
        // stores it. Step 37 documents the operator runbook.
        'lara-diag' => [
            'driver' => 'daily',
            'path' => storage_path('logs/lara-diag.log'),
            'level' => env('LARA_DIAG_LEVEL', 'debug'),
            'days' => (int) env('LARA_DIAG_DAYS', 14),
            'replace_placeholders' => true,
        ],

        // Plan 18 Step 93 / 101: Admin errors screen sink.
        'lara-audit-errors' => [
            'driver' => 'daily',
            'path' => storage_path('logs/lara-audit-errors.log'),
            'level' => 'debug',
            'days' => 30,
            'formatter' => \Monolog\Formatter\JsonFormatter::class,
        ],

        'stderr' => [
            'driver' => 'monolog',
            'level' => env('LOG_LEVEL', 'debug'),
            'handler' => StreamHandler::class,
            'formatter' => env('LOG_STDERR_FORMATTER'),
            'with' => [
                'stream' => 'php://stderr',
            ],
            'processors' => [PsrLogMessageProcessor::class],
        ],

        'syslog' => [
            'driver' => 'syslog',
            'level' => env('LOG_LEVEL', 'debug'),
            'facility' => env('LOG_SYSLOG_FACILITY', LOG_USER),
            'replace_placeholders' => true,
        ],

        'errorlog' => [
            'driver' => 'errorlog',
            'level' => env('LOG_LEVEL', 'debug'),
            'replace_placeholders' => true,
        ],

        'null' => [
            'driver' => 'monolog',
            'handler' => NullHandler::class,
        ],

        'emergency' => [
            'path' => storage_path('logs/laravel.log'),
        ],

    ],

];
