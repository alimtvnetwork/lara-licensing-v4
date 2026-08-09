<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * CI diagnostics helper. Emits a machine-readable JSON summary AND a
 * human-readable Markdown report describing the state of the Root and
 * Shard databases after the migration idempotency job:
 *
 *   - Verification queries (row counts for critical tables).
 *   - Migration order (name + batch) applied to each connection.
 *   - Seeded closed-set values (Roles, Features) so drift is obvious.
 *
 * Output is written to the paths passed via --json and --markdown so
 * the workflow can upload them as artifacts and inline the Markdown
 * into $GITHUB_STEP_SUMMARY without further shell munging.
 *
 * Exits 0 even when a table is missing (the job that runs first is
 * responsible for failing the build); this command's only job is to
 * capture whatever state exists so a failure is easy to diagnose.
 */
final class DbCiSummaryCommand extends Command
{
    protected $signature = 'lara:ci:db-summary
                            {--json= : Path to write the JSON summary}
                            {--markdown= : Path to write the Markdown report}';

    protected $description = 'Capture verification queries, row counts, and migration order for CI diagnostics.';

    /** Tables to snapshot per connection. Missing tables are recorded as null. */
    private const VERIFY_TABLES = [
        'root'  => ['Roles', 'Features', 'Users', 'UserRoles', 'Resellers', 'ResellerShardRoutes', 'Prefixes', 'LicenseTiers', 'AuthSessions', 'PasswordResetTokens'],
        'shard' => ['Licenses', 'Serials', 'LicenseLedger', 'QuotaRequests', 'LicenseFeatures', 'Quotas', 'MachineBindings', 'UserBindings'],
    ];

    public function handle(): int
    {
        $summary = [
            'generated_at' => now()->toIso8601String(),
            'connections'  => [
                'root'  => $this->snapshotConnection('root'),
                'shard' => $this->snapshotConnection('shard'),
            ],
        ];

        $jsonPath = (string) $this->option('json');
        $mdPath   = (string) $this->option('markdown');

        if ($jsonPath !== '') {
            $this->ensureDir($jsonPath);
            file_put_contents($jsonPath, json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
            $this->line("wrote JSON: {$jsonPath}");
        }

        if ($mdPath !== '') {
            $this->ensureDir($mdPath);
            file_put_contents($mdPath, $this->renderMarkdown($summary));
            $this->line("wrote Markdown: {$mdPath}");
        }

        // Always echo the Markdown so the raw step log is self-contained.
        $this->line('');
        $this->line($this->renderMarkdown($summary));

        return self::SUCCESS;
    }

    /**
     * @return array{available:bool, error?:string, row_counts:array<string,?int>, migrations:list<array{name:string,batch:int}>, roles?:list<string>, features?:list<string>}
     */
    private function snapshotConnection(string $conn): array
    {
        try {
            $rowCounts  = $this->rowCounts($conn, self::VERIFY_TABLES[$conn] ?? []);
            $migrations = $this->migrationOrder($conn);
            $extra      = $conn === 'root' ? $this->rootClosedSets() : [];

            return array_merge([
                'available'  => true,
                'row_counts' => $rowCounts,
                'migrations' => $migrations,
            ], $extra);
        } catch (Throwable $e) {
            return [
                'available'  => false,
                'error'      => $e->getMessage(),
                'row_counts' => [],
                'migrations' => [],
            ];
        }
    }

    /**
     * @param list<string> $tables
     * @return array<string, int|null>
     */
    private function rowCounts(string $conn, array $tables): array
    {
        $out = [];
        foreach ($tables as $table) {
            try {
                $out[$table] = (int) DB::connection($conn)->table($table)->count();
            } catch (Throwable) {
                $out[$table] = null; // table missing on this connection.
            }
        }

        return $out;
    }

    /**
     * @return list<array{name:string,batch:int}>
     */
    private function migrationOrder(string $conn): array
    {
        try {
            $rows = DB::connection($conn)
                ->table('migrations')
                ->orderBy('batch')
                ->orderBy('migration')
                ->get(['migration', 'batch']);
        } catch (Throwable) {
            return [];
        }

        $out = [];
        foreach ($rows as $r) {
            $out[] = ['name' => (string) $r->migration, 'batch' => (int) $r->batch];
        }

        return $out;
    }

    /**
     * @return array{roles:list<string>, features:list<string>}
     */
    private function rootClosedSets(): array
    {
        $roles = [];
        $features = [];
        try {
            $roles = DB::connection('root')->table('Roles')->orderBy('RoleName')->pluck('RoleName')->all();
        } catch (Throwable) {
        }
        try {
            $features = DB::connection('root')->table('Features')->orderBy('FeatureKey')->pluck('FeatureKey')->all();
        } catch (Throwable) {
        }

        return ['roles' => array_map('strval', $roles), 'features' => array_map('strval', $features)];
    }

    private function ensureDir(string $path): void
    {
        $dir = dirname($path);
        if (is_dir($dir) === false) {
            @mkdir($dir, 0o777, true);
        }
    }

    /**
     * @param array<string, mixed> $s
     */
    private function renderMarkdown(array $s): string
    {
        $lines = [];
        $lines[] = '## DB Migration Idempotency: CI Summary';
        $lines[] = '';
        $lines[] = "_Generated at {$s['generated_at']}_";
        $lines[] = '';

        foreach (['root', 'shard'] as $conn) {
            $c = $s['connections'][$conn];
            $lines[] = "### Connection: `{$conn}`";
            if (! $c['available']) {
                $lines[] = '';
                $lines[] = "> UNAVAILABLE: `{$c['error']}`";
                $lines[] = '';
                continue;
            }

            $lines[] = '';
            $lines[] = '**Row counts**';
            $lines[] = '';
            $lines[] = '| Table | Rows |';
            $lines[] = '| --- | ---: |';
            foreach ($c['row_counts'] as $table => $count) {
                $lines[] = '| `' . $table . '` | ' . ($count === null ? '_missing_' : (string) $count) . ' |';
            }

            if ($conn === 'root') {
                $roles = $c['roles'] ?? [];
                $features = $c['features'] ?? [];
                $lines[] = '';
                $lines[] = '**Roles seeded (' . count($roles) . ')**: ' . ($roles === [] ? '_none_' : '`' . implode('`, `', $roles) . '`');
                $lines[] = '';
                $lines[] = '**Features seeded (' . count($features) . ')**: ' . ($features === [] ? '_none_' : '`' . implode('`, `', $features) . '`');
            }

            $lines[] = '';
            $lines[] = '**Migration order (' . count($c['migrations']) . ')**';
            $lines[] = '';
            $lines[] = '| # | Batch | Migration |';
            $lines[] = '| ---: | ---: | --- |';
            foreach ($c['migrations'] as $i => $m) {
                $lines[] = '| ' . ($i + 1) . ' | ' . $m['batch'] . ' | `' . $m['name'] . '` |';
            }
            $lines[] = '';
        }

        return implode("\n", $lines) . "\n";
    }
}
