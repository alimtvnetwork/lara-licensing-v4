<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Domain\BR\BrExportScopeKey;
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
 * Plan 14 step 9. Body validation for `POST /Api/Admin/Backup/Exports`.
 *
 * Contract (spec 26 §11 "Request Body"):
 *  - `scope.{schema,closedSets,features,licenses,rbac,secretsEnvelope,files}`: bool required.
 *  - `scope.domain`: array of table names OR the literal `["all"]`; empty allowed only when files=false.
 *  - `scope.schema` MUST be true (INV-BR-SC-1).
 *  - FK deps: `licenses=true` requires closedSets AND features true; `files=true` requires domain non-empty; `rbac=true` when domain non-empty.
 *  - `encryption.epoch` MUST be null (INV-BR-FS-1); any integer -> 422 `BackupCorrupt`.
 *  - `note`: string 1..280 chars, optional.
 *
 * Rules are declared via Laravel primitives; FK-dependency + epoch
 * checks land in `withValidator` so violations surface with the
 * spec-mandated JSON pointer (via LaraException details).
 */
final class BrExportRequest extends FormRequest
{
    public const MAX_NOTE_LEN     = 280;
    public const MAX_DOMAIN_ITEMS = 256;
    private const TABLE_NAME_REGEX = '/^[A-Za-z][A-Za-z0-9_]{0,62}$/';

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'scope'                   => ['required', 'array'],
            'scope.schema'            => ['required', 'boolean'],
            'scope.closedSets'        => ['required', 'boolean'],
            'scope.features'          => ['required', 'boolean'],
            'scope.licenses'          => ['required', 'boolean'],
            'scope.rbac'              => ['required', 'boolean'],
            'scope.secretsEnvelope'   => ['required', 'boolean'],
            'scope.files'             => ['required', 'boolean'],
            'scope.domain'            => ['required', 'array', 'max:' . self::MAX_DOMAIN_ITEMS],
            'scope.domain.*'          => ['string', 'regex:' . self::TABLE_NAME_REGEX],
            'encryption'              => ['sometimes', 'array'],
            'encryption.epoch'        => ['sometimes', 'nullable'],
            'note'                    => ['sometimes', 'string', 'min:1', 'max:' . self::MAX_NOTE_LEN],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $scope = (array) ($this->input('scope') ?? []);
            $this->assertSchemaMandatory($v, $scope);
            $this->assertFkDeps($v, $scope);
            $this->assertEpoch($v);
        });
    }

    /** @param array<string, mixed> $scope */
    private function assertSchemaMandatory(Validator $v, array $scope): void
    {
        if (!(bool) ($scope[BrExportScopeKey::Schema->value] ?? false)) {
            $v->errors()->add('scope.schema', 'Schema scope is mandatory (INV-BR-SC-1).');
        }
    }

    /** @param array<string, mixed> $scope */
    private function assertFkDeps(Validator $v, array $scope): void
    {
        $domain = (array) ($scope['domain'] ?? []);
        $licenses = (bool) ($scope[BrExportScopeKey::Licenses->value] ?? false);
        $features = (bool) ($scope[BrExportScopeKey::Features->value] ?? false);
        $closedSets = (bool) ($scope[BrExportScopeKey::ClosedSets->value] ?? false);
        if ($licenses && (!$features || !$closedSets)) {
            $v->errors()->add('scope.licenses', 'licenses=true requires closedSets=true and features=true.');
        }
        if ((bool) ($scope[BrExportScopeKey::Files->value] ?? false) && $domain === []) {
            $v->errors()->add('scope.files', 'files=true requires scope.domain to be non-empty.');
        }
        if ($domain !== [] && !(bool) ($scope[BrExportScopeKey::Rbac->value] ?? false)) {
            $v->errors()->add('scope.rbac', 'rbac=true is required when scope.domain is non-empty.');
        }
    }

    private function assertEpoch(Validator $v): void
    {
        if (!$this->has('encryption.epoch')) {
            return;
        }
        $epoch = $this->input('encryption.epoch');
        if ($epoch !== null) {
            $v->errors()->add('encryption.epoch', 'Only null is allowed (INV-BR-FS-1); export always seals under Active epoch.');
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
        throw ValidationException::validationFailed( 'Backup export payload failed validation.', $errors);
    }

    /**
     * @return array{Scope: array<string, mixed>, Note: ?string}
     */
    public function payload(): array
    {
        $v = $this->validated();

        return [
            'Scope' => (array) ($v['scope'] ?? []),
            'Note'  => isset($v['note']) ? (string) $v['note'] : null,
        ];
    }
}
