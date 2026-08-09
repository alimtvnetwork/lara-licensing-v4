<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\ApiEnvelope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ErrorController extends Controller
{
    /**
     * Plan 18 Step 102.
     * Tails the lara-audit-errors daily log for the admin UI.
     */
        /**
     * @OA\Get(
     *     path="/api/placeholder",
     *     summary="ErrorController index",
     *     tags={"ErrorController"},
     *     @OA\Response(response=200, description="Successful operation")
     * )
     */
public function index(Request $request): JsonResponse
    {
        $date = now()->format('Y-m-d');
        $logPath = storage_path("logs/lara-audit-errors-{$date}.log");

        $requestId = (string) ($request->attributes->get('lara.request_id') ?: ($request->headers->get('X-Request-Id') ?? ''));

        if (file_exists($logPath) === false) {
            return ApiEnvelope::success([], $requestId);
        }

        // Tailing the last N lines (e.g., 500)
        $limit = min(500, (int) $request->query('limit', 100));
        
        $lines = [];
        $file = new \SplFileObject($logPath, 'r');
        $file->seek(PHP_INT_MAX);
        $totalLines = $file->key();
        
        $startLine = max(0, $totalLines - $limit);
        $file->seek($startLine);
        
        while (!$file->eof()) {
            $line = trim($file->current());
            if ($line !== '') {
                $parsed = json_decode($line, true);
                if (is_array($parsed)) {
                    $lines[] = $parsed;
                }
            }
            $file->next();
        }
        
        // Reverse so newest is first
        $lines = array_reverse($lines);

        // We wrap it in a standard envelope
        return ApiEnvelope::success($lines, $requestId);
    }
}
