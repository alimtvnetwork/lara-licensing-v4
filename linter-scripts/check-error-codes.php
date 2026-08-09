<?php

/**
 * Plan 18 Step 96: check-error-codes.php
 * Fails CI if an Exception uses a code not in the config map.
 * Also cross-checks against ApiErrorCodeType in src/lib/lara-api-error.ts.
 */

$rootDir = dirname(__DIR__);
$configPath = $rootDir . '/backend/config/lara.php';
$tsPath = $rootDir . '/src/lib/lara-api-error.ts';

if (!file_exists($configPath)) {
    echo "ERROR: config/lara.php not found.\n";
    exit(1);
}

$configContent = file_get_contents($configPath);
$errorCodes = [];

// Parse error_codes from config/lara.php
if (preg_match("/'error_codes'\s*=>\s*\[(.*?)\];/s", $configContent, $matches)) {
    $arrayContent = $matches[1];
    if (preg_match_all("/'([A-Za-z0-9_]+)'/", $arrayContent, $keyMatches)) {
        $errorCodes = $keyMatches[1];
    }
}

if (empty($errorCodes)) {
    echo "ERROR: Could not parse error codes from config/lara.php.\n";
    exit(1);
}

$validCodes = array_flip($errorCodes);
$hasErrors = false;

// 1. Cross-check against TS enum
if (file_exists($tsPath)) {
    $tsContent = file_get_contents($tsPath);
    if (preg_match('/export enum ApiErrorCodeType\s*\{([^\}]+)\}/', $tsContent, $tsMatches)) {
        if (preg_match_all('/([A-Za-z0-9_]+)\s*=\s*"[^"]+"/', $tsMatches[1], $enumMatches)) {
            $tsCodes = $enumMatches[1];
            
            $diffBeTs = array_diff($errorCodes, $tsCodes);
            $diffTsBe = array_diff($tsCodes, $errorCodes);
            
            if (!empty($diffBeTs)) {
                echo "ERROR: The following error codes are in config/lara.php but missing in lara-api-error.ts:\n";
                foreach ($diffBeTs as $code) echo " - $code\n";
                $hasErrors = true;
            }
            if (!empty($diffTsBe)) {
                echo "ERROR: The following error codes are in lara-api-error.ts but missing in config/lara.php:\n";
                foreach ($diffTsBe as $code) echo " - $code\n";
                $hasErrors = true;
            }
        }
    }
}

// 2. Check usages in backend/app
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($rootDir . '/backend/app'));
foreach ($iterator as $file) {
    if ($file->isDir() || $file->getExtension() !== 'php') {
        continue;
    }

    $content = file_get_contents($file->getPathname());
    
    // Find *Exception::*('XYZ') or static::make('XYZ')
    if (preg_match_all("/(?:[A-Za-z0-9_]+Exception::[a-zA-Z0-9_]+|static::make)\s*\(\s*['\"]([A-Za-z0-9_]+)['\"]/", $content, $matches)) {
        foreach ($matches[1] as $code) {
            if (!isset($validCodes[$code])) {
                echo "ERROR: Invalid error code '{$code}' used in " . $file->getPathname() . "\n";
                $hasErrors = true;
            }
        }
    }
}

if ($hasErrors) {
    exit(1);
}

echo "OK: All error codes valid.\n";
exit(0);
