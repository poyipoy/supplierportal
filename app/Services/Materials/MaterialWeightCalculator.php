<?php

namespace App\Services\Materials;

use App\Data\Materials\WeightCalculationResult;
use App\Models\MaterialMaster;
use App\Models\PrItem;

final class MaterialWeightCalculator
{
    private const FLAT_STEEL_FACTOR = 0.00785;

    private const FLAT_ALUMINIUM_FACTOR = 0.00273;

    private const ROUND_HOLLOW_FACTOR = 0.006167;

    public function calculate(MaterialMaster $material, ?string $shape, array $dimensions, int $quantity = 1): WeightCalculationResult
    {
        if (! in_array($shape, PrItem::SHAPES, true)) {
            return $this->incomplete('Select a material shape to calculate KG per unit.');
        }

        foreach (PrItem::relevantDimensionFields($shape) as $field) {
            $value = $dimensions[$field] ?? null;
            if ($value === null || $value === '') {
                return $this->incomplete('Complete all dimensions required by the selected shape.');
            }
            if (! is_numeric($value) || (float) $value <= 0) {
                return $this->invalid('Dimensions must be numeric values greater than zero.');
            }
        }

        $thickness = (float) ($dimensions['thickness'] ?? 0);
        $inner = (float) ($dimensions['d_inner'] ?? 0);
        $outer = (float) ($dimensions['d_outer'] ?? 0);
        $width = (float) ($dimensions['width'] ?? 0);
        $length = (float) ($dimensions['length'] ?? 0);

        if ($shape === PrItem::SHAPE_HOLLOW && $inner >= $outer) {
            return $this->invalid('Inner diameter must be smaller than outer diameter.');
        }

        [$rawWeight, $formulaKey, $factor] = match ($shape) {
            PrItem::SHAPE_FLAT => $this->flat($material, $thickness, $width, $length),
            PrItem::SHAPE_ROUND => [($outer * $outer * $length * self::ROUND_HOLLOW_FACTOR) / 1000, 'round_v1', self::ROUND_HOLLOW_FACTOR],
            PrItem::SHAPE_HOLLOW => [((($outer * $outer) - ($inner * $inner)) * $length * self::ROUND_HOLLOW_FACTOR) / 1000, 'hollow_v1', self::ROUND_HOLLOW_FACTOR],
        };

        $unitKg = round($rawWeight, 4, PHP_ROUND_HALF_UP);
        $totalKg = round($unitKg * max(1, $quantity), 4, PHP_ROUND_HALF_UP);

        return new WeightCalculationResult(
            'calculated',
            $unitKg,
            $totalKg,
            $formulaKey,
            $factor,
            'KG per unit was calculated by the server.',
        );
    }

    private function flat(MaterialMaster $material, float $thickness, float $width, float $length): array
    {
        $isAluminium = $material->density_profile === MaterialMaster::DENSITY_ALUMINIUM;
        $factor = $isAluminium ? self::FLAT_ALUMINIUM_FACTOR : self::FLAT_STEEL_FACTOR;

        return [
            ($thickness * $width * $length * $factor) / 1000,
            $isAluminium ? 'flat_aluminium_v1' : 'flat_steel_v1',
            $factor,
        ];
    }

    private function incomplete(string $message): WeightCalculationResult
    {
        return new WeightCalculationResult('incomplete', null, null, null, null, $message);
    }

    private function invalid(string $message): WeightCalculationResult
    {
        return new WeightCalculationResult('invalid', null, null, null, null, $message);
    }
}
