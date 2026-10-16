<?php

namespace Tests\Feature\Procurement;

use App\Models\MaterialMaster;
use App\Models\PrItem;
use App\Services\Pricing\MaterialReferencePriceService;
use App\Services\Pricing\ReferencePriceResolver;
use App\Support\Pricing\EvaluationContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AC-16 (versions resolve by evaluation date) and AC-17 (the band containing
 * the OFFERED key dimension is used, not the PR dimension).
 */
class ReferencePriceVersionResolutionTest extends TestCase
{
    use ProcurementFixtures;
    use RefreshDatabase;

    public function test_ac16_september_resolves_v1_and_october_resolves_v2(): void
    {
        $this->setUpProcurementActors();
        $material = $this->material();
        $service = app(MaterialReferencePriceService::class);
        $v1 = $service->create($this->price($material, ['price_per_kg' => 2.0, 'valid_from' => '2026-01-01']), $this->purchasing);
        $v2 = $service->supersede($v1, ['price_per_kg' => 2.4, 'valid_from' => '2026-10-01'], $this->purchasing);
        $line = $this->offer($material, PrItem::SHAPE_FLAT, ['thickness' => 2], ['available_thickness' => 2.5]);

        $september = app(ReferencePriceResolver::class)->resolve($line, new EvaluationContext(CarbonImmutable::parse('2026-09-15')), 'USD');
        $october = app(ReferencePriceResolver::class)->resolve($line, new EvaluationContext(CarbonImmutable::parse('2026-10-15')), 'USD');

        $this->assertSame($v1->id, $september->reference?->id);
        $this->assertSame('2.0000', $september->convertedReferencePricePerKg);
        $this->assertSame($v2->id, $october->reference?->id);
        $this->assertSame('2.4000', $october->convertedReferencePricePerKg);
        $this->assertSame('2026-09-30', $v1->fresh()->valid_until->toDateString(), 'Both versions are kept.');
    }

    public function test_ac17_the_band_containing_the_offered_155_is_used(): void
    {
        $this->setUpProcurementActors();
        $material = $this->material();
        $service = app(MaterialReferencePriceService::class);
        $service->create($this->price($material, ['shape' => 'Round', 'dimension_min' => 100, 'dimension_max' => 149, 'price_per_kg' => 3.0]), $this->purchasing);
        $band = $service->create($this->price($material, ['shape' => 'Round', 'dimension_min' => 150, 'dimension_max' => 200, 'price_per_kg' => 3.5]), $this->purchasing);
        $line = $this->offer($material, PrItem::SHAPE_ROUND, ['d_outer' => 100], ['available_d_outer' => 155]);

        $result = app(ReferencePriceResolver::class)->resolve($line, EvaluationContext::current(), 'USD');

        $this->assertSame($band->id, $result->reference?->id);
        $this->assertSame('d_outer', $result->dimensionField);
        $this->assertSame('155.0000', $result->dimensionValue);
    }

    private function material(): MaterialMaster
    {
        return MaterialMaster::create([
            'material_code' => 'AC16-17', 'normalized_code' => 'AC16-17', 'raw_category' => 'Fixture',
            'hs_category' => 'alloy_steel', 'density_profile' => 'steel', 'manufacturer_scope' => 'unknown', 'is_active' => true,
        ]);
    }

    private function price(MaterialMaster $material, array $overrides): array
    {
        return [
            'material_master_id' => $material->id, 'shape' => 'Flat', 'dimension_min' => 1, 'dimension_max' => 5,
            'currency' => 'USD', 'price_per_kg' => 2.0, 'valid_from' => '2026-01-01', 'valid_until' => null,
            ...$overrides,
        ];
    }

    private function offer(MaterialMaster $material, string $shape, array $prDims, array $offered)
    {
        $pr = $this->createRequisition([['quantity' => 10]]);
        $pr->items[0]->forceFill(['material_master_id' => $material->id, 'shape' => $shape, ...$prDims])->save();
        $quotation = $this->createQuotation($pr);
        $quotation->items[0]->forceFill($offered)->save();

        return $quotation->fresh(['items.prItem'])->items[0];
    }
}
