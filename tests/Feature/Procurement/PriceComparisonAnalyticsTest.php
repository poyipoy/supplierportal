<?php

namespace Tests\Feature\Procurement;

use App\Models\PurchaseOrder;
use App\Models\PurchaseRequisition;
use App\Models\Quotation;
use App\Models\User;
use App\Services\PrItemAwardService;
use App\Services\PurchaseOrderGenerationService;
use App\Support\BusinessTime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Mission 07 pages: evaluation date header, currency selection, indicator
 * strip, historical tab (supplier optional, key dimension) and vs-best on
 * benchmark rates (AC-20, AC-21, AC-23, AC-39; decisions 15-20, 29, 34, 35).
 */
class PriceComparisonAnalyticsTest extends TestCase
{
    use ProcurementFixtures;
    use RefreshDatabase;

    private const MATERIAL = 'M07 PAGE STEEL';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpProcurementActors();
        Cache::flush();
    }

    public function test_ac39_header_shows_business_today_for_two_frozen_dates_and_rate_dates(): void
    {
        foreach (['2026-11-05 23:30:00', '2026-11-20 00:30:00'] as $jakarta) {
            $this->travelTo(BusinessTime::parseDate(substr($jakarta, 0, 10))->setTimeFromTimeString(substr($jakarta, 11))->utc());
            app()->forgetScopedInstances();
            // A fresh session and user per frozen date: the session security
            // middleware correctly expires a session carried across 15 days.
            $this->flushSession();
            $viewer = User::factory()->create(['role' => 'purchasing', 'is_active' => true]);

            $html = $this->actingAs($viewer)->get(route('purchasing.comparison.vs-best'))->assertOk()->getContent();

            $this->assertStringContainsString('data-evaluation-date="'.BusinessTime::today()->toDateString().'"', $html);
            $this->assertSame(substr($jakarta, 0, 10), BusinessTime::today()->toDateString());
            $this->assertStringContainsString('(Asia/Jakarta)', $html);
            $this->assertStringContainsString('data-rate-unavailable="JPY"', $html);
            $this->travelBack();
        }
    }

    public function test_inter_supplier_indicators_switch_currency_and_report_unavailable_rates(): void
    {
        $pr = $this->openPr();

        $this->actingAs($this->purchasing)
            ->get(route('purchasing.comparison.inter-supplier', ['pr_id' => $pr->getRouteKey()]))
            ->assertOk()
            ->assertSee('data-price-indicators', false)
            ->assertSee('IDR 32000')   // best current offer: USD 2 x 16,000
            ->assertSee('USD 2 · ', false) // the original value stays visible
            ->assertSee('data-rate-unavailable="JPY"', false)
            ->assertSee(__('purchasing.comparison_analytics.unavailable_note', ['currencies' => 'JPY']));

        $this->get(route('purchasing.comparison.inter-supplier', ['pr_id' => $pr->getRouteKey(), 'currency' => 'USD']))
            ->assertOk()
            ->assertSee(__('purchasing.comparison_analytics.indicators_title', ['currency' => 'USD']));

        $this->get(route('purchasing.comparison.inter-supplier', ['pr_id' => $pr->getRouteKey(), 'currency' => 'XYZ']))
            ->assertOk()
            ->assertSee(__('purchasing.comparison_analytics.indicators_title', ['currency' => 'IDR']));
    }

    public function test_ac20_historical_payload_without_supplier_filters_on_the_key_dimension_only(): void
    {
        $this->po(100, 10, ['d_outer' => 50, 'd_inner' => 20]);
        $this->po(300, 10, ['d_outer' => 50, 'd_inner' => 30]);
        $this->po(900, 10, ['d_outer' => 70, 'd_inner' => 20]);

        $payload = $this->actingAs($this->purchasing)->getJson(route('purchasing.comparison.historical', [
            'material_name' => self::MATERIAL, 'view' => 'json', 'key_min' => 50, 'd_inner' => 20,
        ]))->assertOk()->json();

        $this->assertSame('IDR', $payload['currency']);
        $this->assertSame(['field' => 'd_outer', 'min' => '50.0000', 'max' => '50.0000'], $payload['keyDimension']);
        $this->assertSame('200.0000', $payload['summary']['weighted_average'], 'd_inner is displayed, never filtered (Hollow).');
        $this->assertSame(2, $payload['summary']['line_count']);
        $this->assertCount(2, $payload['tableData']);

        $range = $this->getJson(route('purchasing.comparison.historical', [
            'material_name' => self::MATERIAL, 'view' => 'json', 'key_min' => 50, 'key_max' => 70, 'period_view' => 'yearly', 'currency' => 'USD',
        ]))->assertOk()->json();
        $this->assertSame('USD', $range['currency']);
        $this->assertSame(3, $range['summary']['line_count']);
        // 433.3333... IDR / 16,000, rounded once at the end.
        $this->assertSame(0.0271, $range['chartData']['pricesIdr'][0]);

        $this->get(route('purchasing.comparison.historical', ['material_name' => self::MATERIAL]))
            ->assertOk()->assertSee('data-evaluation-header', false)->assertSee(__('purchasing.comparison_analytics.all_suppliers'));
    }

    public function test_vs_best_ranks_on_benchmark_rates_and_never_treats_a_missing_rate_as_one(): void
    {
        // History: an IDR PO at 30,000/kg and a JPY PO at 10/kg with no JPY rate.
        $this->po(30000, 10, [], 'Flat', 'IDR');
        $this->po(10, 10, [], 'Flat', 'JPY');
        // Current offer: USD 2/kg = 32,000 IDR.
        $pr = $this->createRequisition([['quantity' => 10, 'weight_needed' => 1]]);
        $pr->items[0]->forceFill(['material_name' => self::MATERIAL])->save();
        $this->createQuotation($pr, [['price_per_kg' => 2, 'available_qty' => 10, 'offered_weight_per_unit' => 1]]);

        $json = $this->actingAs($this->purchasing)->getJson(route('purchasing.comparison.vs-best.data', ['draw' => 1, 'start' => 0, 'length' => 25]))->assertOk()->json();

        $row = collect($json['data'])->first(fn (array $candidate) => (float) $candidate['current_price'] === 2.0);
        $this->assertNotNull($row);
        $this->assertEquals(32000, (float) $row['current_price_idr']);
        $this->assertEquals(30000, (float) $row['best_price_idr'], 'The JPY line without a rate is never the best (no COALESCE(rate, 1)).');
        $this->assertSame('IDR', $json['currency']);
    }

    private function openPr(): PurchaseRequisition
    {
        $pr = $this->createRequisition([['quantity' => 10, 'weight_needed' => 1]]);
        $pr->items[0]->forceFill(['material_name' => self::MATERIAL])->save();
        $this->createQuotation($pr, [['price_per_kg' => 2, 'available_qty' => 10, 'offered_weight_per_unit' => 1]]);
        $other = User::factory()->create(['role' => 'supplier', 'is_active' => true]);
        $jpy = $this->createQuotation($pr, [['price_per_kg' => 100, 'available_qty' => 10, 'offered_weight_per_unit' => 1]], Quotation::STATUS_SUBMITTED, $other);
        $jpy->forceFill(['currency' => 'JPY', 'exchange_rate_id' => null])->save();

        return $pr->fresh(['items']);
    }

    private function po(float $price, int $qty, array $dims, string $shape = 'Hollow', string $currency = 'IDR'): PurchaseOrder
    {
        $pr = $this->createRequisition([['quantity' => $qty, 'weight_needed' => 1]]);
        $pr->items[0]->forceFill(['material_name' => self::MATERIAL, 'shape' => $shape, ...$dims])->save();
        $quotation = $this->createQuotation($pr, [['price_per_kg' => $price, 'available_qty' => $qty, 'offered_weight_per_unit' => 1]]);
        $quotation->forceFill(['currency' => $currency, 'exchange_rate_id' => null])->save();
        if ($dims !== []) {
            $quotation->items[0]->forceFill(collect($dims)->mapWithKeys(fn ($value, $field) => ['available_'.$field => $value])->all())->save();
        }
        $award = app(PrItemAwardService::class)->awardItem($pr->items[0], $quotation->items[0], $this->purchasing);

        return app(PurchaseOrderGenerationService::class)->generateFromAwards([$award->id], $this->purchasing)->sole();
    }
}
