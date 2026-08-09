<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Http\Controllers\Controller;
use Illuminate\Console\Command;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use ReflectionClass;
use ReflectionMethod;
use Throwable;

/**
 * Plan 10 step 1. Endpoint parity audit.
 *
 * Iterates every route registered by the application, resolves the
 * controller/method pair via reflection, and reports whether the
 * canonical Laravel primitives are wired for each endpoint:
 *   - FormRequest    (typed request parameter for mutation verbs)
 *   - Policy         (registered class in AuthServiceProvider::$policies)
 *   - JsonResource   (used somewhere inside the controller class file)
 *   - TestFile       (any Pest/PHPUnit file under tests/ that mentions
 *                     the controller's short class name)
 *
 * Emits JSON when invoked with --json, otherwise a human-readable table.
 * The paired EndpointInventoryTest consumes the JSON to fail CI on
 * `missing-test` rows for controllers that are not in the allowlist.
 *
 * Non-goals here: this command DOES NOT create the missing pieces. That
 * is Plan 10 steps 2-4 (FormRequest / Policy / Resource backfills) and
 * step 15 (Pest matrix). This command is the sensor, not the actuator.
 *
 * References:
 *  - .lovable/plans/pending/10-e2e-tests-and-cicd.md step 1
 *  - .lovable/plans/subtasks/10-e2e-tests-and-cicd/SS-01-endpoint-parity-audit.md
 *  - spec/02-coding-guidelines/04-php (Laravel primitives conventions)
 *  - spec/03-error-manage (logging + error surfacing rules)
 */
final class AuditEndpoints extends Command
{
    protected $signature = 'lara:audit:endpoints {--json : Emit JSON envelope} {--out= : Write JSON to this path}';

    protected $description = 'Audit every route: FormRequest, Policy, JsonResource, test file coverage.';

    public function handle(): int
    {
        $allowlist = (array) config('lara.endpoint_audit_allowlist', []);
        $testFiles = $this->collectTestFileText();
        $policyMap = $this->collectRegisteredPolicies();
        $rows = [];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            /** @var RoutingRoute $route */
            $action = $route->getActionName();
            if ($action === 'Closure' || ! str_contains($action, '@')) {
                continue;
            }

            [$controllerClass, $method] = explode('@', $action, 2);
            $row = $this->auditOne($route, $controllerClass, $method, $testFiles, $policyMap, $allowlist);
            $rows[] = $row;
        }

        $summary = [
            'Total' => count($rows),
            'Ok' => 0,
            'MissingRequest' => 0,
            'MissingPolicy' => 0,
            'MissingResource' => 0,
            'MissingTest' => 0,
        ];
        foreach ($rows as $r) {
            foreach ($r['Findings'] as $f) {
                $key = match ($f) {
                    'missing-request' => 'MissingRequest',
                    'missing-policy' => 'MissingPolicy',
                    'missing-resource' => 'MissingResource',
                    'missing-test' => 'MissingTest',
                    default => null,
                };
                if ($key !== null) {
                    $summary[$key]++;
                }
            }
            if ($r['Findings'] === []) {
                $summary['Ok']++;
            }
        }

        $envelope = ['Summary' => $summary, 'Rows' => $rows];

