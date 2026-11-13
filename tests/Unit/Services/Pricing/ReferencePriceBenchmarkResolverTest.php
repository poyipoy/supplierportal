<?php

namespace Tests\Unit\Services\Pricing;

use App\Models\ExchangeRate;
use App\Models\MaterialMaster;
use App\Models\Period;
use App\Models\PrItemAward;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequisition;
use App\Models\Quotation;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Pricing\ReferencePriceBenchmarkResolver;
use Database\Seeders\ReferencePriceRulesInitialSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReferencePriceBenchmarkResolverTest extends TestCase
{
    use RefreshDatabase;

    private ReferencePriceBenchmarkResolver $resolver;

    private User $purchasing;

    private User $supplierUser;

    private Supplier $supplier;

    private Period $period;

    private MaterialMaster $material;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->purchasing = User::factory()->create(['role' => 'purchasing']);
        $this->supplierUser = User::factory()->create(['role' => 'supplier']);
        $this->supplier = Supplier::create([
            'user_id' => $this->supplierUser->id,
            'company_name' => 'PT Test Supplier',
        ]);
        $this->period = Period::create([
            'name' => 'Testing Period',
            'month' => 1,
            'year' => 2026,
            'status' => 'open',
            'created_by' => $this->purchasing->id,
        ]);

        $this->seed(ReferencePriceRulesInitialSeeder::class);

        $this->material = MaterialMaster::create([
            'material_code' => 'SKD11',
            'normalized_code' => 'SKD11',
            'raw_category' => 'Alloy Tool Steel',
            'hs_category' => MaterialMaster::HS_CATEGORY_ALLOY,
            'density_profile' => MaterialMaster::DENSITY_STEEL,
            'manufacturer_scope' => MaterialMaster::MANUFACTURER_DAIDO,
            'is_active' => true,
            'created_by' => $this->purchasing->id,
        ]);

        $this->resolver = app(ReferencePriceBenchmarkResolver::class);
    }

    public function test_main_benchmark_is_resolved_from_pr_item_requested_dimension(): void
    {
        // Historical PO for category 1-15 (thickness 10) at 200 IDR/kg
        $this->createHistoricalPo(10, '200.0000', '10.0000');

        $pr = PurchaseRequisition::create([
            'period_id' => $this->period->id,
            'created_by' => $this->purchasing->id,
            'pr_number' => 'PR-LIVE-1',
            'status' => 'bidding',
        ]);
        $prItem = $pr->items()->create([
            'material_master_id' => $this->material->id,
            'material_name' => $this->material->material_code,
            'quantity' => 1,
            'shape' => 'Flat',
            'thickness' => 10,
            'width' => 50,
            'length' => 100,
            'weight_needed' => 10,
        ]);

        $result = $this->resolver->resolve($prItem);

        $this->assertTrue($result->isMainMatched());
        $this->assertSame('200.0000', $result->main->weightedAveragePricePerKg);
        $this->assertFalse($result->hasAlternative);
        $this->assertNull($result->supplierAlternative);
    }

    public function test_alternative_benchmark_is_resolved_when_supplier_offered_dimension_is_in_different_category(): void
    {
        // Historical PO for 1-15 at 200 IDR/kg
        $this->createHistoricalPo(10, '200.0000', '10.0000');
        // Historical PO for 16-50 at 250 IDR/kg
        $this->createHistoricalPo(25, '250.0000', '10.0000');

        $pr = PurchaseRequisition::create([
            'period_id' => $this->period->id,
            'created_by' => $this->purchasing->id,
            'pr_number' => 'PR-LIVE-2',
            'status' => 'bidding',
        ]);
        $prItem = $pr->items()->create([
            'material_master_id' => $this->material->id,
            'material_name' => $this->material->material_code,
            'quantity' => 1,
            'shape' => 'Flat',
            'thickness' => 10, // Category 1-15
            'width' => 50,
            'length' => 100,
            'weight_needed' => 10,
        ]);

        $rate = ExchangeRate::firstOrCreate(
            ['currency' => 'IDR'],
            ['rate_to_idr' => 1, 'valid_from' => '2025-01-01', 'created_by' => $this->purchasing->id]
        );

        $quotation = Quotation::create([
            'pr_id' => $pr->id,
            'supplier_id' => $this->supplierUser->id,
            'exchange_rate_id' => $rate->id,
            'currency' => 'IDR',
            'status' => 'submitted',
        ]);
        $qi = $quotation->items()->create([
            'pr_item_id' => $prItem->id,
            'is_available' => true,
            'available_thickness' => 25, // Category 16-50!
            'price_per_kg' => '280.0000',
            'amount' => '2800.0000',
            'available_qty' => 1,
            'offered_weight_per_unit' => 10,
        ]);

        $result = $this->resolver->resolve($prItem, $qi);

        $this->assertTrue($result->isMainMatched());
        $this->assertSame('200.0000', $result->main->weightedAveragePricePerKg);
        $this->assertSame('1-15', $result->main->categoryKey);

        $this->assertTrue($result->hasAlternative);
        $this->assertNotNull($result->supplierAlternative);
        $this->assertTrue($result->isAlternativeMatched());
        $this->assertSame('250.0000', $result->supplierAlternative->weightedAveragePricePerKg);
        $this->assertSame('16-50', $result->supplierAlternative->categoryKey);

        // Deltas against offered price 280.0000:
        // Main delta: 280 - 200 = 80.0000 (40.00%)
        $this->assertSame('80.0000', $result->mainDelta);
        $this->assertEqualsWithDelta(40.0, $result->mainDeltaPercent, 0.01);

        // Alternative delta: 280 - 250 = 30.0000 (12.00%)
        $this->assertSame('30.0000', $result->alternativeDelta);
        $this->assertEqualsWithDelta(12.0, $result->alternativeDeltaPercent, 0.01);
    }

    public function test_no_alternative_benchmark_when_supplier_offered_dimension_is_in_same_category(): void
    {
        $this->createHistoricalPo(10, '200.0000', '10.0000');

        $pr = PurchaseRequisition::create([
            'period_id' => $this->period->id,
            'created_by' => $this->purchasing->id,
            'pr_number' => 'PR-LIVE-3',
            'status' => 'bidding',
        ]);
        $prItem = $pr->items()->create([
            'material_master_id' => $this->material->id,
            'material_name' => $this->material->material_code,
            'quantity' => 1,
            'shape' => 'Flat',
            'thickness' => 10, // Category 1-15
            'width' => 50,
            'length' => 100,
            'weight_needed' => 10,
        ]);

        $rate = ExchangeRate::firstOrCreate(
            ['currency' => 'IDR'],
            ['rate_to_idr' => 1, 'valid_from' => '2025-01-01', 'created_by' => $this->purchasing->id]
        );

        $quotation = Quotation::create([
            'pr_id' => $pr->id,
            'supplier_id' => $this->supplierUser->id,
            'exchange_rate_id' => $rate->id,
            'currency' => 'IDR',
            'status' => 'submitted',
        ]);
        $qi = $quotation->items()->create([
            'pr_item_id' => $prItem->id,
            'is_available' => true,
            'available_thickness' => 12, // Still in 1-15!
            'price_per_kg' => '210.0000',
            'amount' => '2100.0000',
            'available_qty' => 1,
            'offered_weight_per_unit' => 10,
        ]);

        $result = $this->resolver->resolve($prItem, $qi);

        $this->assertTrue($result->isMainMatched());
        $this->assertSame('200.0000', $result->main->weightedAveragePricePerKg);
        $this->assertFalse($result->hasAlternative);
        $this->assertNull($result->supplierAlternative);

        $this->assertSame('10.0000', $result->mainDelta);
        $this->assertEqualsWithDelta(5.0, $result->mainDeltaPercent, 0.01);
    }

    private function createHistoricalPo(float $thickness, string $pricePerKg, string $kg): void
    {
        $this->seq++;
        $pr = PurchaseRequisition::create([
            'period_id' => $this->period->id,
            'created_by' => $this->purchasing->id,
            'pr_number' => 'PR-BENCH-'.$this->seq,
            'status' => 'completed',
        ]);

        $prItem = $pr->items()->create([
            'material_master_id' => $this->material->id,
            'material_name' => $this->material->material_code,
            'quantity' => 1,
            'shape' => 'Flat',
            'thickness' => $thickness,
            'width' => 50,
            'length' => 100,
            'weight_needed' => (float) $kg,
        ]);

        $rate = ExchangeRate::firstOrCreate(
            ['currency' => 'IDR'],
            ['rate_to_idr' => 1, 'valid_from' => '2025-01-01', 'created_by' => $this->purchasing->id]
        );

        $quotation = Quotation::create([
            'pr_id' => $pr->id,
            'supplier_id' => $this->supplierUser->id,
            'exchange_rate_id' => $rate->id,
            'currency' => 'IDR',
            'status' => 'accepted',
            'submitted_at' => '2026-02-01 10:00:00',
        ]);

        $quotationItem = $quotation->items()->create([
            'pr_item_id' => $prItem->id,
            'is_available' => true,
            'available_thickness' => $thickness,
            'price_per_kg' => $pricePerKg,
            'amount' => bcmul($pricePerKg, $kg, 4),
            'offered_weight_per_unit' => (float) $kg,
            'available_qty' => 1,
        ]);

        $po = PurchaseOrder::create([
            'supplier_id' => $this->supplierUser->id,
            'currency' => 'IDR',
            'exchange_rate_id' => $rate->id,
            'po_number' => 'PO-BENCH-'.$this->seq,
            'status' => 'completed',
            'created_by' => $this->purchasing->id,
            'created_at' => '2026-02-01 10:00:00',
            'updated_at' => '2026-02-01 10:00:00',
        ]);

        $po->quotations()->attach($quotation->id);

        PrItemAward::create([
            'pr_id' => $pr->id,
            'pr_item_id' => $prItem->id,
            'quotation_id' => $quotation->id,
            'quotation_item_id' => $quotationItem->id,
            'supplier_id' => $this->supplierUser->id,
            'purchase_order_id' => $po->id,
            'awarded_by' => $this->purchasing->id,
            'awarded_at' => '2026-02-01 10:00:00',
        ])->forceFill([
            'ordered_qty' => 1,
            'ordered_total_weight_kg' => $kg,
            'ordered_price_per_kg' => $pricePerKg,
            'ordered_amount' => bcmul($pricePerKg, $kg, 4),
        ])->save();
    }
}
