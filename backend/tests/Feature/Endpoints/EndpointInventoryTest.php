<?php

declare(strict_types=1);

/*
 | Plan 10 step 1 + step 14. Endpoint parity gate.
 |
 | Runs `lara:audit:endpoints --json --out=<tmp>` and asserts that every
 | route with a controller action has at least one Pest/PHPUnit test file
 | that mentions the controller's short class name. `missing-request`,
 | `missing-policy`, and `missing-resource` are reported by the command
 | but tolerated at this gate. They become blocking once Plan 10 steps 2-4
 | (FormRequest / Policy / Resource backfills) land; the assertion below
 | flips from `missing-test` to the full `Findings === []` check at that
 | point (Plan 10 step 15). Keeping the gate strictly on `missing-test`
 | here is intentional: it prevents the exact regression that motivated
 | this plan (endpoints shipping without any test coverage) while giving
 | the backfill steps room to land in follow-up PRs without breaking main.
 |
 | Allowlist: `config('lara.endpoint_audit_allowlist')` (route names).
 | Use it ONLY for endpoints that are legitimately covered by an
 | integration path other than a controller-named test (e.g. HMAC
 | handshake `/Api/Portal/Ping`). Do not use it to hide missing coverage.
 |
 | References:
 |  - .lovable/plans/subtasks/10-e2e-tests-and-cicd/SS-01-endpoint-parity-audit.md
 |  - backend/app/Console/Commands/AuditEndpoints.php
 */

use Illuminate\Support\Facades\Artisan;

it('every route has at least one test file referencing its controller', function (): void {
    $out = storage_path('framework/testing/endpoint-parity.json');
    @mkdir(dirname($out), 0777, true);

    $exit = Artisan::call('lara:audit:endpoints', ['--json' => true, '--out' => $out]);
    expect($exit)->toBe(0);
    expect(file_exists($out))->toBeTrue();

    $envelope = json_decode((string) file_get_contents($out), true);
    expect($envelope)->toBeArray()->toHaveKeys(['Summary', 'Rows']);

    // ---- Plan 10 step 14: response-shape assertions ----
    // Root cause the shape lock guards: downstream CI dashboards and the
    // release gate consume this JSON envelope directly. A silent rename
    // or drop of `Summary.MissingTest`, or a `Rows[]` entry that omits
    // `Findings`, would ship as green because the coverage filter below
    // uses `??`-style tolerant lookups. Lock the schema explicitly.

    $summary = $envelope['Summary'];
    expect($summary)->toBeArray()->toHaveKeys([
        'Total', 'Ok', 'MissingRequest', 'MissingPolicy', 'MissingResource', 'MissingTest',
    ]);
    foreach (['Total', 'Ok', 'MissingRequest', 'MissingPolicy', 'MissingResource', 'MissingTest'] as $k) {
        expect($summary[$k])->toBeInt("Summary.{$k} must be int, got " . gettype($summary[$k]));
        expect($summary[$k])->toBeGreaterThanOrEqual(0);
    }

    $rows = $envelope['Rows'];
    expect($rows)->toBeArray();
    expect(count($rows))->toBe(
        $summary['Total'],
        sprintf('Summary.Total=%d must equal count(Rows)=%d', $summary['Total'], count($rows)),
    );

    $rowKeys = ['Method', 'Uri', 'Name', 'Controller', 'Action', 'Findings'];
    $knownFindings = ['missing-request', 'missing-policy', 'missing-resource', 'missing-test'];
    $okCount = 0;
    foreach ($rows as $i => $r) {
        expect($r)->toBeArray()->toHaveKeys($rowKeys, "Row {$i} shape drift");
        expect($r['Method'])->toBeString();
        expect(in_array($r['Method'], ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'], true))
            ->toBeTrue("Row {$i} Method must be an HTTP verb, got " . $r['Method']);
        expect($r['Uri'])->toBeString();
        expect(str_starts_with($r['Uri'], '/'))->toBeTrue("Row {$i} Uri must start with /");
        expect($r['Name'])->toBeString();
        expect($r['Controller'])->toBeString();
        expect($r['Action'])->toBeString();
        expect($r['Findings'])->toBeArray();
        foreach ($r['Findings'] as $f) {
            expect($f)->toBeString();
            // `reflection-failed:*` is a diagnostic prefix, tolerate it.
            $isKnown = in_array($f, $knownFindings, true) || str_starts_with($f, 'reflection-failed');
            expect($isKnown)->toBeTrue("Row {$i} unknown finding '{$f}' (schema drift)");
        }
        if ($r['Findings'] === []) {
            $okCount++;
        }
    }
    expect($summary['Ok'])->toBe(
        $okCount,
        sprintf('Summary.Ok=%d must equal Rows with empty Findings=%d', $summary['Ok'], $okCount),
    );
    // ---- End response-shape assertions ----

    $offenders = array_values(array_filter(
        $rows,
        static fn (array $r): bool => in_array('missing-test', $r['Findings'], true),
    ));

    $message = $offenders === []
        ? ''
        : "Endpoints without a matching test file:\n" . implode("\n", array_map(
            static fn (array $r): string => sprintf(
                '  %s %s  ->  %s@%s',
                $r['Method'],
                $r['Uri'],
                $r['Controller'],
                $r['Action'],
            ),
            $offenders,
        ));

    expect($offenders)->toBe([], $message);
});

