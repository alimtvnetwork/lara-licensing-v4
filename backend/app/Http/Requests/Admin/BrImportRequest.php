<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Exceptions\LaraException;
use App\Exceptions\AuthException;
use App\Exceptions\ValidationException;
use App\Exceptions\RateLimitException;
use App\Exceptions\NotFoundException;
use App\Exceptions\DomainConflictException;
use App\Exceptions\InternalException;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Plan 14 step 27. Body validation for `POST /Api/Admin/Backup/Imports`
 * (S1 shadow, `verifyOnly` slice).
 *
 * Contract (spec/26-backup-restore/12-endpoint-import.md v1.0.0):
 *  - `ArchiveId`: ULID (26 chars, Crockford base32) of a previously-
 *    produced shadow archive resolvable by BrArchiveStorage.
 *  - `Mode`: closed set. This slice only accepts `verifyOnly`;
 *    `verifyAndApply` is rejected 400 `ValidationFailed` with rule
 *    `ModeNotYetSupported` (delegated to a follow-up step that lands
 *    the apply-phase job dispatch, per plan ladder step 12).
 *  - `Note`: optional 1..280 char string, mirrors spec §"Request Body".
 *
 * Rules are declared via Laravel primitives; the closed-set + mode
 * gate lives in `withValidator` so violations surface with the
 * spec-mandated JSON pointer (via LaraException details).
 */
final class BrImportRequest extends FormRequest
{
    public const MAX_NOTE_LEN = 280;
    public const MODE_VERIFY_ONLY = 'verifyOnly';
    public const MODE_VERIFY_AND_APPLY = 'verifyAndApply';
    private const ULID_REGEX = '/^[0-9A-HJKMNP-TV-Z]{26}$/';

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'ArchiveId' => ['required', 'string', 'regex:' . self::ULID_REGEX],
            'Mode'      => ['required', 'string'],
            'Note'      => ['sometimes', 'string', 'min:1', 'max:' . self::MAX_NOTE_LEN],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $mode = (string) $this->input('Mode', '');
            $this->assertModeClosedSet($v, $mode);
        });
    }

    private function assertModeClosedSet(Validator $v, string $mode): void
    {
        $allowed = [self::MODE_VERIFY_ONLY, self::MODE_VERIFY_AND_APPLY];
        if (in_array($mode, $allowed, true) === false) {
            $v->errors()->add('Mode', 'Mode must be one of: ' . implode(', ', $allowed) . '.');
        }
    }

    protected function failedValidation(Validator $validator): void
    {
        $errors = [];
        foreach ($validator->errors()->toArray() as $field => $messages) {
            foreach ((array) $messages as $msg) {
                $errors[] = ['Field' => '/' . str_replace('.', '/', (string) $field), 'Rule' => (string) $msg];
            }
        }
        throw ValidationException::validationFailed( 'Backup import payload failed validation.', $errors);
    }

    /**
     * @return array{ArchiveId:string, Mode:string, Note:?string}
     */
    public function payload(): array
    {
        $v = $this->validated();

        return [
            'ArchiveId' => (string) ($v['ArchiveId'] ?? ''),
            'Mode'      => (string) ($v['Mode'] ?? ''),
            'Note'      => isset($v['Note']) ? (string) $v['Note'] : null,
        ];
    }
}
