<?php

declare(strict_types=1);

/**
 * Enforces spec/02-coding-guidelines/04-php/07-php-standards-reference/04-code-style.md Rule 6:
 * "Every function/method body must be 15 lines or fewer (excluding blank lines,
 * comments, and the signature)."
 *
 * Implementation: scans backend/app/**.php using PHP's native token_get_all
 * (avoids adding a nikic/php-parser dependency; the parse rules we need,
 * "count body lines that contain at least one non-whitespace non-comment
 * token", are trivially expressible from tokens).
 *
 * Enforcement model: closed-set baseline. Any function currently over the cap
 * must appear in spec/02-coding-guidelines/04-php/function-length-baseline.json.
 * Baseline is ratcheted-only: new violations fail the build, refactoring a
 * baseline entry back under 15 lines requires removing it from the baseline
 * (the test also fails when a baseline entry no longer exceeds the cap, so the
 * list can only shrink).
 */

const LARA_FN_BODY_LINE_CAP = 15;

/**
 * @return list<array{file:string,function:string,lines:int}>
 */
function laraScanLongFunctions(string $root): array
{
    $out = [];
    $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
    foreach ($rii as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }
        $path = $file->getPathname();
        $rel = 'app/'.ltrim(substr($path, strlen($root)), '/\\');
        $rel = str_replace('\\', '/', $rel);
        $src = (string) file_get_contents($path);
        foreach (laraFindLongFunctions($src) as $hit) {
            $out[] = ['file' => $rel, 'function' => $hit['name'], 'lines' => $hit['lines']];
        }
    }

    return $out;
}

/**
 * @return list<array{name:string,lines:int}>
 */
function laraFindLongFunctions(string $src): array
{
    $tokens = token_get_all($src);
    $n = count($tokens);
    $results = [];
    for ($i = 0; $i < $n; $i++) {
        $t = $tokens[$i];
        if (! is_array($t) || $t[0] !== T_FUNCTION) {
            continue;
        }
        $name = laraReadFunctionName($tokens, $i, $n);
        if ($name === null) {
            continue;
        }
        $braceIdx = laraFindOpeningBrace($tokens, $i, $n);
        if ($braceIdx === null) {
            continue;
        }
        $span = laraCountBodyLines($tokens, $braceIdx, $n);
        if ($span !== null && $span['lines'] > LARA_FN_BODY_LINE_CAP) {
            $results[] = ['name' => $name, 'lines' => $span['lines']];
        }
        if ($span !== null) {
            $i = $span['end'];
        }
    }

    return $results;
}

function laraReadFunctionName(array $tokens, int $i, int $n): ?string
{
    for ($j = $i + 1; $j < $n; $j++) {
        $t = $tokens[$j];
        if (is_array($t) && $t[0] === T_STRING) {
            return $t[1];
        }
        if ($t === '(' || $t === '{' || $t === ';') {
            return null;
        }
    }

    return null;
}

function laraFindOpeningBrace(array $tokens, int $i, int $n): ?int
{
    $depthParen = 0;
    for ($j = $i; $j < $n; $j++) {
        $t = $tokens[$j];
        if ($t === '(') {
            $depthParen++;
        } elseif ($t === ')') {
            $depthParen--;
        } elseif ($depthParen === 0 && $t === '{') {
            return $j;
        } elseif ($depthParen === 0 && $t === ';') {
            return null;
        }
    }

    return null;
}

/**
 * @return array{lines:int,end:int}|null
 */
function laraCountBodyLines(array $tokens, int $braceIdx, int $n): ?array
{
    $depth = 0;
    $bodyLines = [];
    for ($j = $braceIdx; $j < $n; $j++) {
        $t = $tokens[$j];
        if ($t === '{') {
            $depth++;

            continue;
        }
        if ($t === '}') {
            $depth--;
            if ($depth === 0) {
                return ['lines' => count($bodyLines), 'end' => $j];
            }

            continue;
        }
        if (! is_array($t)) {
            continue;
        }
        [$type, , $line] = $t;
        if (in_array($type, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }
        $bodyLines[$line] = true;
    }

    return null;
}

it('function bodies stay at or under the 15-line cap (baseline-ratcheted)', function () {
    $root = realpath(__DIR__.'/../../../app');
    expect($root)->not->toBeFalse();

    $baselinePath = realpath(__DIR__.'/../../../../spec/02-coding-guidelines/04-php/function-length-baseline.json');
    expect($baselinePath)->not->toBeFalse('baseline JSON must exist');
    /** @var array{entries: list<array{file:string,function:string,approxLines:int}>} $baseline */
    $baseline = json_decode((string) file_get_contents($baselinePath), true);
    $allowed = [];
    foreach ($baseline['entries'] as $e) {
        $allowed[$e['file'].'::'.$e['function']] = true;
    }

    $current = laraScanLongFunctions((string) $root);
    $currentKeys = [];
    $newViolations = [];
    foreach ($current as $c) {
        $key = $c['file'].'::'.$c['function'];
        $currentKeys[$key] = true;
        if (! isset($allowed[$key])) {
            $newViolations[] = $key.' ('.$c['lines'].' lines > '.LARA_FN_BODY_LINE_CAP.')';
        }
    }

    expect($newViolations)->toBe(
        [],
        "New function-length violations detected. Extract helpers per spec/02-coding-guidelines/04-php/07-php-standards-reference/04-code-style.md Rule 6.\n".implode("\n", $newViolations)
    );

    // Ratchet: baseline entries that no longer exceed the cap must be removed.
    $stale = array_values(array_diff(array_keys($allowed), array_keys($currentKeys)));
    expect($stale)->toBe(
        [],
        "Baseline entries are no longer over the cap. Delete them from spec/02-coding-guidelines/04-php/function-length-baseline.json to prevent regressions:\n".implode("\n", $stale)
    );
});
