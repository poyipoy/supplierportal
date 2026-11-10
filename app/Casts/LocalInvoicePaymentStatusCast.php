<?php

namespace App\Casts;

use App\Enums\LocalInvoicePaymentStatus;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Dual-compatible status cast for LocalInvoicePayment:
 * - Validates and normalizes writes via LocalInvoicePaymentStatus::tryFromWithLegacy().
 * - Returns the canonical string value on read so all existing strict comparisons (===)
 *   remain 100% compatible, while $payment->statusEnum() exposes the typed enum.
 *
 * @implements CastsAttributes<string, string|LocalInvoicePaymentStatus>
 */
class LocalInvoicePaymentStatusCast implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        $raw = strtoupper(trim((string) $value));

        return LocalInvoicePaymentStatus::tryFromWithLegacyOrNull($raw)?->value ?? (string) $value;
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        return LocalInvoicePaymentStatus::tryFromWithLegacy($value)->value;
    }
}
