<?php

namespace App\Casts;

use App\Enums\SupplierAuditStatus;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Dual-compatible status cast for SupplierAudit.
 *
 * Returns canonical string on get() so all existing strict (===) checks in
 * controllers, policies, and Blade views remain 100% compatible, while
 * validating and normalizing via SupplierAuditStatus on set().
 *
 * @implements CastsAttributes<string|null, string|SupplierAuditStatus|null>
 */
class SupplierAuditStatusCast implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof SupplierAuditStatus) {
            return $value->value;
        }

        return SupplierAuditStatus::tryFromWithLegacyOrNull((string) $value)?->value ?? (string) $value;
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return SupplierAuditStatus::tryFromWithLegacy($value)->value;
    }
}
