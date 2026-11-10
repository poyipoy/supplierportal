<?php

namespace App\Casts;

use App\Enums\LocalGoodsReceiptStatus;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Dual-compatible status cast for LocalGoodsReceipt:
 * - Validates and normalizes writes via LocalGoodsReceiptStatus::tryFromWithLegacy().
 * - Returns the canonical string value on read so all existing strict comparisons (===)
 *   remain 100% compatible, while $receipt->statusEnum() exposes the typed enum.
 *
 * @implements CastsAttributes<string, string|LocalGoodsReceiptStatus>
 */
class LocalGoodsReceiptStatusCast implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        $raw = strtoupper(trim((string) $value));

        return LocalGoodsReceiptStatus::tryFromWithLegacyOrNull($raw)?->value ?? (string) $value;
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        return LocalGoodsReceiptStatus::tryFromWithLegacy($value)->value;
    }
}
