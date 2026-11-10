<?php

namespace App\Casts;

use App\Enums\SupplierBankAccountStatus;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Dual-compatible status cast for SupplierBankAccount.
 *
 * @implements CastsAttributes<string|null, string|SupplierBankAccountStatus|null>
 */
class SupplierBankAccountStatusCast implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof SupplierBankAccountStatus) {
            return $value->value;
        }

        return SupplierBankAccountStatus::tryFromWithLegacyOrNull((string) $value)?->value ?? (string) $value;
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return SupplierBankAccountStatus::tryFromWithLegacy($value)->value;
    }
}
