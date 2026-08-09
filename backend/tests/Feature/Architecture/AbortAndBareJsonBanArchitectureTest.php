<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * Bans two envelope-bypassing patterns inside `app/Http/Controllers/**`:
 *
 *   1. `abort(4xx|5xx, ...)` and `abort_if` / `abort_unless` variants.
 *      Laravel's `abort()` throws `HttpException` which the framework
 *      renders straight to a bare `{"message": "..."}` body, skipping
 *      `LaraException::make`, `ErrorId` minting, closed-set
 *      `ErrorCode` binding, `Retry-After` propagation, and the
 *      redacted `lara-diag` trace.
 *
 *   2. Bare `response()->json(..., 4xx|5xx)` calls. Any controller
 *      that returns a JSON body with a 4xx/5xx literal is emitting
 *      a hand-rolled envelope that the FE `laraFetch` + Global Error
 *      Modal cannot parse, breaking the error contract laid down in
 *      `spec/03-error-manage/02-error-architecture` and the FE
 *      guarantees in `src/lib/lara-envelope.ts` (Zod parser).
 *
 * Controllers must funnel errors through `LaraException::make('CodeName', ...)`
 * so every failure carries an `ErrorId`, the closed-set `ErrorCode` from
 * `backend/config/lara.php`, and the redacted stack trace. Success
 * responses use a JsonResource / envelope helper; 2xx/3xx literals in
 * `response()->json()` are still allowed.
 *
 * Spec anchors: spec/03-error-manage/02-error-architecture,
 * spec/02-coding-guidelines/04-php.
 */
final class AbortAndBareJsonBanArchitectureTest extends TestCase
{
    /**
     * Documented baseline of pre-existing violations under
     * `app/Http/Controllers/`. Each entry is `relative/path.php:line`.
     * Empty by construction (the scan at introduction was clean); do NOT
     * add entries. Move the failing site to `LaraException::make`
     * instead.
     */
    private const BASELINE_ALLOWLIST = [];

    public function test_no_abort_calls_in_controllers(): void
    {
        // Matches `abort(`, `abort_if(`, `abort_unless(` at a call site
        // (not part of a longer identifier and not inside a comment line
        // handled by the scanner).
        $pattern = '/(?<![A-Za-z0-9_])abort(?:_if|_unless)?\s*\(/';
        $hits = $this->scanForPattern($pattern);
        $newViolations = $this->filterAgainstBaseline($hits);

        $this->assertSame(
            [],
            $newViolations,
            "Forbidden abort() call inside app/Http/Controllers/**. Use LaraException::make('CodeName', ...) with a closed-set code from backend/config/lara.php; see docs/contributing/error-management-cheatsheet.md.\n".implode("\n", $newViolations),
        );
    }

    public function test_no_bare_response_json_with_4xx_or_5xx_literal_in_controllers(): void
    {
        // Matches `response()->json( <anything up to the status arg>, 4xx|5xx )`.
        // Only 4xx/5xx integer literals are forbidden; 2xx/3xx are fine.
        // The status literal must appear on the same line as the closing
        // paren of the json() call for this regex to fire, which matches
        // the idiomatic Laravel form.
        $pattern = '/response\(\)\s*->\s*json\s*\([^;]*?,\s*[45]\d{2}\b/';
        $hits = $this->scanForPattern($pattern);
        $newViolations = $this->filterAgainstBaseline($hits);

        $this->assertSame(
            [],
            $newViolations,
            "Forbidden bare response()->json(..., 4xx|5xx) inside app/Http/Controllers/**. Errors MUST go through LaraException::make so the envelope carries ErrorId, closed-set ErrorCode, and Retry-After. Use a JsonResource or the envelope helper for success bodies.\n".implode("\n", $newViolations),
        );
    }

    /**
     * @param  list<string>  $hits
     * @return list<string>
     */
    private function filterAgainstBaseline(array $hits): array
    {
        $baseline = array_flip(self::BASELINE_ALLOWLIST);
        $newViolations = [];
        foreach ($hits as $hit) {
            $key = $this->stripContentSuffix($hit);
            if (isset($baseline[$key])) {
                continue;
            }
            $newViolations[] = $hit;
        }

        return $newViolations;
    }

    private function stripContentSuffix(string $hit): string
    {
        $parts = explode(':', $hit, 3);

        return isset($parts[0], $parts[1]) ? $parts[0].':'.$parts[1] : $hit;
    }

    /**
     * Scans `app/Http/Controllers/**` for the given regex, skipping
     * comment-only lines. Returns "relPath:line: content" strings.
     *
     * @return list<string>
     */
    private function scanForPattern(string $regex): array
    {
        $hits = [];
        $base = base_path('app/Http/Controllers');
        if (! is_dir($base)) {
            return $hits;
        }
        $iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base));
        foreach ($iter as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $path = $file->getPathname();
            $lines = file($path, FILE_IGNORE_NEW_LINES) ?: [];
            foreach ($lines as $i => $line) {
                $stripped = ltrim($line);
                if (str_starts_with($stripped, '//') || str_starts_with($stripped, '*') || str_starts_with($stripped, '#')) {
                    continue;
                }
                if (preg_match($regex, $line) === 1) {
                    $rel = str_replace(base_path('app').DIRECTORY_SEPARATOR, '', $path);
                    $hits[] = sprintf('%s:%d: %s', $rel, $i + 1, trim($line));
                }
            }
        }
        sort($hits);

        return $hits;
    }
}
