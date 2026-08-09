<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Http\Resources\Concerns\FormatsPascalCase;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Plan 10 step 4. Root `AuditLogs` row wire shape for
 * `GET /Api/Admin/AuditLogs`. Backed by a raw `stdClass|array` DB row
 * (no dedicated Eloquent model), so this Resource normalises the
 * PascalCase keys, coerces nullable ids, and decodes the `PayloadJson`
 * column into a plain array under the `Payload` key. `CreatedAt` is
 * passed through as the raw driver string (matching the controller's
 * prior projection) so byte-for-byte parity holds.
 */
final class AuditEntryResource extends JsonResource
{
    use FormatsPascalCase;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $row = (array) $this->resource;
        $payloadRaw = $row['PayloadJson'] ?? null;
        $payload = null;
        if (is_string($payloadRaw) && $payloadRaw !== '') {
            $decoded = json_decode($payloadRaw, true);
            if (is_array($decoded)) {
                $payload = $decoded;
            }
        } elseif (is_array($payloadRaw)) {
            $payload = $payloadRaw;
        }

        return [
            'AuditLogId' => (int) ($row['AuditLogId'] ?? 0),
            'ActorType' => (string) ($row['ActorType'] ?? ''),
            'ActorId' => ($row['ActorId'] ?? null) === null ? null : (int) $row['ActorId'],
            'Action' => (string) ($row['Action'] ?? ''),
            'TargetType' => (string) ($row['TargetType'] ?? ''),
            'TargetId' => ($row['TargetId'] ?? null) === null ? null : (string) $row['TargetId'],
            'RequestId' => (string) ($row['RequestId'] ?? ''),
            'Payload' => $payload,
            'CreatedAt' => (string) ($row['CreatedAt'] ?? ''),
        ];
    }
}
