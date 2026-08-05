<?php

namespace App\Support;

final class SpreadsheetCellSanitizer
{
    public static function text(mixed $value, string $fallback = '-'): string
    {
        if ($value === null) {
            return $fallback;
        }

        $text = trim((string) $value);

        if ($text === '') {
            return $fallback;
        }

        return preg_match('/^[=+\-@]/u', $text) === 1
            ? "'".$text
            : $text;
    }
}
