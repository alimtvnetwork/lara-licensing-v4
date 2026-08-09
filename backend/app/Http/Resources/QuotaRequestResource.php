<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Http\Resources\Concerns\FormatsPascalCase;
use App\Models\QuotaRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Plan 10 step 4 (wave 3). QuotaRequest wire shape.
 *
 * Mirrors Admin\QuotaRequestController::project() and
 * Reseller\QuotaRequestController::project() byte-for-byte, including
 * the integer Status closed-set ordinal plus its resolved StatusName
 * label. UI decodes labels via closed-sets.ts but the label is also
 * emitted here for backwards-compatible clients and audit logs.
 *
 * @mixin QuotaRequest
 */
final class QuotaRequestResource extends JsonResource
{
    use FormatsPascalCase;

    private const STATUS_PENDING = 1;
    private const STATUS_APPROVED = 2;
    private const STATUS_DENIED = 3;
    private const STATUS_CANCELLED = 4;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var QuotaRequest $r */
        $r = $this->resource;
        $status = (int) $r->getAttribute('Status');

        return [
            'QuotaRequestId' => (int) $r->getAttribute('QuotaRequestId'),
            'ResellerId' => (int) $r->getAttribute('ResellerId'),
            'LicenseCategoryId' => (int) $r->getAttribute('LicenseCategoryId'),
            'LicenseTierId' => (int) $r->getAttribute('LicenseTierId'),
            'RequestedDelta' => (int) $r->getAttribute('RequestedDelta'),
            'ApprovedDelta' => $r->getAttribute('ApprovedDelta') === null
                ? null
                : (int) $r->getAttribute('ApprovedDelta'),
            'Status' => $status,
            'StatusName' => self::statusName($status),
            'Justification' => (string) $r->getAttribute('Justification'),
            'DenialReason' => $r->getAttribute('DenialReason') === null
                ? ''
                : (string) $r->getAttribute('DenialReason'),
            'SubmittedByUserId' => (int) $r->getAttribute('SubmittedByUserId'),
            'DecidedByUserId' => $r->getAttribute('DecidedByUserId') === null
                ? null
                : (int) $r->getAttribute('DecidedByUserId'),
            'SubmittedAt' => $this->isoOrEmpty($r->getAttribute('SubmittedAt')),
            'DecidedAt' => $this->isoOrEmpty($r->getAttribute('DecidedAt')),
            'RequestId' => (string) $r->getAttribute('RequestId'),
        ];
    }

    private static function statusName(int $status): string
    {
        return match ($status) {
            self::STATUS_PENDING => 'Pending',
            self::STATUS_APPROVED => 'Approved',
            self::STATUS_DENIED => 'Denied',
            self::STATUS_CANCELLED => 'Cancelled',
            default => 'Unknown',
        };
    }
}
