<?php

namespace Tests\Feature;

use App\Models\PurchaseOrder;
use App\Models\QcInspection;
use App\Models\User;
use App\Support\StatusHelper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Chart text alternatives (docs/audits/CHART-A11Y-BASELINE-20261102.md): every dashboard chart is a
 * <figure> with a named canvas, a summary/hint description, and a data table built from the same
 * payload the chart draws.
 */
class ChartAccessibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_qc_trend_covers_six_continuous_business_months_and_counts_by_business_month(): void
    {
        $this->travelTo('2026-10-15 03:00:00');
        $qc = User::factory()->create(['role' => 'qc']);
        $po = $this->purchaseOrder();
        // 2026-08-31 18:00 UTC is 2026-09-01 01:00 in Asia/Jakarta: a September inspection.
        QcInspection::create(['po_id' => $po->id, 'inspected_by' => $qc->id, 'status' => 'ng', 'inspected_at' => '2026-08-31 18:00:00']);
        QcInspection::create(['po_id' => $po->id, 'inspected_by' => $qc->id, 'status' => 'ok', 'inspected_at' => '2026-10-01 05:00:00']);

        $response = $this->actingAs($qc)->get(route('qc.dashboard'))->assertOk();
        $trend = $response->viewData('trendData');

        $this->assertSame(['May 2026', 'Jun 2026', 'Jul 2026', 'Aug 2026', 'Sep 2026', 'Oct 2026'], array_column($trend, 'label'));
        $this->assertSame([0, 0, 0, 0, 1, 0], array_column($trend, 'ng'), 'empty months are 0, never skipped; the UTC-August inspection is September in business time');
        $this->assertSame([0, 0, 0, 0, 0, 1], array_column($trend, 'ok'));
    }

    public function test_qc_dashboard_charts_have_text_equivalents_from_the_chart_payload(): void
    {
        $this->travelTo('2026-10-15 03:00:00');
        $qc = User::factory()->create(['role' => 'qc']);
        $po = $this->purchaseOrder();
        foreach (['ok', 'ok', 'ok', 'ng'] as $status) {
            QcInspection::create(['po_id' => $po->id, 'inspected_by' => $qc->id, 'status' => $status, 'inspected_at' => '2026-10-02 03:00:00']);
        }

        $html = $this->actingAs($qc)->get(route('qc.dashboard'))->assertOk()->getContent();

        $this->assertStringNotContainsString('id="qualityChart"', $html, 'two values are a stat, not a two-slice donut');
        $this->assertStringContainsString('75%', $html, 'pass rate is written as text');
        $this->assertStringContainsString('3 of 4 inspections passed QC.', $html);
        $this->assertChartFrame($html, 'trendChart');
        $this->assertMatchesRegularExpression('/<details[^>]*>\s*<summary[^>]*>Show data as a table<\/summary>.*Oct 2026.*<\/details>/s', $html);
        $this->assertSame(1, substr_count($html, 'data-adasi-chart-i18n'), 'chart copy is delivered once per page');
    }

    public function test_qc_trend_without_inspections_shows_an_empty_state_instead_of_a_zero_chart(): void
    {
        $qc = User::factory()->create(['role' => 'qc']);

        $html = $this->actingAs($qc)->get(route('qc.dashboard'))->assertOk()->getContent();

        $this->assertStringNotContainsString('id="trendChart"', $html);
        $this->assertStringContainsString(__('qc.copy.no_inspection_records_available'), $html);
    }

    public function test_purchasing_charts_list_every_value_in_a_table_and_keep_po_statuses_apart(): void
    {
        $purchasing = User::factory()->create(['role' => 'purchasing']);
        $this->purchaseOrder('overdue', 'PO/10/2026/001');
        $this->purchaseOrder('claim_needed', 'PO/10/2026/002');
        $this->purchaseOrder('claim_needed', 'PO/10/2026/003');

        $response = $this->actingAs($purchasing)->get(route('purchasing.dashboard'))->assertOk();
        $html = $response->getContent();

        $this->assertChartFrame($html, 'prChart');
        $this->assertChartFrame($html, 'poStatusChart');
        foreach ($response->viewData('prPerBulan') as $month) {
            $this->assertMatchesRegularExpression('/<th scope="row"[^>]*>\s*'.preg_quote($month['label'], '/').'\s*<\/th>\s*<td[^>]*>\s*'.$month['count'].'\s*<\/td>/', $html);
        }
        $this->assertMatchesRegularExpression('/'.preg_quote(StatusHelper::poLabel('claim_needed'), '/').'\s*<\/th>\s*<td[^>]*>\s*2\s*<\/td>\s*<td[^>]*>\s*67%/', $html);
        $this->assertMatchesRegularExpression('/'.preg_quote(StatusHelper::poLabel('overdue'), '/').'\s*<\/th>\s*<td[^>]*>\s*1\s*<\/td>\s*<td[^>]*>\s*33%/', $html);
        $this->assertStringNotContainsString('id="poDonut"', $html);
    }

    public function test_finance_forecast_chart_is_described_and_points_to_the_period_table(): void
    {
        $finance = User::factory()->create(['role' => 'finance']);

        $html = $this->actingAs($finance)->get(route('finance.dashboard'))->assertOk()->getContent();

        $this->assertChartFrame($html, 'paymentForecastChart');
        $this->assertStringNotContainsString('#0D9488', $html, 'series colors come from tokens');
    }

    private function assertChartFrame(string $html, string $id): void
    {
        $this->assertMatchesRegularExpression(
            '/<figure[^>]*data-adasi-chart[^>]*>.*?<canvas id="'.$id.'" role="img" tabindex="0" aria-label="[^"]+" aria-describedby="[^"]*'.$id.'-summary '.$id.'-hint"/s',
            $html,
            $id.' is a named, described, focusable canvas inside a figure',
        );
        $this->assertStringContainsString('id="'.$id.'-hint"', $html);
        $this->assertStringContainsString('data-adasi-chart-live', $html);
    }

    private function purchaseOrder(string $status = 'active', string $number = 'PO/10/2026/900'): PurchaseOrder
    {
        $supplier = User::factory()->create(['role' => 'supplier']);
        $purchasing = User::factory()->create(['role' => 'purchasing']);

        return PurchaseOrder::create(['supplier_id' => $supplier->id, 'created_by' => $purchasing->id, 'po_number' => $number, 'currency' => 'IDR', 'status' => $status]);
    }
}
