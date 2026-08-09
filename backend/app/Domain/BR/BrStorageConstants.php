<?php

declare(strict_types=1);

namespace App\Domain\BR;

/**
 * Plan 14 step 11. Constants for the BR archive filesystem sink.
 *
 * Sink layout (rooted at `config('lara.br.archive_root')`):
 *
 *   <root>/<archiveId>/                  final artifact directory (mode 0500 after finalize)
 *   <root>/<archiveId>/.staging/         write area, removed on finalize / abort
 *   <root>/<archiveId>/.staging/tmp/     scratch files for atomic-rename writes
 *
 * INV-BR-AF-6 (writers never buffer class bodies in memory) is respected
 * because `BrArchiveStorage::writeAtomic` streams payload -> scratch ->
 * rename; callers pass paths, not concatenated buffers.
 *
 * Every literal used by BrArchiveStorage lives here so a spec drift shows
 * up as a config diff, not a runtime footgun.
 */
final class BrStorageConstants
{
    /** Sub-directory used for scratch writes (removed on finalize). */
    public const STAGING_DIR = '.staging';

    /** Scratch file directory inside STAGING_DIR. */
    public const TMP_DIR = 'tmp';

    /** Mode for the archive directory during writes (`rwx` owner only). */
    public const DIR_MODE_OPEN = 0o700;

    /** Mode for the archive directory once finalized (`r-x` owner only). */
    public const DIR_MODE_SEALED = 0o500;

    /** Mode for regular files (owner read/write only). */
    public const FILE_MODE = 0o600;

    /** Error code emitted for every storage-sink failure. */
    public const ERR_STORAGE_FAILURE = 'BackupStorageFailure';

    /** JSON-pointer roots used in BackupStorageFailure details. */
    public const PTR_ARCHIVE_ID = '/archiveId';
    public const PTR_REL_PATH   = '/relPath';
    public const PTR_ROOT       = '/config/archive_root';

    /** Rule identifiers for LaraException::make details[].Rule. */
    public const RULE_INVALID_ARCHIVE_ID = 'InvalidArchiveId';
    public const RULE_INVALID_REL_PATH   = 'InvalidRelPath';
    public const RULE_MKDIR_FAILED       = 'MkdirFailed';
    public const RULE_WRITE_FAILED       = 'WriteFailed';
    public const RULE_RENAME_FAILED      = 'RenameFailed';
    public const RULE_ROOT_MISSING       = 'ArchiveRootMissing';
    public const RULE_NOT_RESERVED       = 'ArchiveNotReserved';
    public const RULE_ALREADY_FINALIZED  = 'ArchiveAlreadyFinalized';
    public const RULE_CHMOD_FAILED       = 'ChmodFailed';

    /** Regex validators (archive id is UUID; relPath is safe posix segment list). */
    public const RE_UUID    = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';
    public const RE_RELPATH = '/^(?!\.)(?!.*\/\.)(?!.*\/\/)[A-Za-z0-9._\/\-]{1,255}$/';
}
