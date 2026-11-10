<?php

namespace App\Casts;

use App\Enums\PurchaseOrderStatus;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Dual-compatible status cast for PurchaseOrder:
 * - Validates and normalizes writes via PurchaseOrderStatus::tryFromWithLegacy().
 * - Returns the canonical lowercase string value on read so all existing strict comparisons (===)
 *   remain 100% compatible, while $po->statusEnum() exposes the typed enum.
 *
 * @implements CastsAttributes<string, string|PurchaseOrderStatus>
 */
class PurchaseOrderStatusCast implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        $raw = strtolower(trim((string) $value));

        return PurchaseOrderStatus::tryFromWithLegacyOrNull($raw)?->value ?? (string) $value;
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        return PurchaseOrderStatus::tryFromWithLegacy($value)->value;
    }
}
