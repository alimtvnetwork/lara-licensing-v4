<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Ramsey\Uuid\Uuid;

/**
 * Plan 14 step 26. Kill-switch drill machinery.
 *
 * Quarterly scheduler; drill artifact writer; asserts audit trigger fires
 * during drill per INV-BR-RB-6.
 */
final class BrKillSwitchDrillCommand extends Command
{
    protected $signature = 'br:kill-switch:drill';
    protected $description = 'Executes a kill-switch drill to assert audit trigger firing (INV-BR-RB-6).';

    public function handle(): int
    {
        $this->info('Starting kill-switch drill...');
        $requestId = 'drill-' . Uuid::uuid4()->toString();

        try {
            DB::connection('root')->transaction(function () use ($requestId) {
                // Acquire global lock
                DB::connection('root')->select('SELECT pg_advisory_xact_lock(hashtext(?))', ['br.global']);

                // Emit drill row
                $id = Uuid::uuid7()->toString();
                DB::connection('root')->table('BackupAuditEvents')->insert([
                    'BackupAuditEventId' => $id,
                    'OccurredAt'         => now(),
                    'Code'               => 'backup.drill',
                    'ActorKind'          => 'server',
                    'RequestId'          => $requestId,
                    'Payload'            => json_encode(['Type' => 'kill-switch-drill']),
                    'PrevHash'           => DB::raw("'\\x0000000000000000000000000000000000000000000000000000000000000000'::bytea"),
                    'RowHash'            => DB::raw("'\\x0000000000000000000000000000000000000000000000000000000000000000'::bytea"),
                    'ShardId'            => 'br.global',
                    'SchemaVersion'      => 1,
                ]);

                // Assert trigger fired
                $row = DB::connection('root')->table('BackupAuditEvents')->where('BackupAuditEventId', $id)->first();
                $isFailed = !$row;
                if ($isFailed) {
                    throw new \RuntimeException("Drill audit row not found after insert.");
                }

                // If the trigger fired, RowHash won't be zero-filled
                $emptyHash = pack('H*', '0000000000000000000000000000000000000000000000000000000000000000');
                if (stream_get_contents($row->RowHash) === $emptyHash || $row->RowHash === $emptyHash) {
                    throw new \RuntimeException("Audit trigger failed to fire: RowHash is still zero-filled. INV-BR-RB-6 violated.");
                }

                $this->info("Drill successful.");
                Log::info('br.drill.success', ['RequestId' => $requestId]);
            });
        } catch (\Throwable $e) {
            $this->error("Drill failed: " . $e->getMessage());
            Log::error('br.drill.failed', ['RequestId' => $requestId, 'Reason' => $e->getMessage()]);

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
