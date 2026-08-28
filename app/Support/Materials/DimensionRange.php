<?php

namespace App\Support\Materials;

use App\Support\NumberFormat;

/**
 * Normalized exact/range representation for dimensional inputs.
 *
 * The database keeps an exact length in available_length, or a range in
 * available_length_min/available_length_max.  This value object keeps the
 * parser shared by HTTP and spreadsheet input so neither path stores a raw
 * range string or silently invents a midpoint.
 */
final class DimensionRange
{
    public function __construct(
        public readonly ?float $exact,
        public readonly ?float $min,
        public readonly ?float $max,
    ) {}

    public static function parse(mixed $value): ?self
    {
        if ($value instanceof self) {
            return $value;
        }

        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }

        if (is_int($value) || is_float($value)) {
            $number = (float) $value;

            return self::validNumber($number)
                ? new self($number, null, null)
                : null;
        }

        if (! is_string($value)) {
            return null;
        }

        $normalized = trim(str_replace('–', '-', $value));
        if (! preg_match('/^([0-9]+(?:\.[0-9]+)?)\s*(?:-\s*([0-9]+(?:\.[0-9]+)?))?$/', $normalized, $matches)) {
            return null;
        }

        $first = (float) $matches[1];
        if (! self::validNumber($first)) {
            return null;
        }

        if (! isset($matches[2]) || $matches[2] === '') {
            return new self($first, null, null);
        }

        $last = (float) $matches[2];
        if (! self::validNumber($last) || $first > $last) {
            return null;
        }

        return new self(null, $first, $last);
    }

    public function isRange(): bool
    {
        return $this->min !== null && $this->max !== null;
    }

    public function isExact(): bool
    {
        return $this->exact !== null;
    }

    public function contains(float $value): bool
    {
        if ($this->isExact()) {
            return abs($this->exact - $value) <= 0.0001;
        }

        return $this->isRange() && $value >= $this->min && $value <= $this->max;
    }

    public function display(): string
    {
        if ($this->isExact()) {
            return self::format($this->exact);
        }

        if ($this->isRange()) {
            return self::format($this->min).' - '.self::format($this->max);
        }

        return '-';
    }

    /**
     * Return the persistence fields for available_length and its range.
     *
     * @return array{available_length: ?float, available_length_min: ?float, available_length_max: ?float}
     */
    public function toPersistence(): array
    {
        return [
            'available_length' => $this->exact,
            'available_length_min' => $this->min,
            'available_length_max' => $this->max,
        ];
    }

    private static function validNumber(float $value): bool
    {
        return is_finite($value) && $value > 0 && round($value, 4, PHP_ROUND_HALF_UP) > 0;
    }

    private static function format(?float $value): string
    {
        if ($value === null) {
            return '-';
        }

        return NumberFormat::maxDecimals($value, 2);
    }
}
