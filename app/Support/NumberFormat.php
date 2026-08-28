<?php

namespace App\Support;

final class NumberFormat
{
    public static function maxDecimals(mixed $value, int $decimals = 2): string
    {
        if ($value === null || $value === '' || ! is_numeric($value) || ! is_finite((float) $value)) {
            return '-';
        }

        $decimals = max(0, $decimals);
        $formatted = number_format((float) $value, $decimals, '.', '');

        if ($decimals === 0) {
            return $formatted;
        }

        $formatted = rtrim(rtrim($formatted, '0'), '.');

        return $formatted === '' || $formatted === '-0' ? '0' : $formatted;
    }
}
