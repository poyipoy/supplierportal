<?php

namespace App\Casts;

use App\Enums\SupplierRegistrationAttemptStatus;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Dual-compatible status cast for SupplierRegistrationAttempt.
 *
 * @implements CastsAttributes<string|null, string|SupplierRegistrationAttemptStatus|null>
 */
class SupplierRegistrationAttemptStatusCast implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof SupplierRegistrationAttemptStatus) {
            return $value->value;
        }

        return SupplierRegistrationAttemptStatus::tryFromWithLegacyOrNull((string) $value)?->value ?? (string) $value;
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return SupplierRegistrationAttemptStatus::tryFromWithLegacy($value)->value;
    }
}
