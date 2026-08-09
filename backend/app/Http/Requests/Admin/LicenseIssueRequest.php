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
 * Plan 10 step 2 (phase B). Body validation for
 * `POST /Api/Admin/Licenses`, extracted from
 * LicenseController::validatePayload so the endpoint audit sensor
 * (backend/app/Console/Commands/AuditEndpoints.php) can detect a
 * typed FormRequest on the mutation handler.
 *
 * Root cause of the parity gap (one sentence): the issue handler
 * validated inline via `$request->validate()`, so reflection could
 * not see a FormRequest parameter and the route was flagged
 * `missing-request` by the audit.
 */
final class LicenseIssueRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        $tiers = array_keys((array) config('lara.license_tiers', []));
        $envs = (array) config('lara.environments', []);
        $categories = array_keys((array) config('lara.license_categories', []));

        return [
            'ResellerSlug' => ['required', 'string', 'regex:/^[a-z][a-z0-9-]{2,63}$/'],
            'PrefixValue' => ['required', 'string', 'regex:/^[A-Z0-9]{2,12}$/'],
            'TierName' => ['required', 'string', 'in:' . implode(',', $tiers)],
            'EnvironmentName' => ['required', 'string', 'in:' . implode(',', $envs)],
            'LicenseCategory' => ['sometimes', 'string', 'in:' . implode(',', $categories)],
            'ExpiresAt' => ['sometimes', 'string', 'date'],
            'Features' => ['sometimes', 'array'],
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        $errors = [];
        foreach ($validator->errors()->toArray() as $field => $messages) {
            foreach ((array) $messages as $msg) {
                $errors[] = ['Field' => (string) $field, 'Rule' => (string) $msg];
            }
        }
        throw ValidationException::validationFailed(
            'License payload failed validation.',
            $errors,
        );
    }

    /**
     * @return array{ResellerSlug:string, PrefixValue:string, TierName:string, EnvironmentName:string, ExpiresAt?:string, Features?:array<string, mixed>, LicenseCategory?:string}
     */
    public function payload(): array
    {
        /** @var array{ResellerSlug:string, PrefixValue:string, TierName:string, EnvironmentName:string, ExpiresAt?:string, Features?:array<string, mixed>, LicenseCategory?:string} $v */
        $v = $this->validated();

        return $v;
    }
}
