<?php

namespace Tests\Unit\Materials;

use App\Models\MaterialMaster;
use App\Models\PrItem;
use App\Services\Materials\HsCodeResolver;
use Database\Seeders\MaterialHsCodeMasterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HsCodeResolverTest extends TestCase
{
    use RefreshDatabase;

    private HsCodeResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(MaterialHsCodeMasterSeeder::class);
        $this->resolver = app(HsCodeResolver::class);
    }

    public function test_alloy_boundaries_and_same_code_overlap(): void
    {
        $cases = [
            ['Round below 10', 'Round', ['d_outer' => 9.999, 'length' => 1000], 'no_rule', null],
            ['Round at 10', 'Round', ['d_outer' => 10, 'length' => 1000], 'matched', '7228.30.10'],
            ['Round at 165', 'Round', ['d_outer' => 165, 'length' => 1000], 'matched', '7228.30.10'],
            ['Round above 165', 'Round', ['d_outer' => 165.001, 'length' => 1000], 'matched', '7228.40.10'],
            ['Flat width at 150', 'Flat', ['thickness' => 10, 'width' => 150, 'length' => 1000], 'matched', '7228.30.90'],
            ['Flat width above 150', 'Flat', ['thickness' => 10, 'width' => 150.001, 'length' => 1000], 'matched', '7226.91.10'],
            ['Flat width at 400', 'Flat', ['thickness' => 10, 'width' => 400, 'length' => 1000], 'matched', '7226.91.10'],
            ['Flat width above 400', 'Flat', ['thickness' => 10, 'width' => 400.001, 'length' => 1000], 'matched', '7226.91.90'],
            ['Flat width below 600', 'Flat', ['thickness' => 10, 'width' => 599.999, 'length' => 1000], 'matched', '7226.91.90'],
            ['Flat width at 600', 'Flat', ['thickness' => 10, 'width' => 600, 'length' => 1000], 'matched', '7225.40.90'],
            ['Flat thickness at 135', 'Flat', ['thickness' => 135, 'width' => 700, 'length' => 1000], 'matched', '7225.40.90'],
            ['Flat thickness above 135', 'Flat', ['thickness' => 135.001, 'width' => 700, 'length' => 1000], 'matched', '7228.40.90'],
            ['Hollow outer at 165', 'Hollow', ['d_outer' => 165, 'd_inner' => 140, 'length' => 1000], 'no_rule', null],
            ['Hollow inner at 135', 'Hollow', ['d_outer' => 166, 'd_inner' => 135, 'length' => 1000], 'no_rule', null],
            ['Hollow above both limits', 'Hollow', ['d_outer' => 166, 'd_inner' => 136, 'length' => 1000], 'matched', '7304.59.90'],
        ];

        foreach ($cases as [$label, $shape, $dimensions, $status, $code]) {
            $result = $this->resolve('SCM440', $shape, $dimensions);
            $this->assertSame($status, $result->status, $label);
            $this->assertSame($code, $result->hsCode, $label);
        }

        $overlap = $this->resolve('SCM440', PrItem::SHAPE_FLAT, [
            'thickness' => 10,
            'width' => 500,
            'length' => 1000,
        ]);
        $this->assertSame('matched', $overlap->status);
        $this->assertSame('7226.91.90', $overlap->hsCode);
        $this->assertCount(2, $overlap->candidates);
    }

    public function test_carbon_priority_and_numeric_boundaries(): void
    {
        $cases = [
            ['Width at 600', 'Flat', ['thickness' => 3, 'width' => 600, 'length' => 1000], 'no_rule', null],
            ['Thickness at 3', 'Flat', ['thickness' => 3, 'width' => 601, 'length' => 1000], 'matched', '7208.53.00'],
            ['Thickness below 4.75', 'Flat', ['thickness' => 4.7499, 'width' => 601, 'length' => 1000], 'matched', '7208.53.00'],
            ['Thickness at 4.75', 'Flat', ['thickness' => 4.75, 'width' => 601, 'length' => 1000], 'matched', '7208.52.00'],
            ['Thickness at 10', 'Flat', ['thickness' => 10, 'width' => 601, 'length' => 1000], 'matched', '7208.52.00'],
            ['Thickness above 10', 'Flat', ['thickness' => 10.001, 'width' => 601, 'length' => 1000], 'matched', '7208.51.00'],
            ['Specific thickness at 250', 'Flat', ['thickness' => 250, 'width' => 601, 'length' => 1000], 'matched', '7214.10.19'],
            ['Round length at 2000', 'Round', ['d_outer' => 249, 'length' => 2000], 'no_rule', null],
            ['Round below 250', 'Round', ['d_outer' => 249.999, 'length' => 2000.001], 'matched', '7214.99.92'],
            ['Round at 250', 'Round', ['d_outer' => 250, 'length' => 2000.001], 'matched', '7214.10.11'],
        ];

        foreach ($cases as [$label, $shape, $dimensions, $status, $code]) {
            $result = $this->resolve('S45C', $shape, $dimensions);
            $this->assertSame($status, $result->status, $label);
            $this->assertSame($code, $result->hsCode, $label);
        }
    }

    public function test_honed_selected_conflict_and_unresolved_statuses(): void
    {
        $atInnerBoundary = $this->resolve('ST52', PrItem::SHAPE_HOLLOW, [
            'd_outer' => 140,
            'd_inner' => 50,
            'length' => 1000,
        ]);
        $this->assertSame('no_rule', $atInnerBoundary->status);

        $selected = $this->resolve('ST52', PrItem::SHAPE_HOLLOW, [
            'd_outer' => 140,
            'd_inner' => 50.001,
            'length' => 1000,
        ]);
        $this->assertSame('matched', $selected->status);
        $this->assertSame('7304.31.90', $selected->hsCode);

        $incomplete = $this->resolve('SCM440', PrItem::SHAPE_ROUND, ['length' => 1000]);
        $this->assertSame('insufficient_data', $incomplete->status);

        $unmapped = $this->resolve('F3RV', null, []);
        $this->assertSame('unmapped_material', $unmapped->status);

        $aluminium = $this->resolve('Aluminium 7075', PrItem::SHAPE_FLAT, [
            'thickness' => 10,
            'width' => 100,
            'length' => 1000,
        ]);
        $this->assertSame('no_rule', $aluminium->status);
    }

    private function resolve(string $materialCode, ?string $shape, array $dimensions)
    {
        $material = MaterialMaster::query()->where('material_code', $materialCode)->firstOrFail();

        return $this->resolver->resolve($material, $shape, $dimensions);
    }
}
