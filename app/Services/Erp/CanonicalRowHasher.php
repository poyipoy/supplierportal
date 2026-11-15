<?php

namespace App\Services\Erp;

use InvalidArgumentException;

final class CanonicalRowHasher
{
    private const QTY_DECIMALS = 4;

    private const PRICE_DECIMALS = 2;

    /**
     * Compute 64-bit xxh3 hex digest matching Rust `compute_canonical_xxh3`.
     */
    public static function hash(
        string $docNo,
        string $itemCode,
        int|float|string $qty,
        int|float|string $price,
        string $status,
    ): string {
        return hash('xxh3', self::canonicalPayload($docNo, $itemCode, $qty, $price, $status));
    }

    /**
     * Canonical payload: UPPER(TRIM(doc_no))|UPPER(TRIM(item_code))|TRIM(qty_norm)|TRIM(price_norm)|UPPER(TRIM(status)).
     */
    public static function canonicalPayload(
        string $docNo,
        string $itemCode,
        int|float|string $qty,
        int|float|string $price,
        string $status,
    ): string {
        return implode('|', [
            self::normalizeUpper($docNo),
            self::normalizeUpper($itemCode),
            self::normalizeDecimal($qty, self::QTY_DECIMALS),
            self::normalizeDecimal($price, self::PRICE_DECIMALS),
            self::normalizeUpper($status),
        ]);
    }

    public static function normalizeDecimal(int|float|string $value, int $decimals): string
    {
        if (is_int($value)) {
            if ($value < 0) {
                throw new InvalidArgumentException('Numeric value must be non-negative.');
            }

            return number_format((float) $value, $decimals, '.', '');
        }

        if (is_float($value)) {
            if (! is_finite($value) || $value < 0.0) {
                throw new InvalidArgumentException('Numeric value must be finite and non-negative.');
            }

            $normalized = $value == 0.0 ? 0.0 : $value;

            return number_format($normalized, $decimals, '.', '');
        }

        $trimmed = self::trimUnicode($value);
        $clean = str_replace(',', '', $trimmed);

        if ($clean === '' || ! is_numeric($clean)) {
            throw new InvalidArgumentException('Value is not a valid decimal number.');
        }

        $numeric = (float) $clean;
        if (! is_finite($numeric) || $numeric < 0.0) {
            throw new InvalidArgumentException('Numeric value must be finite and non-negative.');
        }

        if ($numeric == 0.0) {
            $numeric = 0.0;
        }

        return number_format($numeric, $decimals, '.', '');
    }

    private static function normalizeUpper(string $value): string
    {
        return mb_strtoupper(self::trimUnicode($value), 'UTF-8');
    }

    private static function trimUnicode(string $value): string
    {
        return preg_replace('/^\s+|\s+$/u', '', $value) ?? trim($value);
    }
}
