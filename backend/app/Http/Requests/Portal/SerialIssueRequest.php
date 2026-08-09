<?php

declare(strict_types=1);

namespace App\Http\Requests\Portal;

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
 * Plan 10 step 2 (phase D-3). FormRequest for `POST /Api/Portal/Serials`.
 *
 * Root cause of the parity gap (one sentence):
 * `Portal\SerialController::issue()` validated inline via
 * `$request->validate()`, so `AuditEndpoints.php` reflection saw a bare
 * `Illuminate\Http\Request` signature and flagged the route as
 * `missing-request` despite the wire contract being fully defined.
 *
 * Wire parity: preserves the exact `ValidationFailed` envelope produced
 * by `SerialController::validatePayload()` (Rule = 'Invalid' per field).
 */
final class SerialIssueRequest extends FormRequest
{
    public const LICENSE_KEY_REGEX = '/^[A-Z0-9]{2,12}-[A-Z0-9-]{4,80}$/';
    public const DEVICE_HASH_REGEX = '/^[a-f0-9]{64}$/';
    public const FEATURE_HASH_REGEX = '/^[a-f0-9]{64}$/';

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        $envs = (array) config('lara.environments', []);

        return [
            'LicenseKey' => ['required', 'string', 'regex:' . self::LICENSE_KEY_REGEX],
            'DeviceIdHash' => ['required', 'string', 'regex:' . self::DEVICE_HASH_REGEX],
            'EnvironmentName' => ['required', 'string', 'in:' . implode(',', $envs)],
            'FeaturePayloadHash' => ['nullable', 'string', 'regex:' . self::FEATURE_HASH_REGEX],
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        $details = [];
        foreach ($validator->errors()->toArray() as $field => $_messages) {
            $details[] = ['Field' => (string) $field, 'Rule' => 'Invalid'];
        }
        throw ValidationException::validationFailed(
            'Serial issue payload failed validation.',
            $details,
        );
    }

    /**
     * @return array{LicenseKey:string, DeviceIdHash:string, EnvironmentName:string, FeaturePayloadHash?:string}
     */
    public function payload(): array
    {
        /** @var array{LicenseKey:string, DeviceIdHash:string, EnvironmentName:string, FeaturePayloadHash?:string} $v */
        $v = $this->validated();

        return $v;
    }
}
