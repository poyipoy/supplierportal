<?php

namespace App\Support;

final class SpreadsheetCellSanitizer
{
    public static function text(mixed $value, string $fallback = '-', bool $preserveWhitespace = false): string
    {
        if ($value === null) {
            return $fallback;
        }

        $text = (string) $value;
        $hasFormulaPrefix = preg_match('/^(?:[\s\v\0\x{FEFF}]*[\t\r=+\-@])/u', $text) === 1;

        if ($preserveWhitespace) {
            // Legacy raw-text exports keep safe text byte-for-byte; only escape
            // formula prefixes, including prefixes hidden behind whitespace or tabs/CR.
            return $hasFormulaPrefix ? "'".$text : $text;
        }

        $trimmed = trim($text);
        if ($trimmed === '') {
            return $fallback;
        }

        return $hasFormulaPrefix
            ? "'".$trimmed
            : $trimmed;
    }
}
