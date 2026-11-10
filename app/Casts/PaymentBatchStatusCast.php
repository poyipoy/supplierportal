<?php

namespace App\Casts;

use App\Enums\PaymentBatchStatus;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Dual-compatible status cast for PaymentBatch:
 * - Validates and normalizes writes via PaymentBatchStatus::tryFromWithLegacy().
 * - Returns the canonical string value on read so all existing strict comparisons (===)
 *   remain 100% compatible, while $batch->statusEnum() exposes the typed enum.
 *
 * @implements CastsAttributes<string, string|PaymentBatchStatus>
 */
class PaymentBatchStatusCast implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        $raw = strtoupper(trim((string) $value));

        return PaymentBatchStatus::tryFromWithLegacyOrNull($raw)?->value ?? (string) $value;
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        return PaymentBatchStatus::tryFromWithLegacy($value)->value;
    }
}
