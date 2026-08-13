<?php

namespace Tests\Unit\Materials;

use App\Models\MaterialMaster;
use App\Models\PrItem;
use App\Services\Materials\MaterialWeightCalculator;
use PHPUnit\Framework\TestCase;

class MaterialWeightCalculatorTest extends TestCase
{
    private MaterialWeightCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calculator = new MaterialWeightCalculator;
    }

    public function test_exact_weight_formulas_and_quantity_semantics(): void
    {
        $steel = new MaterialMaster(['density_profile' => MaterialMaster::DENSITY_STEEL]);
        $aluminium = new MaterialMaster(['density_profile' => MaterialMaster::DENSITY_ALUMINIUM]);

        $flatSteel = $this->calculator->calculate($steel, PrItem::SHAPE_FLAT, [
            'thickness' => 10, 'width' => 100, 'length' => 1000,
        ], 2);
        $this->assertSame(7.8500, $flatSteel->unitKg);
        $this->assertSame(15.7000, $flatSteel->totalKg);
        $this->assertSame('flat_steel_v1', $flatSteel->formulaKey);

        $flatAluminium = $this->calculator->calculate($aluminium, PrItem::SHAPE_FLAT, [
            'thickness' => 10, 'width' => 100, 'length' => 1000,
        ]);
        $this->assertSame(2.7300, $flatAluminium->unitKg);
        $this->assertSame('flat_aluminium_v1', $flatAluminium->formulaKey);

        $round = $this->calculator->calculate($steel, PrItem::SHAPE_ROUND, [
            'd_outer' => 100, 'length' => 1000,
        ]);
        $this->assertSame(61.6700, $round->unitKg);

        $hollow = $this->calculator->calculate($steel, PrItem::SHAPE_HOLLOW, [
            'd_outer' => 100, 'd_inner' => 60, 'length' => 1000,
        ]);
        $this->assertSame(39.4688, $hollow->unitKg);
    }

    public function test_missing_invalid_and_impossible_dimensions_are_not_calculated(): void
    {
        $material = new MaterialMaster(['density_profile' => MaterialMaster::DENSITY_STEEL]);

        $this->assertSame('incomplete', $this->calculator->calculate(
            $material,
            PrItem::SHAPE_ROUND,
            ['d_outer' => 100],
        )->status);
        $this->assertSame('invalid', $this->calculator->calculate(
            $material,
            PrItem::SHAPE_ROUND,
            ['d_outer' => 0, 'length' => 1000],
        )->status);
        $this->assertSame('invalid', $this->calculator->calculate(
            $material,
            PrItem::SHAPE_HOLLOW,
            ['d_outer' => 100, 'd_inner' => 100, 'length' => 1000],
        )->status);
    }
}
