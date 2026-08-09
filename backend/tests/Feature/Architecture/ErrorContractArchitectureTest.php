<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * Plan 11 step 15. Architecture gate that locks the current clean state
 * of the controller + service layer against the two most common error
 * anti-patterns:
 *
 *   1. `abort(<4xx|5xx>, ...)` inside `App\Http\Controllers\**`, which
 *      bypasses `LaraException` and produces an envelope without an
 *      `ErrorCode` from the closed set.
 *   2. `response()->json([...], <4xx|5xx>)` inside controllers, same
 *      reason.
 *   3. `throw new (RuntimeException|InvalidArgumentException|\Exception)`
 *      inside `App\Services\**` or controllers, which bypasses the
 *      `LaraException::make(...)` factory so no `ErrorId`/`Retry-After`
 *      is attached and the trace lands in the shared log channel
 *      instead of `lara-diag`.
 *
 * Spec anchors: spec/03-error-manage/02-error-architecture,
 * spec/02-coding-guidelines/04-php.
 *
 * Root cause this guards: without a gate, a future PR silently
 * reintroduces one of these paths and the correlation contract shipped
 * in v0.409.0..v0.412.0 (`ErrorId` in envelope <-> `lara-diag` trace)
 * breaks for whichever endpoint regressed, with no test failure until
 * a caller opens a support ticket.
 *
 * The scan is deliberately regex-based (not AST) so it stays fast and
 * needs no PhpParser dependency; the patterns are conservative and
 * exclude quoted-string and comment lines.
 */
final class ErrorContractArchitectureTest extends TestCase
{
    /**
     * Directories under `backend/app/` scanned by this gate. Kept in the
     * test to avoid a config knob that could silently exclude a folder.
     */
    private const SCAN_ROOTS = [
        'Http/Controllers',
        'Services',
    ];

    public function test_no_abort_calls_in_controllers_or_services(): void
    {
        $hits = $this->scanForPattern('/(^|[^A-Za-z0-9_>])abort\s*\(/');
        $this->assertSame(
            [],
            $hits,
            "Forbidden abort() call. Throw LaraException::make('CodeName', ...) instead so the envelope carries a closed-set ErrorCode + ErrorId.\n".implode("\n", $hits),
        );
    }

    public function test_no_raw_response_json_with_status_literal_in_controllers_or_services(): void
    {
        // Matches `response()->json(..., 4xx|5xx)` where the status is a
        // 3-digit literal starting with 4 or 5. 2xx/3xx responses are
        // fine (success envelopes go through ApiEnvelope::success).
        $hits = $this->scanForPattern('/response\s*\(\s*\)\s*->\s*json\s*\([^;]*?,\s*[45]\d{2}\s*[,)]/');
        $this->assertSame(
            [],
            $hits,
            "Forbidden raw response()->json([...], 4xx/5xx). Throw LaraException::make(...) so the response goes through the canonical envelope renderer.\n".implode("\n", $hits),
        );
    }

    public function test_no_generic_exception_throws_in_controllers_or_services(): void
    {
        // Blocks: throw new Exception(...), throw new \Exception(...),
        // throw new RuntimeException(...), throw new \RuntimeException(...),
        // throw new InvalidArgumentException(...), throw new \InvalidArgumentException(...).
        $pattern = '/throw\s+new\s+\\\\?(Exception|RuntimeException|InvalidArgumentException|LogicException|DomainException)\s*\(/';
        $hits = $this->scanForPattern($pattern);
        $this->assertSame(
            [],
            $hits,
            "Forbidden generic exception throw. Use LaraException::make('CodeName', ...) with a closed-set code from backend/config/lara.php.\n".implode("\n", $hits),
        );
    }

    /**
     * @return list<string> Human-readable "path:line: content" hits.
     */
    private function scanForPattern(string $regex): array
    {
        $hits = [];
        $base = base_path('app');
        foreach (self::SCAN_ROOTS as $rel) {
            $root = $base.DIRECTORY_SEPARATOR.$rel;
            if (! is_dir($root)) {
                continue;
            }
            $iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
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
                        $rel = str_replace($base.DIRECTORY_SEPARATOR, '', $path);
                        $hits[] = sprintf('%s:%d: %s', $rel, $i + 1, trim($line));
                    }
                }
            }
        }
        sort($hits);

        return $hits;
    }
}
