<?php

namespace Tests\Unit\Services\Pricing;

use App\Models\AnnualExchangeRate;
use App\Models\ExchangeRate;
use App\Models\MaterialMaster;
use App\Models\Period;
use App\Models\PrItemAward;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequisition;
use App\Models\Quotation;
use App\Models\ReferencePriceRule;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Pricing\HistoricalReferencePriceService;
use Database\Seeders\ReferencePriceRulesInitialSeeder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class HistoricalReferencePriceServiceTest extends TestCase
{
    use RefreshDatabase;

    private HistoricalReferencePriceService $service;

    private User $purchasing;

    private User $supplierUser;

    private Supplier $supplier;

    private Period $period;

    private MaterialMaster $materialA;

    private MaterialMaster $materialB;

    /** @var Collection<int, ReferencePriceRule> */
    private Collection $rules;

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
        $this->rules = ReferencePriceRule::all();

        $this->materialA = MaterialMaster::create([
            'material_code' => 'SKD11',
            'normalized_code' => 'SKD11',
            'raw_category' => 'Alloy Tool Steel',
            'hs_category' => MaterialMaster::HS_CATEGORY_ALLOY,
            'density_profile' => MaterialMaster::DENSITY_STEEL,
            'manufacturer_scope' => MaterialMaster::MANUFACTURER_DAIDO,
            'is_active' => true,
            'created_by' => $this->purchasing->id,
        ]);

        $this->materialB = MaterialMaster::create([
            'material_code' => 'SKD11-IMPORT',
            'normalized_code' => 'SKD11_IMPORT',
            'raw_category' => 'Alloy Tool Steel',
            'hs_category' => MaterialMaster::HS_CATEGORY_ALLOY,
            'density_profile' => MaterialMaster::DENSITY_STEEL,
            'manufacturer_scope' => MaterialMaster::MANUFACTURER_NON_DAIDO,
            'is_active' => true,
            'created_by' => $this->purchasing->id,
        ]);

        // Annual exchange rates
        AnnualExchangeRate::create([
            'year' => 2026,
            'revision' => 1,
            'currency' => 'USD',
            'rate_to_idr' => '16000.0000',
            'created_by' => $this->purchasing->id,
        ]);

        AnnualExchangeRate::create([
            'year' => 2025,
            'revision' => 1,
            'currency' => 'USD',
            'rate_to_idr' => '15000.0000',
            'created_by' => $this->purchasing->id,
        ]);

        $this->service = app(HistoricalReferencePriceService::class);
    }

    public function test_three_pos_with_different_prices_and_weights_calculate_correct_weighted_average(): void
    {
        $rule = $this->rules->where('category_key', '1-15')->first();

        // PO 1: 10 kg @ 100 IDR/kg = 1,000 IDR
        $this->createHistoricalPo(
            material: $this->materialA,
            shape: 'Flat',
            thickness: 10,
            pricePerKg: '100.0000',
            kg: '10.0000',
            currency: 'IDR',
            createdAt: '2026-02-01 10:00:00'
        );

        // PO 2: 20 kg @ 200 IDR/kg = 4,000 IDR
        $this->createHistoricalPo(
            material: $this->materialA,
            shape: 'Flat',
            thickness: 12,
            pricePerKg: '200.0000',
            kg: '20.0000',
            currency: 'IDR',
            createdAt: '2026-03-01 10:00:00'
        );

        // PO 3: 10 kg @ 300 IDR/kg = 3,000 IDR
        $this->createHistoricalPo(
            material: $this->materialA,
            shape: 'Flat',
            thickness: 15,
            pricePerKg: '300.0000',
            kg: '10.0000',
            currency: 'IDR',
            createdAt: '2026-04-01 10:00:00'
        );

        // Total amount = 8,000 IDR. Total KG = 40 kg. Weighted average = 8,000 / 40 = 200.0000 IDR/kg.
        $result = $this->service->calculate(
            materialMasterId: $this->materialA->id,
            shape: 'Flat',
            rule: $rule,
            targetCurrency: 'IDR',
            benchmarkYear: 2026,
            historyAsOfAt: '2026-05-01 00:00:00'
        );

        $this->assertTrue($result->isMatched());
        $this->assertSame('200.0000', $result->weightedAveragePricePerKg);
        $this->assertSame('100.0000', $result->lowestPricePerKg);
        $this->assertSame('300.0000', $result->highestPricePerKg);
        $this->assertSame('300.0000', $result->lastPurchasePricePerKg);
        $this->assertSame('40.0000', $result->totalKg);
        $this->assertSame(3, $result->lineCount);
        $this->assertSame(3, $result->poCount);
        $this->assertSame(0, $result->excludedLineCount);
    }

    public function test_pos_from_different_calendar_years_are_isolated_and_not_mixed(): void
    {
        $rule = $this->rules->where('category_key', '1-15')->first();

        // 2025 PO: 10 kg @ 150 IDR
        $this->createHistoricalPo(
            material: $this->materialA,
            shape: 'Flat',
            thickness: 10,
            pricePerKg: '150.0000',
            kg: '10.0000',
            currency: 'IDR',
            createdAt: '2025-11-01 10:00:00'
        );

        // 2026 PO: 10 kg @ 250 IDR
        $this->createHistoricalPo(
            material: $this->materialA,
            shape: 'Flat',
            thickness: 10,
            pricePerKg: '250.0000',
            kg: '10.0000',
            currency: 'IDR',
            createdAt: '2026-02-01 10:00:00'
        );

        $result2026 = $this->service->calculate(
            materialMasterId: $this->materialA->id,
            shape: 'Flat',
            rule: $rule,
            targetCurrency: 'IDR',
            benchmarkYear: 2026
        );

        $this->assertTrue($result2026->isMatched());
        $this->assertSame('250.0000', $result2026->weightedAveragePricePerKg);
        $this->assertSame(1, $result2026->lineCount);

        // Check 2025 summary in yearlySummaries
        $this->assertArrayHasKey(2025, $result2026->yearlySummaries);
        $this->assertSame('150.0000', $result2026->yearlySummaries[2025]['weighted_average_price_per_kg']);
    }

    public function test_current_year_ytd_is_isolated_when_no_current_year_pos_exist(): void
    {
        $rule = $this->rules->where('category_key', '1-15')->first();

        // Only 2025 PO exists
        $this->createHistoricalPo(
            material: $this->materialA,
            shape: 'Flat',
            thickness: 10,
            pricePerKg: '150.0000',
            kg: '10.0000',
            currency: 'IDR',
            createdAt: '2025-11-01 10:00:00'
        );

        $result = $this->service->calculate(
            materialMasterId: $this->materialA->id,
            shape: 'Flat',
            rule: $rule,
            targetCurrency: 'IDR',
            benchmarkYear: 2026
        );

        $this->assertFalse($result->isMatched());
        $this->assertSame('not_available', $result->status);
        $this->assertSame('no_eligible_historical_line', $result->reasonCode);
        $this->assertNull($result->weightedAveragePricePerKg);

        // Previous year 2025 is still preserved in yearlySummaries
        $this->assertArrayHasKey(2025, $result->yearlySummaries);
        $this->assertSame('150.0000', $result->yearlySummaries[2025]['weighted_average_price_per_kg']);
    }

    public function test_different_material_master_ids_do_not_mix_even_with_same_material_name(): void
    {
        $rule = $this->rules->where('category_key', '1-15')->first();

        // PO for material B (different ID)
        $this->createHistoricalPo(
            material: $this->materialB,
            shape: 'Flat',
            thickness: 10,
            pricePerKg: '999.0000',
            kg: '10.0000',
            currency: 'IDR',
            createdAt: '2026-02-01 10:00:00'
        );

        $result = $this->service->calculate(
            materialMasterId: $this->materialA->id,
            shape: 'Flat',
            rule: $rule,
            targetCurrency: 'IDR',
            benchmarkYear: 2026
        );

        $this->assertFalse($result->isMatched());
        $this->assertSame('no_eligible_historical_line', $result->reasonCode);
    }

    public function test_different_shapes_are_excluded(): void
    {
        $rule = $this->rules->where('category_key', '1-15')->first();

        // PO for Round with outer diameter 10
        $this->createHistoricalPo(
            material: $this->materialA,
            shape: 'Round',
            thickness: null,
            dOuter: 10,
            pricePerKg: '500.0000',
            kg: '10.0000',
            currency: 'IDR',
            createdAt: '2026-02-01 10:00:00'
        );

        $result = $this->service->calculate(
            materialMasterId: $this->materialA->id,
            shape: 'Flat',
            rule: $rule,
            targetCurrency: 'IDR',
            benchmarkYear: 2026
        );

        $this->assertFalse($result->isMatched());
    }

    public function test_different_categories_do_not_enter_main_calculation(): void
    {
        $rule1_15 = $this->rules->where('category_key', '1-15')->first();
        $rule16_50 = $this->rules->where('category_key', '16-50')->first();

        // PO with thickness 30 (falls into 16-50)
        $this->createHistoricalPo(
            material: $this->materialA,
            shape: 'Flat',
            thickness: 30,
            pricePerKg: '800.0000',
            kg: '10.0000',
            currency: 'IDR',
            createdAt: '2026-02-01 10:00:00'
        );

        // Querying for 1-15 should find 0 lines
        $result = $this->service->calculate(
            materialMasterId: $this->materialA->id,
            shape: 'Flat',
            rule: $rule1_15,
            targetCurrency: 'IDR',
            benchmarkYear: 2026
        );

        $this->assertFalse($result->isMatched());

        // Querying for 16-50 should find it
        $result16 = $this->service->calculate(
            materialMasterId: $this->materialA->id,
            shape: 'Flat',
            rule: $rule16_50,
            targetCurrency: 'IDR',
            benchmarkYear: 2026
        );

        $this->assertTrue($result16->isMatched());
        $this->assertSame('800.0000', $result16->weightedAveragePricePerKg);
    }

    public function test_decimal_key_dimension_uses_ceil_to_classify_category(): void
    {
        $rule1_15 = $this->rules->where('category_key', '1-15')->first();
        $rule16_50 = $this->rules->where('category_key', '16-50')->first();

        // PO with thickness 15.2 -> CEIL is 16 -> should be in 16-50, not 1-15
        $this->createHistoricalPo(
            material: $this->materialA,
            shape: 'Flat',
            thickness: 15.2,
            pricePerKg: '450.0000',
            kg: '10.0000',
            currency: 'IDR',
            createdAt: '2026-02-01 10:00:00'
        );

        $res1 = $this->service->calculate(
            materialMasterId: $this->materialA->id,
            shape: 'Flat',
            rule: $rule1_15,
            targetCurrency: 'IDR',
            benchmarkYear: 2026
        );
        $this->assertFalse($res1->isMatched());

        $res16 = $this->service->calculate(
            materialMasterId: $this->materialA->id,
            shape: 'Flat',
            rule: $rule16_50,
            targetCurrency: 'IDR',
            benchmarkYear: 2026
        );
        $this->assertTrue($res16->isMatched());
        $this->assertSame('450.0000', $res16->weightedAveragePricePerKg);
    }

    public function test_annual_exchange_rate_converts_currencies_correctly(): void
    {
        $rule = $this->rules->where('category_key', '1-15')->first();

        // PO in USD: 10 kg @ 10 USD/kg. In 2026, 1 USD = 16,000 IDR.
        // Converted price = 160,000 IDR/kg.
        $this->createHistoricalPo(
            material: $this->materialA,
            shape: 'Flat',
            thickness: 10,
            pricePerKg: '10.0000',
            kg: '10.0000',
            currency: 'USD',
            createdAt: '2026-02-01 10:00:00'
        );

        $result = $this->service->calculate(
            materialMasterId: $this->materialA->id,
            shape: 'Flat',
            rule: $rule,
            targetCurrency: 'IDR',
            benchmarkYear: 2026
        );

        $this->assertTrue($result->isMatched());
        $this->assertSame('160000.0000', $result->weightedAveragePricePerKg);
    }

    public function test_unsupported_or_missing_exchange_rate_currencies_are_excluded_and_reported(): void
    {
        $rule = $this->rules->where('category_key', '1-15')->first();

        // PO in JPY: no rate exists in annual_exchange_rates for JPY
        $this->createHistoricalPo(
            material: $this->materialA,
            shape: 'Flat',
            thickness: 10,
            pricePerKg: '50.0000',
            kg: '15.0000',
            currency: 'JPY',
            createdAt: '2026-02-01 10:00:00'
        );

        $result = $this->service->calculate(
            materialMasterId: $this->materialA->id,
            shape: 'Flat',
            rule: $rule,
            targetCurrency: 'IDR',
            benchmarkYear: 2026
        );

        $this->assertFalse($result->isMatched());
        $this->assertSame('exchange_rate_unavailable', $result->reasonCode);
        $this->assertSame(1, $result->excludedLineCount);
        $this->assertSame('15.0000', $result->excludedTotalKg);
        $this->assertContains('JPY', $result->excludedCurrencies);
    }

    public function test_excluded_kg_line_count_and_currencies_are_tracked_accurately_with_convertible_lines(): void
    {
        $rule = $this->rules->where('category_key', '1-15')->first();

        // Valid IDR PO: 10 kg @ 100 IDR
        $this->createHistoricalPo(
            material: $this->materialA,
            shape: 'Flat',
            thickness: 10,
            pricePerKg: '100.0000',
            kg: '10.0000',
            currency: 'IDR',
            createdAt: '2026-02-01 10:00:00'
        );

        // Missing rate JPY PO: 5 kg @ 50 JPY
        $this->createHistoricalPo(
            material: $this->materialA,
            shape: 'Flat',
            thickness: 10,
            pricePerKg: '50.0000',
            kg: '5.0000',
            currency: 'JPY',
            createdAt: '2026-03-01 10:00:00'
        );

        $result = $this->service->calculate(
            materialMasterId: $this->materialA->id,
            shape: 'Flat',
            rule: $rule,
            targetCurrency: 'IDR',
            benchmarkYear: 2026
        );

        $this->assertTrue($result->isMatched());
        $this->assertSame('100.0000', $result->weightedAveragePricePerKg);
        $this->assertSame(1, $result->lineCount);
        $this->assertSame('10.0000', $result->totalKg);
        $this->assertSame(1, $result->excludedLineCount);
        $this->assertSame('5.0000', $result->excludedTotalKg);
        $this->assertContains('JPY', $result->excludedCurrencies);
    }

    public function test_last_purchase_price_tie_breaker_is_deterministic(): void
    {
        $rule = $this->rules->where('category_key', '1-15')->first();

        // Line 1: earlier date
        $this->createHistoricalPo(
            material: $this->materialA,
            shape: 'Flat',
            thickness: 10,
            pricePerKg: '100.0000',
            kg: '10.0000',
            currency: 'IDR',
            createdAt: '2026-02-01 10:00:00'
        );

        // Line 2: later date, lower price
        $this->createHistoricalPo(
            material: $this->materialA,
            shape: 'Flat',
            thickness: 10,
            pricePerKg: '200.0000',
            kg: '10.0000',
            currency: 'IDR',
            createdAt: '2026-04-01 12:00:00'
        );

        // Line 3: same later date, higher PO id, price 250
        $this->createHistoricalPo(
            material: $this->materialA,
            shape: 'Flat',
            thickness: 10,
            pricePerKg: '250.0000',
            kg: '10.0000',
            currency: 'IDR',
            createdAt: '2026-04-01 12:00:00'
        );

        $result = $this->service->calculate(
            materialMasterId: $this->materialA->id,
            shape: 'Flat',
            rule: $rule,
            targetCurrency: 'IDR',
            benchmarkYear: 2026
        );

        $this->assertSame('250.0000', $result->lastPurchasePricePerKg);
    }

    public function test_cancelled_pos_are_excluded(): void
    {
        $rule = $this->rules->where('category_key', '1-15')->first();

        $this->createHistoricalPo(
            material: $this->materialA,
            shape: 'Flat',
            thickness: 10,
            pricePerKg: '999.0000',
            kg: '10.0000',
            currency: 'IDR',
            createdAt: '2026-02-01 10:00:00',
            poStatus: 'cancelled'
        );

        $result = $this->service->calculate(
            materialMasterId: $this->materialA->id,
            shape: 'Flat',
            rule: $rule,
            targetCurrency: 'IDR',
            benchmarkYear: 2026
        );

        $this->assertFalse($result->isMatched());
    }

    public function test_exclude_po_ids_prevents_self_inclusion(): void
    {
        $rule = $this->rules->where('category_key', '1-15')->first();

        [$po, $quotation, $qi] = $this->createHistoricalPo(
            material: $this->materialA,
            shape: 'Flat',
            thickness: 10,
            pricePerKg: '150.0000',
            kg: '10.0000',
            currency: 'IDR',
            createdAt: '2026-02-01 10:00:00'
        );

        $result = $this->service->calculate(
            materialMasterId: $this->materialA->id,
            shape: 'Flat',
            rule: $rule,
            targetCurrency: 'IDR',
            benchmarkYear: 2026,
            excludePoIds: [$po->id]
        );

        $this->assertFalse($result->isMatched());
    }

    private function createHistoricalPo(
        MaterialMaster $material,
        string $shape,
        ?float $thickness = null,
        ?float $dOuter = null,
        string $pricePerKg = '100.0000',
        string $kg = '10.0000',
        string $currency = 'IDR',
        string $createdAt = '2026-02-01 10:00:00',
        string $poStatus = 'completed',
    ): array {
        $this->seq++;
        $pr = PurchaseRequisition::create([
            'period_id' => $this->period->id,
            'created_by' => $this->purchasing->id,
            'pr_number' => 'PR-TEST-'.$this->seq,
            'status' => 'completed',
        ]);

        $itemData = [
            'material_master_id' => $material->id,
            'material_name' => $material->material_code,
            'quantity' => 1,
            'shape' => $shape,
            'length' => 100,
            'weight_needed' => (float) $kg,
        ];
        if ($shape === 'Flat') {
            $itemData['thickness'] = $thickness;
            $itemData['width'] = 50;
        } elseif ($shape === 'Round') {
            $itemData['d_outer'] = $dOuter;
        }

        $prItem = $pr->items()->create($itemData);

        $rate = ExchangeRate::firstOrCreate(
            ['currency' => $currency],
            ['rate_to_idr' => 1, 'valid_from' => '2025-01-01', 'created_by' => $this->purchasing->id]
        );

        $quotation = Quotation::create([
            'pr_id' => $pr->id,
            'supplier_id' => $this->supplierUser->id,
            'exchange_rate_id' => $rate->id,
            'currency' => $currency,
            'status' => 'accepted',
            'submitted_at' => $createdAt,
        ]);

        $qiData = [
            'pr_item_id' => $prItem->id,
            'is_available' => true,
            'price_per_kg' => $pricePerKg,
            'amount' => bcmul($pricePerKg, $kg, 4),
            'offered_weight_per_unit' => (float) $kg,
            'available_qty' => 1,
        ];
        if ($shape === 'Flat' && $thickness !== null) {
            $qiData['available_thickness'] = $thickness;
        } elseif ($shape === 'Round' && $dOuter !== null) {
            $qiData['available_d_outer'] = $dOuter;
        }

        $quotationItem = $quotation->items()->create($qiData);

        $po = PurchaseOrder::create([
            'supplier_id' => $this->supplierUser->id,
            'currency' => $currency,
            'exchange_rate_id' => $rate->id,
            'po_number' => 'PO-TEST-'.$this->seq,
            'status' => $poStatus,
            'created_by' => $this->purchasing->id,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);

        DB::table('purchase_orders')->where('id', $po->id)->update([
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
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
            'awarded_at' => $createdAt,
        ])->forceFill([
            'ordered_qty' => 1,
            'ordered_total_weight_kg' => $kg,
            'ordered_price_per_kg' => $pricePerKg,
            'ordered_amount' => bcmul($pricePerKg, $kg, 4),
        ])->save();

        return [$po, $quotation, $quotationItem];
    }
}
