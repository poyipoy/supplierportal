<?php

namespace App\Casts;

use App\Enums\MaterialClaimStatus;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Dual-compatible status cast for MaterialClaim:
 * - Validates and normalizes writes via MaterialClaimStatus::tryFromWithLegacy().
 * - Returns the canonical lowercase string value on read so all existing strict comparisons (===)
 *   remain 100% compatible, while $claim->statusEnum() exposes the typed enum.
 *
 * @implements CastsAttributes<string, string|MaterialClaimStatus>
 */
class MaterialClaimStatusCast implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        $raw = strtolower(trim((string) $value));

        return MaterialClaimStatus::tryFromWithLegacyOrNull($raw)?->value ?? (string) $value;
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        return MaterialClaimStatus::tryFromWithLegacy($value)->value;
    }
}
