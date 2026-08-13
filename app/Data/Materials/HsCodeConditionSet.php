<?php

namespace App\Data\Materials;

use InvalidArgumentException;

final readonly class HsCodeConditionSet
{
    private const DIMENSIONS = ['thickness', 'd_inner', 'd_outer', 'width', 'length'];

    public function __construct(public array $conditions) {}

    public static function fromArray(array $conditions): self
    {
        $normalized = [];

        foreach ($conditions as $dimension => $bounds) {
            if (! in_array($dimension, self::DIMENSIONS, true) || ! is_array($bounds)) {
                throw new InvalidArgumentException("Invalid HS Code dimension: {$dimension}.");
            }

            $min = array_key_exists('min', $bounds) && $bounds['min'] !== null && $bounds['min'] !== ''
                ? self::numericBound($bounds['min'], "{$dimension}.min")
                : null;
            $max = array_key_exists('max', $bounds) && $bounds['max'] !== null && $bounds['max'] !== ''
                ? self::numericBound($bounds['max'], "{$dimension}.max")
                : null;

            if ($min === null && $max === null) {
                throw new InvalidArgumentException("{$dimension} must have a min or max bound.");
            }

            if ($min !== null && $max !== null && $min > $max) {
                throw new InvalidArgumentException("{$dimension}.min cannot exceed {$dimension}.max.");
            }

            $normalized[$dimension] = [
                'min' => $min,
                'min_inclusive' => (bool) ($bounds['min_inclusive'] ?? true),
                'max' => $max,
                'max_inclusive' => (bool) ($bounds['max_inclusive'] ?? true),
            ];
        }

        ksort($normalized);

        return new self($normalized);
    }

    public function evaluate(array $dimensions): ?bool
    {
        foreach ($this->conditions as $dimension => $bounds) {
            $raw = $dimensions[$dimension] ?? null;
            if ($raw === null || $raw === '' || ! is_numeric($raw)) {
                return null;
            }

            $value = (float) $raw;
            if ($bounds['min'] !== null) {
                if ($value < $bounds['min'] || ($value === $bounds['min'] && ! $bounds['min_inclusive'])) {
                    return false;
                }
            }

            if ($bounds['max'] !== null) {
                if ($value > $bounds['max'] || ($value === $bounds['max'] && ! $bounds['max_inclusive'])) {
                    return false;
                }
            }
        }

        return true;
    }

    public function overlaps(self $other): bool
    {
        foreach (array_unique(array_merge(array_keys($this->conditions), array_keys($other->conditions))) as $dimension) {
            $left = $this->conditions[$dimension] ?? self::unbounded();
            $right = $other->conditions[$dimension] ?? self::unbounded();

            if (self::endsBefore($left, $right) || self::endsBefore($right, $left)) {
                return false;
            }
        }

        return true;
    }

    public function exactlyEquals(self $other): bool
    {
        return $this->conditions === $other->conditions;
    }

    public function toArray(): array
    {
        return $this->conditions;
    }

    private static function numericBound(mixed $value, string $field): float
    {
        if (! is_numeric($value)) {
            throw new InvalidArgumentException("{$field} must be numeric.");
        }

        return (float) $value;
    }

    private static function unbounded(): array
    {
        return [
            'min' => null,
            'min_inclusive' => true,
            'max' => null,
            'max_inclusive' => true,
        ];
    }

    private static function endsBefore(array $left, array $right): bool
    {
        if ($left['max'] === null || $right['min'] === null) {
            return false;
        }

        if ($left['max'] < $right['min']) {
            return true;
        }

        return $left['max'] === $right['min']
            && (! $left['max_inclusive'] || ! $right['min_inclusive']);
    }
}
