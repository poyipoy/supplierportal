<?php

namespace App\Casts;

use App\Enums\SupplierChangeRequestStatus;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Dual-compatible status cast for SupplierChangeRequest.
 *
 * @implements CastsAttributes<string|null, string|SupplierChangeRequestStatus|null>
 */
class SupplierChangeRequestStatusCast implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof SupplierChangeRequestStatus) {
            return $value->value;
        }

        return SupplierChangeRequestStatus::tryFromWithLegacyOrNull((string) $value)?->value ?? (string) $value;
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return SupplierChangeRequestStatus::tryFromWithLegacy($value)->value;
    }
}
