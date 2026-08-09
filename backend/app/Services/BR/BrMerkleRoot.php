<?php

declare(strict_types=1);

namespace App\Services\BR;

use App\Exceptions\InternalException;


use App\Exceptions\LaraException;

/**
 * Plan 14 step 13. Binary Merkle root over archive chunk leaves.
 *
 * Spec 26 §08 "Merkle Tree" pins the construction:
 *  - Leaves: raw SHA-256 (32 bytes) of each chunk's compressed bytes,
 *    consumed in tar-entry order.
 *  - Internal node: SHA-256(left || right).
 *  - Odd leaf/node at any level: duplicated (Bitcoin-style).
 *  - Root satisfies INV-BR-AF-4 and equals `manifest.contentHash`.
 *
 * Callers pass hex SHA-256 strings (matching `chunks[].sha256`); the
 * root comes back hex-lowercase so it drops directly into
 * `manifest.contentHash` / `manifest.chunkIndex.merkleRoot`.
 *
 * An empty leaf list is a programming error (INV-BR-AF-1 requires
 * at least the seven S1 shadow chunks); we throw `BackupCorrupt`
 * with a stable pointer so the worker's outer catch aborts the sink.
 *
 * 15-line function cap held.
 */
final class BrMerkleRoot
{
    private const ERR_CORRUPT = 'BackupCorrupt';
    private const RULE_EMPTY = 'MerkleLeavesEmpty';
    private const RULE_HEX = 'LeafNotHexSha256';
    private const PTR_LEAVES = '/chunkIndex/chunks';
    private const HEX_SHA256 = '/^[0-9a-f]{64}$/';

    /**
     * @param  list<string>  $leavesHex  chunk sha256 in tar-entry order.
     */
    public static function compute(array $leavesHex): string
    {
        if ($leavesHex === []) {
            throw InternalException::custom(self::ERR_CORRUPT, 'Merkle leaves list is empty.', [
                ['Field' => self::PTR_LEAVES, 'Rule' => self::RULE_EMPTY],
            ]);
        }
        $level = array_map(self::hexToBinLeaf(...), $leavesHex);
        while (count($level) > 1) {
            $level = self::foldLevel($level);
        }

        return bin2hex($level[0]);
    }

    private static function hexToBinLeaf(string $hex): string
    {
        if (preg_match(self::HEX_SHA256, $hex) !== 1) {
            throw InternalException::custom(self::ERR_CORRUPT, 'Merkle leaf is not a hex SHA-256.', [
                ['Field' => self::PTR_LEAVES, 'Rule' => self::RULE_HEX],
            ]);
        }

        return (string) hex2bin($hex);
    }

    /**
     * @param  list<string>  $level  raw 32-byte hashes.
     * @return list<string>
     */
    private static function foldLevel(array $level): array
    {
        if (count($level) % 2 === 1) {
            $level[] = $level[count($level) - 1];
        }
        $next = [];
        for ($i = 0, $n = count($level); $i < $n; $i += 2) {
            $next[] = hash('sha256', $level[$i] . $level[$i + 1], true);
        }

        return $next;
    }
}
