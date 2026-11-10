<?php

namespace App\Casts;

use App\Enums\GaClaimStatus;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Dual-compatible status cast for GaClaim:
 * - Validates and normalizes writes via GaClaimStatus::tryFromWithLegacy().
 * - Returns the canonical string value on read so all existing strict comparisons (===)
 *   remain 100% compatible, while $claim->statusEnum() exposes the typed enum.
 *
 * @implements CastsAttributes<string, string|GaClaimStatus>
 */
class GaClaimStatusCast implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        $raw = strtoupper(trim((string) $value));

        return GaClaimStatus::tryFromWithLegacyOrNull($raw)?->value ?? (string) $value;
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        return GaClaimStatus::tryFromWithLegacy($value)->value;
    }
}
