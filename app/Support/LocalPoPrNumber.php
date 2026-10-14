<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;

final class LocalPoPrNumber
{
    public const MAX_LENGTH = 100;

    public static function normalize(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if ((! is_string($value) && ! is_int($value)) || ! mb_check_encoding((string) $value, 'UTF-8')) {
            throw ValidationException::withMessages(['pr_number' => __('local_procurement.pr_import.invalid')]);
        }
        $value = (string) $value;
        if (preg_match('/[\p{C},;]/u', $value) || str_starts_with(ltrim($value), '=')) {
            throw ValidationException::withMessages(['pr_number' => __('local_procurement.pr_import.invalid')]);
        }
        $value = trim($value);
        if (mb_strlen($value) > self::MAX_LENGTH) {
            throw ValidationException::withMessages(['pr_number' => __('local_procurement.pr_import.length', ['max' => self::MAX_LENGTH])]);
        }

        return $value === '' ? null : $value;
    }

    public static function key(?string $value): string
    {
        return mb_strtolower(trim($value ?? ''));
    }
}
