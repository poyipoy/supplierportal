<?php

namespace Tests\Feature\Procurement;

use App\Models\ExchangeRate;
use App\Models\PurchaseOrder;
use App\Models\Quotation;
use App\Services\Pricing\PurchaseHistoryPriceService;
use App\Services\PrItemAwardService;
use App\Services\PurchaseOrderGenerationService;
use App\Support\BusinessTime;
use App\Support\Pricing\EvaluationContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Mission 07 (plan section 12.3): weighted purchase history across
 * currencies at the single evaluation date (AC-19..24, AC-38, AC-39, AC-44).
 */
class PurchaseHistoryPriceTest extends TestCase
{
    use ProcurementFixtures;
    use RefreshDatabase;

    private const MATERIAL = 'M07 HISTORY STEEL';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpProcurementActors();
    }

    public function test_ac19_weighted_average_uses_ordered_kg(): void
    {
        $this->po('IDR', 50000, 100);
        $this->po('IDR', 70000, 300);

        $total = $this->summary('IDR')['total'];

        $this->assertSame('65000.0000', $total['weighted_average']);
        $this->assertSame('50000.0000', $total['best']);
        $this->assertSame(2, $total['line_count']);
        $this->assertSame(2, $total['po_count']);
        $this->assertSame('400.0000', $total['total_kg']);
    }

    public function test_ac38_multi_currency_weighting_is_exact_and_missing_rates_are_reported(): void
    {
        $this->po('IDR', 50000, 100);
        $this->po('USD', 4, 300);
        $this->po('JPY', 500, 50);

        $summary = $this->summary('IDR');

        // (5,000,000 + 300 x 4 x 16,000) / 400 = 60,500; JPY has no rate.
        $this->assertSame('60500.0000', $summary['total']['weighted_average']);
        $this->assertSame(['lines' => 1, 'kg' => '50.0000', 'currencies' => ['JPY']], $summary['total']['excluded']);
        $this->assertNull($summary['rates']['JPY']['rate']);
        $this->assertSame('1.0000', $summary['rates']['IDR']['rate']);

        // Per-line computation gives the same value.
        $lines = app(PurchaseHistoryPriceService::class)->lines(['material_name' => self::MATERIAL], 'IDR', EvaluationContext::current())['rows'];
        $available = collect($lines)->whereNotNull('converted_total');
        $this->assertSame('60500.0000', bcdiv((string) $available->reduce(fn ($carry, $row) => bcadd($carry, $row['converted_total'], 8), '0'), (string) $available->reduce(fn ($carry, $row) => bcadd($carry, $row['kg'], 8), '0'), 4));
        $this->assertNull(collect($lines)->firstWhere('currency', 'JPY')['converted_price_per_kg']);
    }

    public function test_ac21_target_currency_conversion_keeps_original_values(): void
    {
        ExchangeRate::create(['currency' => 'JPY', 'rate_to_idr' => 100, 'valid_from' => now()->subDays(2)->toDateString(), 'created_by' => $this->purchasing->id]);
        $this->po('USD', 4, 100);
        $this->po('JPY', 640, 100);

        $usd = $this->summary('USD')['total'];
        // 4 USD and 640 JPY = 64,000 IDR = 4 USD.
        $this->assertSame('4.0000', $usd['weighted_average']);
        $lines = app(PurchaseHistoryPriceService::class)->lines(['material_name' => self::MATERIAL], 'IDR', EvaluationContext::current())['rows'];
        $this->assertSame(['USD', 'JPY'], array_column($lines, 'currency'));
        $this->assertSame(['4.0000', '640.0000'], array_column($lines, 'price_per_kg'));
        $this->assertSame(['64000.0000', '64000.0000'], array_column($lines, 'converted_price_per_kg'));
    }

    public function test_ac22_a_future_dated_benchmark_is_never_used(): void
    {
        $this->po('USD', 4, 100);
        ExchangeRate::create(['currency' => 'USD', 'rate_to_idr' => 20000, 'valid_from' => BusinessTime::today()->addDay()->toDateString(), 'created_by' => $this->purchasing->id]);

        $this->assertSame('64000.0000', $this->summary('IDR')['total']['weighted_average']);
    }

    public function test_ac23_and_ac24_new_benchmark_never_changes_document_snapshots(): void
    {
        $po = $this->po('USD', 4, 100);
        $snapshotTotal = PurchaseOrder::query()->whereKey($po->id)->withResolvedTotalIdr()->value('resolved_total_idr');

        ExchangeRate::create(['currency' => 'USD', 'rate_to_idr' => 17000, 'valid_from' => BusinessTime::today()->toDateString(), 'created_by' => $this->purchasing->id]);
        app()->forgetScopedInstances();

        $this->assertSame('68000.0000', $this->summary('IDR')['total']['weighted_average'], 'Analytics follow the evaluation-date benchmark.');
        $this->assertEquals($snapshotTotal, PurchaseOrder::query()->whereKey($po->id)->withResolvedTotalIdr()->value('resolved_total_idr'), 'Snapshot totals never move.');
        $this->assertEquals(6400000, (float) $snapshotTotal);
    }

    public function test_ac20_hollow_filters_on_outer_diameter_only_and_ranges_are_inclusive(): void
    {
        $this->po('IDR', 100, 10, shape: 'Hollow', dims: ['d_outer' => 50, 'd_inner' => 20]);
        $this->po('IDR', 200, 10, shape: 'Hollow', dims: ['d_outer' => 50, 'd_inner' => 30]);
        $this->po('IDR', 300, 10, shape: 'Hollow', dims: ['d_outer' => 60, 'd_inner' => 20]);

        $exact = $this->summary('IDR', ['shape' => 'Hollow', 'key_min' => 50, 'key_max' => 50])['total'];
        $this->assertSame(2, $exact['line_count']);
        $range = $this->summary('IDR', ['shape' => 'Hollow', 'key_min' => 50, 'key_max' => 60])['total'];
        $this->assertSame(3, $range['line_count'], 'Both bounds are inclusive.');
        $this->assertSame(0, $this->summary('IDR', ['shape' => 'Hollow', 'key_min' => 50.0001, 'key_max' => 59.9999])['total']['line_count']);
    }

    public function test_only_po_lines_count_and_cancelled_deleted_or_unawarded_lines_never_do(): void
    {
        $kept = $this->po('IDR', 1000, 10);
        $cancelled = $this->po('IDR', 2000, 10);
        $cancelled->forceFill(['status' => 'cancelled'])->save();
        $deleted = $this->po('IDR', 3000, 10);
        $deleted->delete();

        // A submitted quotation without a PO never enters history.
        $pr = $this->createRequisition([['quantity' => 10, 'weight_needed' => 1]]);
        $pr->items[0]->forceFill(['material_name' => self::MATERIAL])->save();
        $this->createQuotation($pr, [['price_per_kg' => 1, 'available_qty' => 10, 'offered_weight_per_unit' => 1]]);

        // A second, unawarded line of an awarded quotation is excluded.
        $twoLines = $this->createRequisition([['quantity' => 10, 'weight_needed' => 1], ['quantity' => 10, 'weight_needed' => 1]]);
        $twoLines->items->each(fn ($item) => $item->forceFill(['material_name' => self::MATERIAL])->save());
        $quotation = $this->createQuotation($twoLines, [['price_per_kg' => 4000, 'available_qty' => 10, 'offered_weight_per_unit' => 1], ['price_per_kg' => 9, 'available_qty' => 10, 'offered_weight_per_unit' => 1]]);
        $quotation->forceFill(['currency' => 'IDR', 'exchange_rate_id' => null])->save();
        $this->generate($twoLines, $quotation, [$twoLines->items[0]->id => $quotation->items[0]->id]);

        $total = $this->summary('IDR')['total'];
        $this->assertSame(2, $total['line_count']);
        $this->assertSame('2500.0000', $total['weighted_average']);
        $this->assertSame(1, DB::table('po_quotations')->where('po_id', $kept->id)->count(), 'One pivot row per quotation (no double counting).');
    }

    public function test_frozen_ordered_kg_is_used_and_the_write_flag_does_not_change_analytics(): void
    {
        $this->po('IDR', 200, 6); // legacy-path PO, created before the write path is enabled
        config(['procurement.ordered_quantity_write_enabled' => true, 'procurement.allow_offer_surplus' => true]);
        $pr = $this->createRequisition([['quantity' => 10, 'weight_needed' => 1]]);
        $pr->items[0]->forceFill(['material_name' => self::MATERIAL])->save();
        $quotation = $this->createQuotation($pr, [['price_per_kg' => 100, 'available_qty' => 15, 'offered_weight_per_unit' => 2]]);
        $quotation->forceFill(['currency' => 'IDR', 'exchange_rate_id' => null])->save();
        app(PrItemAwardService::class)->awardBatch($pr, [$pr->items[0]->id => $quotation->items[0]->id], $this->purchasing, [$pr->items[0]->id => ['order_qty' => 12]]);
        app(PurchaseOrderGenerationService::class)->generateFromAwards($pr->fresh()->awards->pluck('id')->all(), $this->purchasing);
        $on = $this->summary('IDR')['total'];
        $this->assertSame('30.0000', $on['total_kg'], 'Frozen 12 x 2 kg plus 6 kg, not the offered 30 kg.');
        config(['procurement.ordered_quantity_write_enabled' => false, 'procurement.allow_offer_surplus' => false]);
        $this->assertSame($on, $this->summary('IDR')['total']);
    }

    public function test_ac39_one_evaluation_date_per_request_for_two_frozen_dates(): void
    {
        $this->po('USD', 4, 100);
        foreach (['2026-11-03' => 18000, '2026-11-10' => 19000] as $date => $rate) {
            ExchangeRate::create(['currency' => 'USD', 'rate_to_idr' => $rate, 'valid_from' => $date, 'created_by' => $this->purchasing->id]);
        }

        foreach (['2026-11-05 23:59:00' => '72000.0000', '2026-11-12 08:00:00' => '76000.0000'] as $jakarta => $expected) {
            $this->travelTo(BusinessTime::parseDate(substr($jakarta, 0, 10))->setTimeFromTimeString(substr($jakarta, 11))->utc());
            app()->forgetScopedInstances();
            $context = EvaluationContext::current();
            $this->assertSame(BusinessTime::today()->toDateString(), $context->dateString());

            // The clock crosses midnight mid-request: the context does not move.
            $this->travel(2)->minutes();
            $summary = app(PurchaseHistoryPriceService::class)->summarize(['material_name' => self::MATERIAL], 'IDR', $context);
            $this->assertSame($expected, $summary['total']['weighted_average']);
            $this->assertSame(substr($jakarta, 0, 10), $summary['evaluation_date']);
            $this->travelBack();
        }
    }

    private function summary(string $currency, array $filter = []): array
    {
        return app(PurchaseHistoryPriceService::class)->summarize(['material_name' => self::MATERIAL, ...$filter], $currency, EvaluationContext::current());
    }

    /**
     * One PO line of $qty units at 1 kg/unit (so KG = qty) and $price per KG.
     */
    private function po(string $currency, float $price, int $qty, string $shape = 'Flat', array $dims = []): PurchaseOrder
    {
        $pr = $this->createRequisition([['quantity' => $qty, 'weight_needed' => 1]]);
        $pr->items[0]->forceFill(['material_name' => self::MATERIAL, 'shape' => $shape, ...$dims])->save();
        $quotation = $this->createQuotation($pr, [['price_per_kg' => $price, 'available_qty' => $qty, 'offered_weight_per_unit' => 1]]);
        if ($currency !== 'USD') {
            $quotation->forceFill(['currency' => $currency, 'exchange_rate_id' => null])->save();
        }
        if ($dims !== []) {
            $quotation->items[0]->forceFill(collect($dims)->mapWithKeys(fn ($value, $field) => ['available_'.$field => $value])->all())->save();
        }

        return $this->generate($pr, $quotation, [$pr->items[0]->id => $quotation->items[0]->id]);
    }

    private function generate($pr, Quotation $quotation, array $selections): PurchaseOrder
    {
        $awards = app(PrItemAwardService::class)->awardBatch($pr, $selections, $this->purchasing);

        return app(PurchaseOrderGenerationService::class)->generateFromAwards($awards->pluck('id')->all(), $this->purchasing)->sole();
    }
}
