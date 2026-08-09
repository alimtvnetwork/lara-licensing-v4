<?php

use Illuminate\Support\Str;

/**
 * Database connection map.
 *
 * Plan 06 step 10. Two connections are declared statically:
 *
 *   - `root`: the Root DB (Resellers, Prefixes, identity, UserRoles,
 *     AuthSessions, Root-scoped AuditLogs). Every request begins here to
 *     resolve the caller and, for reseller-scoped calls, the target shard.
 *   - `shard_template`: parameter template consumed by
 *     `App\Db\ShardResolver` when it registers a fresh per-reseller
 *     connection at runtime. Never used directly; picking it in code is
 *     a defect.
 *
 * Shard connections are created on-demand: `ShardResolver::bind($resellerId)`
 * clones this template, substitutes the shard database name, and pushes it
 * under connection alias `shard`. See spec/23-app-db/10-reseller-shard-split-db.md.
 */

return [
    'default' => env('DB_CONNECTION', 'root'),

    'connections' => [
        'root' => [
            'driver' => env('DB_ROOT_CONNECTION', 'pgsql'),
            'host' => env('DB_ROOT_HOST', '127.0.0.1'),
            'port' => env('DB_ROOT_PORT', '5432'),
            'database' => env('DB_ROOT_DATABASE', 'lara_root'),
            'username' => env('DB_ROOT_USERNAME', 'lara'),
            'password' => env('DB_ROOT_PASSWORD', ''),
            'charset' => 'utf8',
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => env('DB_ROOT_SSLMODE', 'prefer'),
        ],

        // Runtime template consumed by ShardResolver. Do not select in code.
        'shard_template' => [
            'driver' => env('DB_SHARD_TEMPLATE_CONNECTION', 'pgsql'),
            'host' => env('DB_SHARD_TEMPLATE_HOST', '127.0.0.1'),
            'port' => env('DB_SHARD_TEMPLATE_PORT', '5432'),
            'database' => env('DB_SHARD_TEMPLATE_DATABASE', 'lara_shard_{reseller}'),
            'username' => env('DB_SHARD_TEMPLATE_USERNAME', 'lara'),
            'password' => env('DB_SHARD_TEMPLATE_PASSWORD', ''),
            'charset' => 'utf8',
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => env('DB_SHARD_TEMPLATE_SSLMODE', 'prefer'),
        ],
    ],

    'migrations' => [
        'table' => 'migrations',
        'update_date_on_publish' => true,
    ],

    'redis' => [
        'client' => env('REDIS_CLIENT', 'phpredis'),
        'options' => [
            'cluster' => env('REDIS_CLUSTER', 'redis'),
            'prefix' => Str::slug(env('APP_NAME', 'lara'), '_') . '_database_',
        ],
    ],
];
