<?php

declare(strict_types=1);

namespace Tests\Feature\BR;

use App\Exceptions\LaraException;
use App\Services\BR\BrDomainArchiveRecheck;
use App\Services\BR\BrArchiveStorage;
use PHPUnit\Framework\TestCase;

/**
 * Plan 14 contract tests for {@see BrDomainArchiveRecheck}.
 *
 * Locks the archive-vs-manifest per-table hash pairing rules:
 *  - success returns TableCount + one row per manifest.scope.domain.tables[]
 *  - hash mismatch throws BackupCorrupt / DomainArchiveContentDrift
 *  - missing chunk descriptor throws BackupCorrupt / DomainArchiveChunkMissing
 *  - malformed tables slot throws BackupCorrupt / DomainTablesShape
 *  - empty tables list is a valid no-op
 */
final class BrDomainArchiveRecheckTest extends TestCase
{
    private const REQ = 'req-domain-archive-0001';
    private const ARCHIVE = '018fa7c3-e2e2-7c4d-8e5f-6a7b8c9d0000';

    private function svc(): BrDomainArchiveRecheck
    {
        return new BrDomainArchiveRecheck(new BrArchiveStorage());
    }

    private function manifest(array $tables): array
    {
        return ['scope' => ['domain' => ['tables' => $tables]]];
    }

    private function chunk(string $name, string $hash, int $bytes = 42): array
    {
        return ['Path' => 'scope/domain/' . $name . '.jsonl.zst', 'PlainSha256' => $hash, 'PlainBytes' => $bytes, 'Ordinal' => 0, 'SealedBytes' => 100, 'Sha256' => 'x'];
    }

    public function test_success_matches_declared_to_archive_hash(): void
    {
        $h = hash('sha256', 'row-a');
        $manifest = $this->manifest([['name' => 'Prefixes', 'rowCount' => 1, 'contentHash' => $h]]);
        $preflight = ['Chunks' => [$this->chunk('Prefixes', $h, 17)]];

        $out = $this->svc()->run(self::ARCHIVE, $manifest, $preflight, self::REQ);

        $this->assertSame(1, $out['TableCount']);
        $this->assertSame('Prefixes', $out['Tables'][0]['name']);
        $this->assertSame($h, $out['Tables'][0]['declaredHash']);
        $this->assertSame($h, $out['Tables'][0]['archiveHash']);
        $this->assertSame(17, $out['Tables'][0]['plainBytes']);
    }

    public function test_hash_mismatch_throws_backup_corrupt_content_drift(): void
    {
        $declared = hash('sha256', 'declared');
        $archive = hash('sha256', 'archive');
        $manifest = $this->manifest([['name' => 'Prefixes', 'rowCount' => 1, 'contentHash' => $declared]]);
        $preflight = ['Chunks' => [$this->chunk('Prefixes', $archive)]];

        try {
            $this->svc()->run(self::ARCHIVE, $manifest, $preflight, self::REQ);
            $this->fail('expected BackupCorrupt');
        } catch (LaraException $e) {
            $this->assertSame('BackupCorrupt', $e->errorCode);
            $this->assertSame('DomainArchiveContentDrift', $e->details[0]['Rule']);
            $this->assertSame('/scope/domain/Prefixes/contentHash', $e->details[0]['Field']);
        }
    }

    public function test_missing_chunk_throws_backup_corrupt_chunk_missing(): void
    {
        $h = hash('sha256', 'x');
        $manifest = $this->manifest([['name' => 'Resellers', 'rowCount' => 0, 'contentHash' => $h]]);
        $preflight = ['Chunks' => []];

        try {
            $this->svc()->run(self::ARCHIVE, $manifest, $preflight, self::REQ);
            $this->fail('expected BackupCorrupt');
        } catch (LaraException $e) {
            $this->assertSame('BackupCorrupt', $e->errorCode);
            $this->assertSame('DomainArchiveChunkMissing', $e->details[0]['Rule']);
        }
    }

    public function test_malformed_tables_slot_throws_shape_error(): void
    {
        $manifest = ['scope' => ['domain' => ['tables' => 'not-a-list']]];

        try {
            $this->svc()->run(self::ARCHIVE, $manifest, ['Chunks' => []], self::REQ);
            $this->fail('expected BackupCorrupt');
        } catch (LaraException $e) {
            $this->assertSame('DomainTablesShape', $e->details[0]['Rule']);
        }
    }

    public function test_empty_tables_list_is_ok(): void
    {
        $out = $this->svc()->run(self::ARCHIVE, $this->manifest([]), ['Chunks' => []], self::REQ);

        $this->assertSame(0, $out['TableCount']);
        $this->assertSame([], $out['Tables']);
    }

    public function test_multiple_tables_verified_in_order(): void
    {
        $ha = hash('sha256', 'a');
        $hb = hash('sha256', 'b');
        $manifest = $this->manifest([
            ['name' => 'AuditLogs', 'rowCount' => 2, 'contentHash' => $ha],
            ['name' => 'Prefixes',  'rowCount' => 1, 'contentHash' => $hb],
        ]);
        $preflight = ['Chunks' => [$this->chunk('AuditLogs', $ha, 3), $this->chunk('Prefixes', $hb, 4)]];

        $out = $this->svc()->run(self::ARCHIVE, $manifest, $preflight, self::REQ);

        $this->assertSame(['AuditLogs', 'Prefixes'], array_column($out['Tables'], 'name'));
    }
}
