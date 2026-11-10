<?php

namespace App\Casts;

use App\Enums\QuotationStatus;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Dual-compatible status cast for Quotation:
 * - Validates and normalizes writes via QuotationStatus::tryFromWithLegacy().
 * - Returns the canonical lowercase string value on read so all existing strict comparisons (===)
 *   remain 100% compatible, while $quotation->statusEnum() exposes the typed enum.
 *
 * @implements CastsAttributes<string, string|QuotationStatus>
 */
class QuotationStatusCast implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        $raw = strtolower(trim((string) $value));

        return QuotationStatus::tryFromWithLegacyOrNull($raw)?->value ?? (string) $value;
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        return QuotationStatus::tryFromWithLegacy($value)->value;
    }
}
