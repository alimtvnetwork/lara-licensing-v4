<?php

declare(strict_types=1);

namespace App\Services\BR;

use App\Exceptions\InternalException;


use App\Domain\BR\BrStorageConstants as S;
use App\Exceptions\LaraException;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Plan 14 step 11. Local-filesystem sink for BR archive artifacts.
 *
 * Contract (spec 26 §08 "Streaming Write Contract" + §22 "Observability"):
 *  - `reserve(archiveId, requestId)` idempotently creates
 *    `<root>/<archiveId>/` and `<root>/<archiveId>/.staging/tmp/` with mode
 *    0700. Re-reserve on an already-reserved (not yet finalized) directory
 *    is a no-op so a crash + retry survives; re-reserve on a finalized dir
 *    fails `BackupStorageFailure` (`AlreadyFinalized`) so INV-BR-A
 *    (atomicity) is never violated by a second writer.
 *  - `writeAtomic(archiveId, relPath, bytes, requestId)` writes the bytes
 *    to `<archiveId>/.staging/tmp/<u.uniq>-<basename>` then `rename()`s to
 *    `<archiveId>/<relPath>`. POSIX rename is atomic within one filesystem
 *    so readers only ever see a fully written file. LOCK_EX guards the
 *    scratch write against concurrent workers sharing the same JobId
 *    (spec §15 "Retry Policy" allows AttemptCount>1 for Export).
 *  - `finalize(archiveId, requestId)` removes `.staging/`, chmods the
 *    archive directory to 0500 (read-only), and logs the total byte size.
 *  - `abort(archiveId, requestId)` recursively removes `<archiveId>/`,
 *    used on any terminal failure path so a crashed worker never leaves
 *    a half-written archive behind.
 *  - `sizeBytes(archiveId)` sums every regular file under `<archiveId>/`
 *    excluding `.staging/`; feeds the worker `Result.Bytes` field.
 *  - `path(archiveId)` returns the absolute directory path so downstream
 *    services (manifest builder in Step 12, chunk writer in Step 13) can
 *    stream into it via this class only.
 *
 * Every failure surfaces `BackupStorageFailure` (500) with a JSON pointer
 * so the Global Error Modal (Plan 11) can render `/archiveId` or
 * `/relPath` with the offending value. All handlers land inside 15-line
 * bodies (project memory Core rule).
 *
 * The archive root is `config('lara.br.archive_root')`, absolute path,
 * created lazily on the first `reserve` call. Missing config surfaces
 * `ArchiveRootMissing` before any FS syscall so an operator sees a clear
 * error instead of a stray write into `storage_path()`.
 */
final class BrArchiveStorage
{
    public function reserve(string $archiveId, string $requestId): string
    {
        $this->assertArchiveId($archiveId);
        $root = $this->root();
        $dir = $root . DIRECTORY_SEPARATOR . $archiveId;
        if (is_dir($dir) && ! is_writable($dir)) {
            $this->fail(S::PTR_ARCHIVE_ID, S::RULE_ALREADY_FINALIZED, $archiveId);
        }
        $this->mkdirRecursive($dir, S::DIR_MODE_OPEN, $archiveId);
        $this->mkdirRecursive($dir . DIRECTORY_SEPARATOR . S::STAGING_DIR . DIRECTORY_SEPARATOR . S::TMP_DIR, S::DIR_MODE_OPEN, $archiveId);
        Log::info('br.archive.storage.reserved', ['ArchiveId' => $archiveId, 'Path' => $dir, 'RequestId' => $requestId]);

        return $dir;
    }

    public function writeAtomic(string $archiveId, string $relPath, string $bytes, string $requestId): int
    {
        $this->assertArchiveId($archiveId);
        $this->assertRelPath($relPath);
        $this->assertReserved($archiveId);
        $target = $this->targetPath($archiveId, $relPath);
        $scratch = $this->scratchPath($archiveId, $relPath);
        $this->mkdirRecursive(dirname($target), S::DIR_MODE_OPEN, $archiveId);
        $this->writeScratch($scratch, $bytes, $archiveId, $relPath);
        $this->promote($scratch, $target, $archiveId, $relPath);
        $written = strlen($bytes);
        Log::info('br.archive.storage.wrote', ['ArchiveId' => $archiveId, 'RelPath' => $relPath, 'Bytes' => $written, 'RequestId' => $requestId]);

        return $written;
    }

    public function finalize(string $archiveId, string $requestId): int
    {
        $this->assertArchiveId($archiveId);
        $this->assertReserved($archiveId);
        $dir = $this->root() . DIRECTORY_SEPARATOR . $archiveId;
        $this->rmTree($dir . DIRECTORY_SEPARATOR . S::STAGING_DIR);
        if (! @chmod($dir, S::DIR_MODE_SEALED)) {
            $this->fail(S::PTR_ARCHIVE_ID, S::RULE_CHMOD_FAILED, $archiveId);
        }
        $bytes = $this->sizeBytes($archiveId);
        Log::info('br.archive.storage.finalized', ['ArchiveId' => $archiveId, 'Bytes' => $bytes, 'RequestId' => $requestId]);

        return $bytes;
    }