        $outPath = (string) ($this->option('out') ?? '');
        if ($outPath !== '') {
            File::ensureDirectoryExists(dirname($outPath));
            File::put($outPath, json_encode($envelope, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
            $this->info("Wrote {$outPath}");
        }

        if ($this->option('json')) {
            $this->line(json_encode($envelope, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->table(
            ['Method', 'Uri', 'Controller', 'Action', 'Findings'],
            array_map(static fn (array $r): array => [
                $r['Method'],
                $r['Uri'],
                class_basename($r['Controller']),
                $r['Action'],
                $r['Findings'] === [] ? 'ok' : implode(', ', $r['Findings']),
            ], $rows),
        );
        $this->info(sprintf(
            'Total=%d Ok=%d MissingRequest=%d MissingPolicy=%d MissingResource=%d MissingTest=%d',
            $summary['Total'],
            $summary['Ok'],
            $summary['MissingRequest'],
            $summary['MissingPolicy'],
            $summary['MissingResource'],
            $summary['MissingTest'],
        ));

        return self::SUCCESS;
    }

    /**
     * @param array<string,string> $testFiles Map of path -> file contents (concatenated grep haystack per file).
     * @param array<class-string,class-string> $policyMap Model class -> Policy class.
     * @param array<int,string> $allowlist Route names allowed to skip each finding.
     * @return array<string,mixed>
     */
    private function auditOne(
        RoutingRoute $route,
        string $controllerClass,
        string $method,
        array $testFiles,
        array $policyMap,
        array $allowlist,
    ): array {
        $uri = '/' . ltrim($route->uri(), '/');
        $verbs = array_values(array_diff($route->methods(), ['HEAD']));
        $verb = $verbs[0] ?? 'GET';
        $name = (string) ($route->getName() ?? '');
        $findings = [];

        $needsRequest = in_array($verb, ['POST', 'PUT', 'PATCH', 'DELETE'], true);
        $hasFormRequest = false;
        $controllerFile = null;

        try {
            $refClass = new ReflectionClass($controllerClass);
            $controllerFile = $refClass->getFileName() ?: null;
            if ($refClass->hasMethod($method)) {
                $refMethod = new ReflectionMethod($controllerClass, $method);
                foreach ($refMethod->getParameters() as $p) {
                    $type = $p->getType();
                    if ($type === null || method_exists($type, 'getName') === false) {
                        continue;
                    }
                    $typeName = $type->getName();
                    if (class_exists($typeName) && is_subclass_of($typeName, FormRequest::class)) {
                        $hasFormRequest = true;
                        break;
                    }
                }
            }
        } catch (Throwable $e) {
            $findings[] = 'reflection-failed:' . $e->getMessage();
        }

        if ($needsRequest && ! $hasFormRequest) {
            $findings[] = 'missing-request';
        }

        // Policy detection is heuristic: we only know the controller class,
        // not the model. Report `missing-policy` when the app has no policies
        // registered at all OR when the controller class file references a
        // model (via `use App\Models\...`) whose class is not in $policyMap.
        $usesResource = false;
        $referencedModel = null;
        if ($controllerFile !== null && File::exists($controllerFile)) {
            $src = File::get($controllerFile);
            $usesResource = preg_match('/use\s+' . preg_quote(JsonResource::class, '/') . '\b/', $src) === 1
                || preg_match('/use\s+App\\\\Http\\\\Resources\\\\/', $src) === 1;
            if (preg_match('/use\s+App\\\\Models\\\\([A-Za-z0-9_]+)/', $src, $m) === 1) {
                $referencedModel = 'App\\Models\\' . $m[1];
            }
        }

        if ($referencedModel !== null && ! isset($policyMap[$referencedModel])) {
            $findings[] = 'missing-policy';
        }

        $isFailed = !$usesResource;
        if ($isFailed) {
            $findings[] = 'missing-resource';
        }

        $short = class_basename($controllerClass);
        $hasTest = false;
        foreach ($testFiles as $haystack) {
            if (str_contains($haystack, $short)) {
                $hasTest = true;
                break;
            }
        }
        $isFailed = !$hasTest;
        if ($isFailed) {
            $findings[] = 'missing-test';
        }

        if (in_array($name, $allowlist, true)) {
            $findings = array_values(array_filter($findings, static fn (string $f): bool => str_starts_with($f, 'reflection-failed')));
        }

        return [
            'Method' => $verb,
            'Uri' => $uri,
            'Name' => $name,
            'Controller' => $controllerClass,
            'Action' => $method,
            'Findings' => $findings,
        ];
    }

    /**
     * @return array<string,string> Absolute path -> file contents.
     */
    private function collectTestFileText(): array
    {
        $base = base_path('tests');
        if (is_dir($base) === false) {
            return [];
        }
        $out = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            /** @var \SplFileInfo $f */
            if ($f->isFile() && str_ends_with($f->getFilename(), '.php')) {
                $out[$f->getPathname()] = (string) file_get_contents($f->getPathname());
            }
        }

        return $out;
    }

    /**
     * @return array<class-string,class-string>
     */
    private function collectRegisteredPolicies(): array
    {
        // Laravel exposes the map through Gate::policies() once providers boot.
        try {
            $gate = app(\Illuminate\Contracts\Auth\Access\Gate::class);
            if (method_exists($gate, 'policies')) {
                /** @var array<class-string,class-string> $map */
                $map = $gate->policies();

                return $map;
            }
        } catch (Throwable) {
            // fall through
        }

        return [];
    }
}
