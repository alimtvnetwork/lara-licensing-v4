<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Plan 10 step 32. E2E helper: print the lowest active ResellerId as
 * JSON on stdout, so the Reseller-dashboard spec can target a real
 * seeded row without hard-coding an id.
 *
 * Root cause this command exists: the Reseller-scoped dashboard at
 * `/reseller/$resellerId/` parses `resellerId` as a positive integer
 * and throws on invalid input. Playwright cannot introspect the DB,
 * and seeded ids drift across environments. This command is the one
 * deterministic lookup path, guarded to non-production envs only.
 */
final class E2EFirstResellerIdCommand extends Command
{
    protected $signature = 'e2e:first-reseller-id';

    protected $description = 'E2E-only: print the lowest active ResellerId as JSON.';

    public function handle(): int
    {
        if (! $this->isE2EEnabled()) {
            $this->error('e2e:first-reseller-id refused: APP_ENV must be testing/ci/local or LARA_E2E_SEED=1.');

            return self::FAILURE;
        }

        $row = DB::connection('root')->table('Resellers')
            ->where('IsActive', true)
            ->orderBy('ResellerId', 'asc')
            ->first(['ResellerId', 'Slug']);

        if ($row === null) {
            $this->line(json_encode([
                'Found' => false,
                'Reason' => 'no_active_reseller',
            ], JSON_THROW_ON_ERROR));

            return self::FAILURE;
        }

        $this->line(json_encode([
            'Found' => true,
            'ResellerId' => (int) $row->ResellerId,
            'Slug' => (string) $row->Slug,
        ], JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }

    private function isE2EEnabled(): bool
    {
        if ((bool) env('LARA_E2E_SEED', false)) {
            return true;
        }
        $env = (string) env('APP_ENV', 'production');

        return in_array($env, ['testing', 'ci', 'local'], true);
    }
}
