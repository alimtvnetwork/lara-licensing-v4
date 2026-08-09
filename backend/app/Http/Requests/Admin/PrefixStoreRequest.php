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
 * `POST /Api/Admin/Prefixes`. Extracted from
 * PrefixController::validatePayload so the endpoint audit can see
 * a typed FormRequest and clear the `missing-request` finding.
 */
final class PrefixStoreRequest extends FormRequest
{
    public const PREFIX_REGEX = '/^[A-Z0-9]{2,12}$/';

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
            'ResellerId' => ['required', 'integer', 'min:1'],
            'PrefixValue' => ['required', 'string', 'regex:' . self::PREFIX_REGEX],
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
            'Prefix payload failed validation.',
            $errors,
        );
    }

    /**
     * @return array{ResellerId:int,PrefixValue:string,IsActive?:bool}
     */
    public function payload(): array
    {
        /** @var array{ResellerId:int,PrefixValue:string,IsActive?:bool} $v */
        $v = $this->validated();

        return $v;
    }
}
