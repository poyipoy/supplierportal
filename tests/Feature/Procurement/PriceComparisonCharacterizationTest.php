<?php

namespace Tests\Feature\Procurement;

use App\Models\ExchangeRate;
use App\Models\User;
use App\Services\PrItemAwardService;
use App\Services\PurchaseOrderGenerationService;
use App\Support\BusinessTime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Gate 2 (Historical comparison) characterization, contracts I-A, I-B and I-I / AC4:
 * the legacy Historical JSON (no pr_id), the Vs Best data feed and the Award Selection
 * form inputs must not change while PR-mode quotation history is added.
 */
class PriceComparisonCharacterizationTest extends TestCase
{
    use ProcurementFixtures;
    use RefreshDatabase;

    private const MATERIAL = 'G2 CHAR VSBEST STEEL';

    private User $secondSupplier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpProcurementActors();
        $this->secondSupplier = User::factory()->create(['role' => 'supplier', 'is_active' => true]);
        ExchangeRate::create(['currency' => 'USD', 'rate_to_idr' => 16000, 'valid_from' => '2026-01-01', 'created_by' => $this->purchasing->id]);
        $this->travelTo(BusinessTime::parseDate('2026-10-20')->setTime(10, 0)->utc());
    }

    public function test_legacy_historical_json_contract_is_unchanged(): void
    {
        $this->historyPo(50000, 100);

        $payload = $this->actingAs($this->purchasing)
            ->getJson(route('purchasing.comparison.historical', ['view' => 'json', 'material_name' => self::MATERIAL, 'shape' => 'Flat']))
            ->assertOk()
            ->json();

        $this->assertSame([
            'chartData', 'tableData', 'summary', 'pagination', 'periodView', 'range', 'rangeOptions',
            'materialName', 'shape', 'keyOptions', 'supplierName', 'currency', 'evaluation', 'keyDimension',
        ], array_keys($payload));
        $this->assertArrayNotHasKey('quotationHistory', $payload);
        $this->assertSame('50000.0000', $payload['summary']['weighted_average']);
        $this->assertSame(1, $payload['summary']['line_count']);
        $this->assertSame([50000], $payload['chartData']['pricesIdr']);
        $this->assertSame('IDR', $payload['currency']);
    }

    public function test_vs_best_data_feed_is_unchanged(): void
    {
        $this->historyPo(50000, 100);
        $pr = $this->createRequisition([['quantity' => 10, 'weight_needed' => 2]]);
        $pr->items[0]->forceFill(['material_name' => self::MATERIAL])->save();
        $current = $this->createQuotation($pr, [['price_per_kg' => 55000]]);
        $current->forceFill(['currency' => 'IDR', 'exchange_rate_id' => null])->save();

        $response = $this->actingAs($this->purchasing)
            ->getJson(route('purchasing.comparison.vs-best.data').'?'.http_build_query([
                'draw' => 1, 'start' => 0, 'length' => 50, 'currency' => 'IDR',
                'material_name' => self::MATERIAL, 'shape' => 'Flat',
                'search' => ['value' => '', 'regex' => 'false'],
            ]))
            ->assertOk();

        $rows = collect($response->json('data'))->keyBy(fn (array $row) => 'p'.(int) $row['current_price']);
        $this->assertSame(['p55000', 'p50000'], $rows->keys()->all(), 'The current offer and the awarded history quotation are both current rows.');

        $offer = $rows['p55000'];
        $this->assertEqualsWithDelta(50000, (float) $offer['best_price'], 0.0001);
        $this->assertEqualsWithDelta(5000, (float) $offer['diff_idr_per_kg'], 0.0001);
        $this->assertEqualsWithDelta(10, (float) $offer['diff_percent'], 0.0001);
        // GREATEST(0, 5,000) x (2 kg x 10) = 100,000.
        $this->assertEqualsWithDelta(100000, (float) $offer['potential_difference_idr'], 0.0001);
        $this->assertEqualsWithDelta(0, (float) $rows['p50000']['diff_percent'], 0.0001);

        $this->assertSame([
            'total_rows' => 2,
            'competitive_count' => 1,
            'above_count' => 1,
            'total_potential_difference_idr' => 100000,
            'average_diff_idr_per_kg' => 2500,
        ], $response->json('summary'));
    }

    public function test_award_selection_form_inputs_are_unchanged(): void
    {
        $pr = $this->createRequisition([['quantity' => 10], ['quantity' => 5]]);
        $this->createQuotation($pr);
        $this->createQuotation($pr, supplier: $this->secondSupplier);

        $html = $this->actingAs($this->purchasing)
            ->get(route('purchasing.comparison.inter-supplier', ['pr_id' => $pr->getRouteKey()]))
            ->assertOk()
            ->getContent();

        $document = new \DOMDocument;
        libxml_use_internal_errors(true);
        $document->loadHTML($html);
        libxml_clear_errors();
        $xpath = new \DOMXPath($document);
        $this->assertSame(1, $xpath->query('//form[@id="itemAwardForm"]')->length);

        $names = collect(iterator_to_array($xpath->query('//form[@id="itemAwardForm"]//*[@name]')))
            ->map(fn (\DOMElement $field) => preg_replace('/\d+/', '{id}', $field->getAttribute('name')))
            ->unique()->sort()->values()->all();
        $this->assertSame(self::AWARD_FORM_INPUTS, $names);

        $radios = $xpath->query('//form[@id="itemAwardForm"]//input[contains(concat(" ", normalize-space(@class), " "), " award-radio ")]');
        $this->assertSame(4, $radios->length, 'Two PR items x two selectable offers.');
        $attributes = collect(iterator_to_array($radios->item(0)->attributes))
            ->keys()->filter(fn (string $name) => str_starts_with($name, 'data-'))->sort()->values()->all();
        $this->assertSame(self::AWARD_RADIO_DATA_ATTRIBUTES, $attributes);
    }

    private const AWARD_FORM_INPUTS = ['_token', 'action', 'awards[{id}]', 'estimated_arrival', 'notes', 'pr_id'];

    private const AWARD_RADIO_DATA_ATTRIBUTES = ['data-pr-item-id', 'data-pr-item-name', 'data-price', 'data-price-display', 'data-supplier-estimated-ready', 'data-supplier-id', 'data-supplier-name'];

    private function historyPo(float $price, int $kg): void
    {
        $this->travelTo(BusinessTime::parseDate('2026-09-10')->setTime(10, 0)->utc());
        $pr = $this->createRequisition([['quantity' => $kg, 'weight_needed' => 1]]);
        $pr->items[0]->forceFill(['material_name' => self::MATERIAL])->save();
        $quotation = $this->createQuotation($pr, [['price_per_kg' => $price, 'available_qty' => $kg, 'offered_weight_per_unit' => 1]]);
        $quotation->forceFill(['currency' => 'IDR', 'exchange_rate_id' => null])->save();
        $awards = app(PrItemAwardService::class)->awardBatch($pr, [$pr->items[0]->id => $quotation->items[0]->id], $this->purchasing);
        app(PurchaseOrderGenerationService::class)->generateFromAwards($awards->pluck('id')->all(), $this->purchasing);
        $this->travelTo(BusinessTime::parseDate('2026-10-20')->setTime(10, 0)->utc());
        app()->forgetScopedInstances();
    }
}
