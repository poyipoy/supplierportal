<?php

namespace App\Casts;

use App\Enums\CustomsDocumentStatus;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Dual-compatible status cast for ShipmentDocument and PoDocument.
 *
 * @implements CastsAttributes<string|null, string|CustomsDocumentStatus|null>
 */
class CustomsDocumentStatusCast implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof CustomsDocumentStatus) {
            return $value->value;
        }

        return CustomsDocumentStatus::tryFromWithLegacyOrNull((string) $value)?->value ?? (string) $value;
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return CustomsDocumentStatus::tryFromWithLegacy($value)->value;
    }
}
