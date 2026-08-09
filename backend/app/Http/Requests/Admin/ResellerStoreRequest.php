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
 * Plan 10 step 2 (phase A). Body validation for
 * `POST /Api/Admin/Resellers`, extracted from
 * ResellerController::validatePayload so the endpoint audit
 * (backend/app/Console/Commands/AuditEndpoints.php) can see a
 * typed FormRequest parameter on the mutation handler.
 *
 * Root cause of the parity gap (one sentence): the store handler
 * validated inline via `$request->validate()`, so reflection could
 * not detect a FormRequest and flagged the route as missing.
 */
final class ResellerStoreRequest extends FormRequest
{
    public const SLUG_REGEX = '/^[a-z][a-z0-9-]{2,63}$/';

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'ResellerName' => ['required', 'string', 'min:2', 'max:128'],
            'ResellerSlug' => ['required', 'string', 'regex:' . self::SLUG_REGEX],
            'ContactEmail' => ['required', 'string', 'email', 'max:255'],
            'IsActive' => ['sometimes', 'boolean'],
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
            'Reseller payload failed validation.',
            $errors,
        );
    }

    /**
     * @return array{ResellerName:string,ResellerSlug:string,ContactEmail:string,IsActive?:bool}
     */
    public function payload(): array
    {
        /** @var array{ResellerName:string,ResellerSlug:string,ContactEmail:string,IsActive?:bool} $v */
        $v = $this->validated();

        return $v;
    }
}
