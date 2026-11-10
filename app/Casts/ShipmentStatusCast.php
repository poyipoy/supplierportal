<?php

namespace App\Casts;

use App\Enums\ShipmentStatus;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Dual-compatible status cast for Shipment:
 * - Validates and normalizes writes via ShipmentStatus::tryFromWithLegacy().
 * - Returns the canonical lowercase string value on read so all existing strict comparisons (===)
 *   remain 100% compatible, while $shipment->statusEnum() exposes the typed enum.
 *
 * @implements CastsAttributes<string, string|ShipmentStatus>
 */
class ShipmentStatusCast implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        $raw = strtolower(trim((string) $value));

        return ShipmentStatus::tryFromWithLegacyOrNull($raw)?->value ?? (string) $value;
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        return ShipmentStatus::tryFromWithLegacy($value)->value;
    }
}
