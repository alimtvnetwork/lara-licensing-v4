<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AppUpdateAsset;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Plan 06 step 47. `retention:sweep-orphan-tickets`.
 *
 * Root cause this command closes (one sentence): spec/21-app/
 * 17-self-update-endpoint.md v1.3.0 §"Upload ticket expiry" requires
 * that orphan `AppUpdateAssets` rows (IsFinalized=0) whose
 * `UploadTicketExpiresAt` has passed be reclaimed so a retry of the
 * same (Product, Version, Platform) triple can INSERT cleanly on the
 * partial unique index `UX_AppUpdateAssets_TicketTriple`; without a
 * sweeper, a failed PUT during phase 2 permanently locks that triple
 * until an operator hand-deletes the row.
 *
 * Behaviour:
 *  - Root-DB scan; batch cap keeps a single run bounded.
 *  - Per row: unlink the partial-upload file on disk (if present) then
 *    DELETE the row inside its own transaction so one failure does not
 *    starve the batch.
 *  - Emits one `UpdateAssetTicketExpired` audit row per swept ticket
 *    with a synthetic `RequestId = sweep-<UploadToken>` prefix so audit
 *    consumers can distinguish sweep-originated rows from operator-
 *    originated ones (mirrors `impersonation:timeout-sweep`).
 */
final class SelfUpdateOrphanTicketSweepCommand extends Command
{
    protected $signature = 'retention:sweep-orphan-tickets {--batch=500}';

    protected $description = 'Delete expired self-update upload tickets and their partial uploads (spec 17 §"Upload ticket expiry").';

    private const ROOT = 'root';
    private const AUDIT_ACTION = 'UpdateAssetTicketExpired';
    private const AUDIT_TARGET_TYPE = 'AppUpdateAssets';
    private const REQUEST_ID_PREFIX = 'sweep-';

    public function handle(): int
    {
        $batch = (int) $this->option('batch');
        $totals = ['BatchSize' => $batch, 'ExpiredTickets' => 0, 'Errors' => 0, 'BytesReclaimed' => 0];
        $rows = AppUpdateAsset::query()

            ->where('IsFinalized', 0)
            ->whereNotNull('UploadTicketExpiresAt')
            ->where('UploadTicketExpiresAt', '<', Carbon::now('UTC'))
            ->orderBy('AppUpdateAssetId')
            ->limit($batch)
            ->get();
        foreach ($rows as $row) {
            $this->deleteOne($row, $totals);
        }
        Log::info('self_update.orphan_ticket_sweep.completed', $totals);

        return self::SUCCESS;
    }

    /**
     * @param array{BatchSize:int,ExpiredTickets:int,Errors:int,BytesReclaimed:int} $totals
     */
    private function deleteOne(AppUpdateAsset $row, array &$totals): void
    {
        $assetId = (int) $row->AppUpdateAssetId;
        $token = (string) ($row->UploadToken ?? '');
        try {
            $bytes = $this->deleteOneTx($row);
            $totals['ExpiredTickets']++;
            $totals['BytesReclaimed'] += $bytes;
        } catch (Throwable $e) {
            $totals['Errors']++;
            Log::warning('self_update.orphan_ticket_sweep.row_failed', [
                'AppUpdateAssetId' => $assetId,
                'UploadToken' => $token,
                'Error' => $e->getMessage(),
            ]);
        }
    }

    private function deleteOneTx(AppUpdateAsset $row): int
    {
        return DB::connection(self::ROOT)->transaction(function () use ($row): int {
            $locked = AppUpdateAsset::query()
                ->where('AppUpdateAssetId', $row->AppUpdateAssetId)
                ->where('IsFinalized', 0)
                ->lockForUpdate()
                ->first();
            if (($locked instanceof AppUpdateAsset) === false) {
                return 0;
            }
            $bytes = $this->unlinkPartialUpload((string) $locked->StoragePath);
            $this->insertExpiryAudit($locked);
            $locked->delete();

            return $bytes;
        });
    }

    private function unlinkPartialUpload(string $path): int
    {
        if ($path === '' || !is_file($path)) {
            return 0;
        }
        $bytes = (int) @filesize($path);
        @unlink($path);

        return $bytes;
    }

    private function insertExpiryAudit(AppUpdateAsset $row): void
    {
        $payload = [
            'Product' => (string) $row->Product,
            'Version' => (string) $row->Version,
            'Platform' => (string) $row->Platform,
            'UploadTicketExpiresAt' => $row->UploadTicketExpiresAt?->format('Y-m-d\TH:i:s\Z'),
        ];
        DB::connection(self::ROOT)->insert(
            \App\Support\AuditWriter::insertSql(self::ROOT),
            [
                'System',
                null,
                self::AUDIT_ACTION,
                self::AUDIT_TARGET_TYPE,
                (string) $row->AppUpdateAssetId,
                self::REQUEST_ID_PREFIX . (string) ($row->UploadToken ?? $row->AppUpdateAssetId),
                (string) json_encode($payload),
            ]
        );
    }
}
