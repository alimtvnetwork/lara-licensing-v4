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
 * Plan 10 step 2 (phase D-2). Query + route-param validation for
 * `POST /Api/Admin/AppUpdates/{Version}/Yank`.
 *
 * Root cause of the parity gap (one sentence): the yank handler read
 * `Product` off the query string via `$request->query()` with no
 * FormRequest binding, so `AuditEndpoints.php` reflection saw a bare
 * `Illuminate\Http\Request` signature and flagged the route as
 * `missing-request` despite the wire contract being well-defined.
 *
 * Wire parity: keeps the existing `?Product=` default of `lara-cli`
 * (spec 17 v1.3.0 §"v1.0 rollout policy") and rejects malformed
 * `Version` path segments before they reach the row lookup.
 */
final class AppUpdateYankRequest extends FormRequest
{
    public const PRODUCT_MAX = 64;
    public const VERSION_MAX = 64;
    public const PRODUCT_REGEX = '/^[a-z0-9][a-z0-9\-]{0,63}$/';
    public const VERSION_REGEX = '/^[0-9]+\.[0-9]+\.[0-9]+(?:[\-+][A-Za-z0-9.\-]+)?$/';

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        // Version arrives as a route parameter; merge it into the input
        // bag so FormRequest rules can validate it uniformly.
        $version = (string) $this->route('version');
        $product = trim((string) $this->query('Product', 'lara-cli'));
        $this->merge([
            'Product' => $product === '' ? 'lara-cli' : $product,
            'Version' => $version,
        ]);
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'Product' => ['required', 'string', 'max:' . self::PRODUCT_MAX, 'regex:' . self::PRODUCT_REGEX],
            'Version' => ['required', 'string', 'max:' . self::VERSION_MAX, 'regex:' . self::VERSION_REGEX],
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
            'AppUpdate yank payload failed validation.',
            $errors,
        );
    }

    public function product(): string
    {
        /** @var array{Product:string,Version:string} $v */
        $v = $this->validated();

        return (string) $v['Product'];
    }

    public function version(): string
    {
        /** @var array{Product:string,Version:string} $v */
        $v = $this->validated();

        return (string) $v['Version'];
    }
}
