<?php

declare(strict_types=1);

namespace App\Contracts\BR;

/**
 * Plan 14 step 8. Contract for KEK material lookup by `SecretsRef`
 * (the opaque string stored in `BrKekEpochs.SecretsRef`, e.g.
 * `br/kek/epoch-0`). The provider MUST return exactly 32 raw bytes
 * or throw. Implementations MUST NEVER log the returned material.
 *
 * Normative: spec 26 §09 "Key Hierarchy" (Root KEK held by
 * SecretsProvider, never touches disk in cleartext).
 */
interface SecretsProviderContract
{
    /**
     * @param string $ref opaque reference (matches `BrKekEpochs.SecretsRef`).
     * @return string exactly 32 raw bytes.
     */
    public function getKekMaterial(string $ref): string;
}
