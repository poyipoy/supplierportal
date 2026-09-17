<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Exact decimal arithmetic for monetary values.
 *
 * Money in this system is stored as DECIMAL and must never be accumulated,
 * compared or derived through PHP floats. Every method here takes and returns
 * decimal strings so that the value crossing the application/database boundary
 * is the value that was computed.
 *
 * This helper is deliberately minimal. It is not an accounting framework: it
 * exposes only the operations the financial write paths actually perform.
 */
final class Money
{
    /** Scale used for IDR amounts throughout the payment domain. */
    public const SCALE = 2;

    public const ZERO = '0.00';

    /**
     * Normalize any supported representation to a decimal string at $scale,
     * rounding half away from zero.
     *
     * Floats are accepted because legacy call sites and Eloquent float casts
     * still produce them, but they are converted through a fixed-notation
     * string so that no exponent form ever reaches BCMath.
     */
    public static function normalize(string|int|float|null $value, int $scale = self::SCALE): string
    {
        if ($value === null || $value === '') {
            return self::zero($scale);
        }

        if (is_int($value)) {
            $raw = (string) $value;
        } elseif (is_float($value)) {
            if (! is_finite($value)) {
                throw new InvalidArgumentException('Monetary value must be a finite number.');
            }
            $raw = sprintf('%.'.($scale + 8).'F', $value);
        } else {
            $raw = trim($value);
            if (! preg_match('/^-?\d+(\.\d+)?$/', $raw)) {
                throw new InvalidArgumentException("Monetary value [{$value}] is not a plain decimal string.");
            }
        }

        return self::roundHalfUp($raw, $scale);
    }

    public static function add(string|int|float|null $a, string|int|float|null $b, int $scale = self::SCALE): string
    {
        return bcadd(self::normalize($a, $scale), self::normalize($b, $scale), $scale);
    }

    public static function subtract(string|int|float|null $a, string|int|float|null $b, int $scale = self::SCALE): string
    {
        return bcsub(self::normalize($a, $scale), self::normalize($b, $scale), $scale);
    }

    /**
     * Multiply an amount by a factor. The factor keeps extra working precision
     * so that percentage rates (e.g. a 2.00% withholding rate) do not lose
     * digits before the final rounding step.
     */
    public static function multiply(string|int|float|null $amount, string|int|float|null $factor, int $scale = self::SCALE): string
    {
        $working = $scale + 8;

        return self::roundHalfUp(
            bcmul(self::normalize($amount, $working), self::normalize($factor, $working), $working),
            $scale
        );
    }

    /** Returns -1, 0 or 1, following bccomp. */
    public static function compare(string|int|float|null $a, string|int|float|null $b, int $scale = self::SCALE): int
    {
        return bccomp(self::normalize($a, $scale), self::normalize($b, $scale), $scale);
    }

    /**
     * @param  iterable<string|int|float|null>  $values
     */
    public static function sum(iterable $values, int $scale = self::SCALE): string
    {
        $total = self::zero($scale);
        foreach ($values as $value) {
            $total = bcadd($total, self::normalize($value, $scale), $scale);
        }

        return $total;
    }

    /** Clamp a negative amount to zero, replacing max(0.0, $x) on floats. */
    public static function atLeastZero(string|int|float|null $value, int $scale = self::SCALE): string
    {
        $normalized = self::normalize($value, $scale);

        return bccomp($normalized, '0', $scale) < 0 ? self::zero($scale) : $normalized;
    }

    public static function zero(int $scale = self::SCALE): string
    {
        return bcadd('0', '0', $scale);
    }

    private static function roundHalfUp(string $raw, int $scale): string
    {
        $half = $scale > 0
            ? '0.'.str_repeat('0', $scale).'5'
            : '0.5';

        return str_starts_with($raw, '-')
            ? bcsub($raw, $half, $scale)
            : bcadd($raw, $half, $scale);
    }
}
