<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * Plan 11 step 16. PascalCase JSON-key gate for every JsonResource.
 *
 * Root cause this guards: `spec/02-coding-guidelines` mandates PascalCase
 * for JSON keys (matches DB column casing so BE/FE contracts stay one
 * source of truth), but nothing in CI stopped a contributor from
 * writing `'license_id' => ...` inside a `toArray` and shipping mixed
 * casing to the FE, where `LaraEnvelope<T>` and `LicenseResource`
 * consumers only key on PascalCase.
 *
 * Scan target: `backend/app/Http/Resources/**` excluding the
 * `Concerns/` trait folder. For every `.php` file, extract every
 * string array-key literal of the form `'Key' =>` / `"Key" =>` (both
 * quote styles) and assert:
 *   1. First char is uppercase A-Z.
 *   2. Body is alphanumeric only, no underscores, no hyphens.
 *   3. Key is not one of a small allow-list of framework meta keys
 *      (`data`, `meta`, `links`) that Laravel's pagination wrapper
 *      injects at the resource root; those are documented exceptions
 *      in the spec and belong to Laravel, not our domain.
 *
 * Method-level scoping to `toArray` bodies is intentionally skipped:
 * the whole file is a JsonResource, so any string-keyed array literal
 * in it participates in the wire shape (e.g. helpers on traits under
 * `Concerns/` are called from `toArray` and would leak the same
 * keys). Comment lines are stripped before matching to avoid tripping
 * on docblock examples.
 */
final class JsonResourcePascalCaseTest extends TestCase
{
    private const RESOURCES_DIR = 'Http/Resources';

    /**
     * Laravel-injected keys allowed at the envelope-wrap layer. Kept
     * inline (not in config) so a security-review diff sees the whole
     * allow-list in one place.
     *
     * @var list<string>
     */
    private const ALLOWED_LOWERCASE_KEYS = [
        'data',
        'meta',
        'links',
    ];

    public function test_every_resource_emits_only_pascal_case_keys(): void
    {
        $violations = [];
        $base = base_path('app');
        $root = $base.DIRECTORY_SEPARATOR.self::RESOURCES_DIR;
        $this->assertDirectoryExists($root, 'Http/Resources directory missing.');

        $iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
        foreach ($iter as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            // Skip the shared trait folder: it holds helpers, not wire
            // shapes. Its callers are covered because the caller's own
            // toArray literals are still scanned.
            if (str_contains($file->getPathname(), DIRECTORY_SEPARATOR.'Concerns'.DIRECTORY_SEPARATOR)) {
                continue;
            }
            $violations = array_merge($violations, $this->violationsInFile($file->getPathname(), $base));
        }

        sort($violations);
        $this->assertSame(
            [],
            $violations,
            "Non-PascalCase JSON keys detected in JsonResource classes. Rename to PascalCase to keep the BE/FE contract single-source (spec/02-coding-guidelines).\n".implode("\n", $violations),
        );
    }

    /**
     * @return list<string>
     */
    private function violationsInFile(string $path, string $base): array
    {
        $lines = file($path, FILE_IGNORE_NEW_LINES) ?: [];
        $rel = str_replace($base.DIRECTORY_SEPARATOR, '', $path);
        $out = [];
        foreach ($lines as $i => $line) {
            $stripped = ltrim($line);
            if ($stripped === '' || str_starts_with($stripped, '//') || str_starts_with($stripped, '*') || str_starts_with($stripped, '#')) {
                continue;
            }
            // Match  'Key' =>  or  "Key" =>  where Key is any run of
            // word characters. Then validate Key against PascalCase.
            if (preg_match_all('/([\'"])([A-Za-z_][A-Za-z0-9_]*)\1\s*=>/', $line, $m) === false) {
                continue;
            }
            foreach ($m[2] as $key) {
                if (in_array($key, self::ALLOWED_LOWERCASE_KEYS, true)) {
                    continue;
                }
                if (! $this->isPascalCase($key)) {
                    $out[] = sprintf('%s:%d: key "%s"', $rel, $i + 1, $key);
                }
            }
        }

        return $out;
    }

    private function isPascalCase(string $key): bool
    {
        return preg_match('/^[A-Z][A-Za-z0-9]*$/', $key) === 1;
    }
}
