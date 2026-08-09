<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Exceptions\ValidationException;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Plan 06 step 45. Request body validation for
 * `POST /Api/Admin/AppUpdates/UploadTicket` per
 * spec/21-app/17-self-update-endpoint.md v1.3.0 §"POST UploadTicket".
 *
 * Root cause this class exists (one sentence): the ticket endpoint
 * takes five typed fields (Product, Version, Platform, SizeBytes,
 * Sha256) and every one must be pinned to a closed-set or format rule
 * before touching the DB, so we centralise the validation here rather
 * than scattering `->query()` checks across the controller.
 */
final class AppUpdateUploadTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'Product' => ['required', 'string', 'max:64'],
            'Version' => ['required', 'string', 'regex:/^\d+\.\d+\.\d+([-+][0-9A-Za-z.\-]+)?$/'],
            'Platform' => ['required', 'string', 'max:32'],
            'SizeBytes' => ['required', 'integer', 'min:1'],
            'Sha256' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/'],
        ];
    }

    protected function failedValidation(\Illuminate\Contracts\Validation\Validator $validator): void
    {
        $errors = $validator->errors()->toArray();
        $first = array_key_first($errors) ?? 'Body';
        $isVersion = $first === 'Version';
        throw ValidationException::custom(
            $isVersion ? 'ValidationInvalidVersion' : 'ValidationFailed',
            (string) ($errors[$first][0] ?? 'Invalid request body.'),
            [['Field' => $first, 'Rule' => 'FormatInvalid']],
        );
    }

    /**
     * @return array{Product:string,Version:string,Platform:string,SizeBytes:int,Sha256:string}
     */
    public function payload(): array
    {
        return [
            'Product' => (string) $this->input('Product'),
            'Version' => (string) $this->input('Version'),
            'Platform' => (string) $this->input('Platform'),
            'SizeBytes' => (int) $this->input('SizeBytes'),
            'Sha256' => strtolower((string) $this->input('Sha256')),
        ];
    }
}
