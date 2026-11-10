<?php

namespace App\Casts;

use App\Enums\LocalInvoiceStatus;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Dual-compatible status cast for LocalInvoice:
 * - Validates and normalizes writes via LocalInvoiceStatus::tryFromWithLegacy().
 * - Returns the canonical string value on read so all existing strict comparisons (===)
 *   in controllers, policies, services, and Blade views remain 100% compatible,
 *   while $invoice->statusEnum() exposes the typed LocalInvoiceStatus enum.
 *
 * @implements CastsAttributes<string, string|LocalInvoiceStatus>
 */
class LocalInvoiceStatusCast implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        $raw = strtoupper(trim((string) $value));

        return LocalInvoiceStatus::tryFromWithLegacyOrNull($raw)?->value ?? (string) $value;
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof LocalInvoiceStatus) {
            return $value->value;
        }

        $resolved = LocalInvoiceStatus::tryFromWithLegacy($value);
        $raw = strtoupper(trim((string) $value));

        return $raw === 'SUBMITTED' ? $resolved->value : $raw;
    }
}
