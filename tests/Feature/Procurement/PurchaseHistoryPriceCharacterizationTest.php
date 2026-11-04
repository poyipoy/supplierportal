<?php

namespace Tests\Feature\Procurement;

use App\Models\ExchangeRate;
use App\Models\PurchaseOrder;
use App\Services\Pricing\PurchaseHistoryPriceService;
use App\Services\PrItemAwardService;
use App\Services\PurchaseOrderGenerationService;
use App\Support\BusinessTime;
use App\Support\Pricing\EvaluationContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Gate 2 (Historical comparison) characterization, contract I-A / AC4: the PO-based
 * purchase history must stay byte-for-byte equivalent while quotation history is
 * added beside it. Expected values are hand-calculated for a fixed fixture:
 *
 *   PO1 2026-08-15  IDR 50,000/kg x 100 kg
 *   PO2 2026-09-10  USD 4/kg      x 300 kg  (benchmark 16,000 -> 64,000 IDR/kg)
 *   PO3 2026-09-20  IDR 70,000/kg x 100 kg
 *   PO4 2026-10-05  JPY 500/kg    x  50 kg  (no JPY benchmark -> excluded)
 *
 * Total (IDR) = (5,000,000 + 19,200,000 + 7,000,000) / 500 = 62,400.
 */
class PurchaseHistoryPriceCharacterizationTest extends TestCase
{
    use ProcurementFixtures;
    use RefreshDatabase;

    private const MATERIAL = 'G2 CHARACTERIZATION STEEL';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpProcurementActors();
        ExchangeRate::create(['currency' => 'USD', 'rate_to_idr' => 16000, 'valid_from' => '2026-01-01', 'created_by' => $this->purchasing->id]);

        $this->poAt('2026-08-15 10:00', 'IDR', 50000, 100);
        $this->poAt('2026-09-10 10:00', 'USD', 4, 300);
        $this->poAt('2026-09-20 10:00', 'IDR', 70000, 100);
        $this->poAt('2026-10-05 10:00', 'JPY', 500, 50);

        $this->travelTo(BusinessTime::parseDate('2026-10-20')->setTime(10, 0)->utc());
        app()->forgetScopedInstances();
    }

    public function test_total_summary_is_unchanged(): void
    {
        $summary = $this->service()->summarize(['material_name' => self::MATERIAL], 'IDR', EvaluationContext::current());

        $this->assertSame('IDR', $summary['currency']);
        $this->assertSame('2026-10-20', $summary['evaluation_date']);
        $this->assertSame([
            'weighted_average' => '62400.0000',
            'best' => '50000.0000',
            'highest' => '70000.0000',
            'line_count' => 3,
            'po_count' => 3,
            'total_kg' => '500.0000',
            'excluded' => ['lines' => 1, 'kg' => '50.0000', 'currencies' => ['JPY']],
        ], $summary['total']);
        $this->assertSame(['rate' => '16000.0000', 'valid_from' => '2026-01-01'], $summary['rates']['USD']);
        $this->assertSame(['rate' => null, 'valid_from' => null], $summary['rates']['JPY']);
        $this->assertSame('1.0000', $summary['rates']['IDR']['rate']);
    }

    public function test_monthly_and_yearly_periods_are_unchanged(): void
    {
        $monthly = $this->service()->summarize(['material_name' => self::MATERIAL], 'IDR', EvaluationContext::current(), 'month')['periods'];

        $this->assertSame(['2026-08', '2026-09', '2026-10'], array_column($monthly, 'period'));
        $this->assertSame(['50000.0000', '65500.0000', null], array_column($monthly, 'weighted_average'));
        $this->assertSame(['50000.0000', '64000.0000', null], array_column($monthly, 'best'));
        $this->assertSame(['50000.0000', '70000.0000', null], array_column($monthly, 'highest'));
        $this->assertSame([1, 2, 0], array_column($monthly, 'line_count'));
        $this->assertSame(['100.0000', '400.0000', '0.0000'], array_column($monthly, 'total_kg'));
        $this->assertSame(['lines' => 1, 'kg' => '50.0000', 'currencies' => ['JPY']], $monthly[2]['excluded']);

        $yearly = $this->service()->summarize(['material_name' => self::MATERIAL], 'IDR', EvaluationContext::current(), 'year')['periods'];
        $this->assertSame(['2026'], array_column($yearly, 'period'));
        $this->assertSame('62400.0000', $yearly[0]['weighted_average']);
    }

    public function test_lines_and_change_percent_are_unchanged(): void
    {
        $lines = $this->service()->lines(['material_name' => self::MATERIAL], 'IDR', EvaluationContext::current());

        $this->assertSame(4, $lines['total']);
        $rows = $lines['rows'];
        $this->assertSame(['IDR', 'USD', 'IDR', 'JPY'], array_column($rows, 'currency'));
        $this->assertSame(['50000.0000', '4.0000', '70000.0000', '500.0000'], array_column($rows, 'price_per_kg'));
        $this->assertSame(['100.0000', '300.0000', '100.0000', '50.0000'], array_column($rows, 'kg'));
        $this->assertSame(['50000.0000', '64000.0000', '70000.0000', null], array_column($rows, 'converted_price_per_kg'));
        $this->assertSame(['5000000.0000', '19200000.0000', '7000000.0000', null], array_column($rows, 'converted_total'));
        // (64,000 - 50,000) / 50,000 = 28%; (70,000 - 64,000) / 64,000 = 9.375% -> 9.38.
        $this->assertSame([null, 28.0, 9.38, null], array_column($rows, 'change_pct'));
    }

    public function test_page_boundary_keeps_the_previous_line_for_change_percent(): void
    {
        $page2 = $this->service()->lines(['material_name' => self::MATERIAL], 'IDR', EvaluationContext::current(), 2, 2)['rows'];

        $this->assertSame(['70000.0000', '500.0000'], array_column($page2, 'price_per_kg'));
        $this->assertSame([9.38, null], array_column($page2, 'change_pct'));
    }

    private function service(): PurchaseHistoryPriceService
    {
        return app(PurchaseHistoryPriceService::class);
    }

    private function poAt(string $jakarta, string $currency, float $price, int $kg): PurchaseOrder
    {
        $this->travelTo(BusinessTime::parseDate(substr($jakarta, 0, 10))->setTimeFromTimeString(substr($jakarta, 11))->utc());

        $pr = $this->createRequisition([['quantity' => $kg, 'weight_needed' => 1]]);
        $pr->items[0]->forceFill(['material_name' => self::MATERIAL])->save();
        $quotation = $this->createQuotation($pr, [['price_per_kg' => $price, 'available_qty' => $kg, 'offered_weight_per_unit' => 1]]);
        if ($currency !== 'USD') {
            $quotation->forceFill(['currency' => $currency, 'exchange_rate_id' => null])->save();
        }
        $awards = app(PrItemAwardService::class)->awardBatch($pr, [$pr->items[0]->id => $quotation->items[0]->id], $this->purchasing);
        $po = app(PurchaseOrderGenerationService::class)->generateFromAwards($awards->pluck('id')->all(), $this->purchasing)->sole();
        $this->travelBack();

        return $po;
    }
}
