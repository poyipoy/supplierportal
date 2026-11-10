<?php

namespace App\Casts;

use App\Enums\PurchaseRequisitionStatus;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Dual-compatible status cast for PurchaseRequisition:
 * - Validates and normalizes writes via PurchaseRequisitionStatus::tryFromWithLegacy().
 * - Returns the canonical lowercase string value on read so all existing strict comparisons (===)
 *   remain 100% compatible, while $pr->statusEnum() exposes the typed enum.
 *
 * @implements CastsAttributes<string, string|PurchaseRequisitionStatus>
 */
class PurchaseRequisitionStatusCast implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        $raw = strtolower(trim((string) $value));

        return PurchaseRequisitionStatus::tryFromWithLegacyOrNull($raw)?->value ?? (string) $value;
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        return PurchaseRequisitionStatus::tryFromWithLegacy($value)->value;
    }
}