    public function abort(string $archiveId, string $requestId): void
    {
        $this->assertArchiveId($archiveId);
        $dir = $this->root() . DIRECTORY_SEPARATOR . $archiveId;
        if (is_dir($dir) === false) {
            Log::info('br.archive.storage.abort_noop', ['ArchiveId' => $archiveId, 'RequestId' => $requestId]);

            return;
        }
        @chmod($dir, S::DIR_MODE_OPEN);
        $this->rmTree($dir);
        Log::warning('br.archive.storage.aborted', ['ArchiveId' => $archiveId, 'RequestId' => $requestId]);
    }

    public function sizeBytes(string $archiveId): int
    {
        $this->assertArchiveId($archiveId);
        $dir = $this->root() . DIRECTORY_SEPARATOR . $archiveId;
        if (is_dir($dir) === false) {
            return 0;
        }
        $total = 0;
        foreach ($this->iterate($dir) as $file) {
            $total += (int) filesize($file);
        }

        return $total;
    }

    public function path(string $archiveId): string
    {
        $this->assertArchiveId($archiveId);

        return $this->root() . DIRECTORY_SEPARATOR . $archiveId;
    }

    private function root(): string
    {
        $root = (string) config('lara.br.archive_root', '');
        if ($root === '') {
            $this->fail(S::PTR_ROOT, S::RULE_ROOT_MISSING, 'config lara.br.archive_root is empty');
        }
        if (is_dir($root) === false) {
            $this->mkdirRecursive($root, S::DIR_MODE_OPEN, '(root)');
        }

        return rtrim($root, DIRECTORY_SEPARATOR);
    }

    private function mkdirRecursive(string $dir, int $mode, string $archiveId): void
    {
        if (is_dir($dir)) {
            return;
        }
        if (! @mkdir($dir, $mode, true) && ! is_dir($dir)) {
            $this->fail(S::PTR_ARCHIVE_ID, S::RULE_MKDIR_FAILED, $archiveId . ':' . $dir);
        }
    }

    private function assertReserved(string $archiveId): void
    {
        $dir = $this->root() . DIRECTORY_SEPARATOR . $archiveId . DIRECTORY_SEPARATOR . S::STAGING_DIR;
        if (is_dir($dir) === false) {
            $this->fail(S::PTR_ARCHIVE_ID, S::RULE_NOT_RESERVED, $archiveId);
        }
    }

    private function targetPath(string $archiveId, string $relPath): string
    {
        return $this->root() . DIRECTORY_SEPARATOR . $archiveId . DIRECTORY_SEPARATOR . $relPath;
    }

    private function scratchPath(string $archiveId, string $relPath): string
    {
        $base = str_replace(['/', DIRECTORY_SEPARATOR], '_', $relPath);
        $u = bin2hex(random_bytes(8));

        return $this->root() . DIRECTORY_SEPARATOR . $archiveId . DIRECTORY_SEPARATOR . S::STAGING_DIR
            . DIRECTORY_SEPARATOR . S::TMP_DIR . DIRECTORY_SEPARATOR . $u . '-' . $base;
    }

    private function writeScratch(string $scratch, string $bytes, string $archiveId, string $relPath): void
    {
        $written = @file_put_contents($scratch, $bytes, LOCK_EX);
        if ($written === false || $written !== strlen($bytes)) {
            @unlink($scratch);
            $this->fail(S::PTR_REL_PATH, S::RULE_WRITE_FAILED, $archiveId . ':' . $relPath);
        }
        @chmod($scratch, S::FILE_MODE);
    }

    private function promote(string $scratch, string $target, string $archiveId, string $relPath): void
    {
        if (! @rename($scratch, $target)) {
            @unlink($scratch);
            $this->fail(S::PTR_REL_PATH, S::RULE_RENAME_FAILED, $archiveId . ':' . $relPath);
        }
    }

    private function assertArchiveId(string $archiveId): void
    {
        if (preg_match(S::RE_UUID, $archiveId) === false) {
            $this->fail(S::PTR_ARCHIVE_ID, S::RULE_INVALID_ARCHIVE_ID, $archiveId);
        }
    }

    private function assertRelPath(string $relPath): void
    {
        if (! preg_match(S::RE_RELPATH, $relPath) || str_contains($relPath, '..')) {
            $this->fail(S::PTR_REL_PATH, S::RULE_INVALID_REL_PATH, $relPath);
        }
    }

    /** @return iterable<string> */
    private function iterate(string $dir): iterable
    {
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $entry) {
            /** @var \SplFileInfo $entry */
            $path = (string) $entry->getPathname();
            if ($entry->isFile() && ! str_contains($path, DIRECTORY_SEPARATOR . S::STAGING_DIR . DIRECTORY_SEPARATOR)) {
                yield $path;
            }
        }
    }

    private function rmTree(string $dir): void
    {
        if (is_dir($dir) === false) {
            return;
        }
        try {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($it as $entry) {
                /** @var \SplFileInfo $entry */
                $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
            }
            @rmdir($dir);
        } catch (Throwable $e) {
            Log::warning('br.archive.storage.rm_tree_failed', ['Dir' => $dir, 'Reason' => $e->getMessage()]);
        }
    }

    private function fail(string $pointer, string $rule, string $value): never
    {
        throw InternalException::custom(S::ERR_STORAGE_FAILURE,
            'archive storage failure',
            [['Field' => $pointer, 'Rule' => $rule, 'Value' => $value]]
        );
    }
}
