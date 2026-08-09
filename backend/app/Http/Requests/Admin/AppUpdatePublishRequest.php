<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Exceptions\ValidationException;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Plan 06 step 45 (publish write path, phase 3 of 3).
 *
 * Body validation for `POST /Api/Admin/AppUpdates` per
 * spec/21-app/17-self-update-endpoint.md v1.3.0 §"POST /Admin/AppUpdates".
 *
 * Root cause (one sentence): the finalize endpoint takes a nested
 * `Assets[]` array where each element pins the same closed sets as
 * the ticket call plus an opaque `UploadToken`, so every field must
 * be validated before the DB transaction opens or the finalize step
 * leaks half-written rows on shape errors.
 */
final class AppUpdatePublishRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'Product' => ['required', 'string', 'max:64'],
            'Channel' => ['required', 'string', 'max:16'],
            'Version' => ['required', 'string', 'regex:/^\d+\.\d+\.\d+([-+][0-9A-Za-z.\-]+)?$/'],
            'MinRequiredVersion' => ['required', 'string', 'regex:/^\d+\.\d+\.\d+([-+][0-9A-Za-z.\-]+)?$/'],
            'ReleaseNotesUrl' => ['nullable', 'string', 'max:512'],
            'Assets' => ['required', 'array', 'min:1'],
            'Assets.*.Platform' => ['required', 'string', 'max:32'],
            'Assets.*.Sha256' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/'],
            'Assets.*.SizeBytes' => ['required', 'integer', 'min:1'],
            'Assets.*.UploadToken' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/'],
        ];
    }

    protected function failedValidation(\Illuminate\Contracts\Validation\Validator $validator): void
    {
        $errors = $validator->errors()->toArray();
        $first = array_key_first($errors) ?? 'Body';
        $isVersion = in_array($first, ['Version', 'MinRequiredVersion'], true);
        throw ValidationException::custom(
            $isVersion ? 'ValidationInvalidVersion' : 'ValidationInputInvalid',
            (string) ($errors[$first][0] ?? 'Invalid request body.'),
            [['Field' => $first, 'Rule' => 'FormatInvalid']],
        );
    }

    /**
     * @return array{Product:string,Channel:string,Version:string,MinRequiredVersion:string,ReleaseNotesUrl:?string,Assets:array<int,array{Platform:string,Sha256:string,SizeBytes:int,UploadToken:string}>}
     */
    public function payload(): array
    {
        $assets = [];
        foreach ((array) $this->input('Assets', []) as $a) {
            $assets[] = [
                'Platform' => (string) ($a['Platform'] ?? ''),
                'Sha256' => strtolower((string) ($a['Sha256'] ?? '')),
                'SizeBytes' => (int) ($a['SizeBytes'] ?? 0),
                'UploadToken' => strtolower((string) ($a['UploadToken'] ?? '')),
            ];
        }

        return [
            'Product' => (string) $this->input('Product'),
            'Channel' => (string) $this->input('Channel'),
            'Version' => (string) $this->input('Version'),
            'MinRequiredVersion' => (string) $this->input('MinRequiredVersion'),
            'ReleaseNotesUrl' => $this->input('ReleaseNotesUrl') === null ? null : (string) $this->input('ReleaseNotesUrl'),
            'Assets' => $assets,
        ];
    }
}
