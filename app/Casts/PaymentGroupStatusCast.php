<?php

namespace App\Casts;

use App\Enums\PaymentGroupStatus;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Dual-compatible status cast for PaymentGroup:
 * - Validates and normalizes writes via PaymentGroupStatus::tryFromWithLegacy().
 * - Returns the canonical string value on read so all existing strict comparisons (===)
 *   remain 100% compatible, while $group->statusEnum() exposes the typed enum.
 *
 * @implements CastsAttributes<string, string|PaymentGroupStatus>
 */
class PaymentGroupStatusCast implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        $raw = strtoupper(trim((string) $value));

        return PaymentGroupStatus::tryFromWithLegacyOrNull($raw)?->value ?? (string) $value;
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        return PaymentGroupStatus::tryFromWithLegacy($value)->value;
    }
}
