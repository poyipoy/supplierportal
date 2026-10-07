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
        if ($preserveWhitespace) {
            // Legacy raw-text exports keep safe text byte-for-byte; only escape
            // formula prefixes, including prefixes hidden behind whitespace.
            return preg_match('/^[=+\-@]/u', trim($text)) === 1 ? "'".$text : $text;
        }
        $text = trim($text);

        if ($text === '') {
            return $fallback;
        }

        return preg_match('/^[=+\-@]/u', $text) === 1
            ? "'".$text
            : $text;
    }
}
