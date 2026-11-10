<?php

namespace App\Casts;

use App\Enums\QcInspectionStatus;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Dual-compatible status cast for QcInspection:
 * - Validates and normalizes writes via QcInspectionStatus::tryFromWithLegacy().
 * - Returns the canonical lowercase string value on read so all existing strict comparisons (===)
 *   remain 100% compatible, while $inspection->statusEnum() exposes the typed enum.
 *
 * @implements CastsAttributes<string, string|QcInspectionStatus>
 */
class QcInspectionStatusCast implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        $raw = strtolower(trim((string) $value));

        return QcInspectionStatus::tryFromWithLegacyOrNull($raw)?->value ?? (string) $value;
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        return QcInspectionStatus::tryFromWithLegacy($value)->value;
    }
}
