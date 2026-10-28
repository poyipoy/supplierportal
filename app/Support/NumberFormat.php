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

    /**
     * maxDecimals() precision with ',' thousands grouping and '.' decimals. Plain decimal
     * strings and integers take an exact BCMath path; '-' marks an unusable value.
     */
    public static function grouped(mixed $value, int $decimals = 2): string
    {
        $decimals = max(0, $decimals);
        if (is_int($value) || (is_string($value) && preg_match('/^\s*-?\d+(\.\d+)?\s*$/', $value))) {
            $text = Money::normalize(is_int($value) ? $value : trim($value), $decimals);
            if ($decimals > 0) {
                $text = rtrim(rtrim($text, '0'), '.');
            }
            if ($text === '' || $text === '-0') {
                $text = '0';
            }
        } else {
            $text = self::maxDecimals($value, $decimals);
            if ($text === '-') {
                return '-';
            }
        }

        $parts = explode('.', $text, 2);

        return preg_replace('/\B(?=(?:[0-9]{3})+(?![0-9]))/', ',', $parts[0]).(isset($parts[1]) ? '.'.$parts[1] : '');
    }
}
