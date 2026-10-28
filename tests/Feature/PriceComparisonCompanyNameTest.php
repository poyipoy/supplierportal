<?php

namespace Tests\Feature;

use App\Models\ExchangeRate;
use App\Models\Period;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequisition;
use App\Models\Quotation;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Gate 2 PUR-03: Price Comparison identifies suppliers by company name (login name only as a
 * fallback) and shows no contact person, on every tab and in the Vs Best keyword search.
 */
class PriceComparisonCompanyNameTest extends TestCase
{
    use RefreshDatabase;

    private User $purchasing;

    private User $named;

    private User $blank;

    private PurchaseRequisition $currentPr;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-28 10:00:00');
        $this->purchasing = User::factory()->create(['role' => 'purchasing']);
        $period = Period::create(['name' => 'Oct', 'month' => 10, 'year' => 2026, 'status' => 'open', 'created_by' => $this->purchasing->id]);
        $rate = ExchangeRate::create(['currency' => 'USD', 'rate_to_idr' => 16000, 'valid_from' => '2025-01-01', 'created_by' => $this->purchasing->id]);

        $this->named = User::factory()->create(['role' => 'supplier', 'name' => 'login-alpha']);
        Supplier::create(['user_id' => $this->named->id, 'company_name' => 'PT Alpha Baja', 'pic_name' => 'Contact Person Alpha']);
        $this->blank = User::factory()->create(['role' => 'supplier', 'name' => 'login-beta']);
        Supplier::create(['user_id' => $this->blank->id, 'company_name' => '   ']);

        // History: a completed legacy PO from the named supplier.
        $historyPr = PurchaseRequisition::create(['period_id' => $period->id, 'created_by' => $this->purchasing->id, 'pr_number' => 'REQ/HIST/1', 'status' => 'completed']);
        $historyItem = $historyPr->items()->create(['material_name' => 'SCM440', 'quantity' => 2, 'shape' => 'Flat', 'thickness' => 10, 'width' => 100, 'length' => 200, 'weight_needed' => 5]);
        $historyQuote = Quotation::create(['pr_id' => $historyPr->id, 'supplier_id' => $this->named->id, 'exchange_rate_id' => $rate->id, 'currency' => 'USD', 'status' => 'accepted', 'submitted_at' => '2026-01-10 09:00:00']);
        $historyQuote->items()->create(['pr_item_id' => $historyItem->id, 'is_available' => true, 'price_per_kg' => 8, 'amount' => 80]);
        $po = PurchaseOrder::create(['supplier_id' => $this->named->id, 'currency' => 'USD', 'exchange_rate_id' => $rate->id, 'po_number' => 'PO/HIST/1', 'status' => 'completed', 'created_by' => $this->purchasing->id]);
        $po->quotations()->attach($historyQuote->id);

        // Current: both suppliers quote the same material.
        $this->currentPr = PurchaseRequisition::create(['period_id' => $period->id, 'created_by' => $this->purchasing->id, 'pr_number' => 'REQ/CUR/1', 'status' => 'bidding']);
        $item = $this->currentPr->items()->create(['material_name' => 'SCM440', 'quantity' => 2, 'shape' => 'Flat', 'thickness' => 10, 'width' => 100, 'length' => 200, 'weight_needed' => 5]);
        foreach ([[$this->named, 10], [$this->blank, 11]] as [$supplier, $price]) {
            $quote = Quotation::create(['pr_id' => $this->currentPr->id, 'supplier_id' => $supplier->id, 'exchange_rate_id' => $rate->id, 'currency' => 'USD', 'status' => 'submitted', 'submitted_at' => '2026-10-01 09:00:00']);
            $quote->items()->create(['pr_item_id' => $item->id, 'is_available' => true, 'price_per_kg' => $price, 'amount' => $price * 10]);
        }
    }

    public function test_inter_supplier_header_chart_and_indicators_use_the_company_name(): void
    {
        $response = $this->actingAs($this->purchasing)
            ->get(route('purchasing.comparison.inter-supplier', ['pr_id' => $this->currentPr]))
            ->assertOk();

        $response->assertSee('PT Alpha Baja')->assertSee('login-beta')
            ->assertDontSee('login-alpha')->assertDontSee('Contact Person Alpha');
        $chart = $response->viewData('chartData');
        $this->assertSame(['PT Alpha Baja', 'login-beta'], array_column($chart['datasets'], 'label'));
        $this->assertSame('PT Alpha Baja', $response->viewData('comparison')['matrix'][0]['indicators']['best_current']['supplier']);
    }

    public function test_historical_supplier_select_and_table_use_the_company_name(): void
    {
        $this->actingAs($this->purchasing);
        $page = $this->get(route('purchasing.comparison.historical', ['material_name' => 'SCM440']))->assertOk();
        $page->assertSee('PT Alpha Baja')->assertDontSee('login-alpha');

        $json = $this->getJson(route('purchasing.comparison.historical', ['material_name' => 'SCM440', 'view' => 'json', 'supplier_id' => $this->named->getRouteKey()]))->assertOk();
        $this->assertSame('PT Alpha Baja', $json->json('supplierName'));
        $this->assertSame(['PT Alpha Baja'], array_values(array_unique(array_column($json->json('tableData'), 'supplier'))));
    }

    public function test_vs_best_cells_and_keyword_search_use_the_company_name(): void
    {
        $this->actingAs($this->purchasing);
        $data = $this->vsBest('');
        $current = collect($data->json('data'))->pluck('current_price_display')->implode(' ');
        $best = collect($data->json('data'))->pluck('best_price_display')->implode(' ');
        $this->assertStringContainsString('PT Alpha Baja', $current);
        $this->assertStringContainsString('login-beta', $current, 'A blank company name falls back to the login name.');
        $this->assertStringContainsString('PT Alpha Baja', $best);
        $this->assertStringNotContainsString('login-alpha', $current.$best);

        // The accepted history quotation is also a current row; Alpha is the best supplier on every row.
        $this->assertSame($data->json('recordsFiltered'), $this->vsBest('Alpha Baja')->json('recordsFiltered'), 'Search matches the best supplier company on every row.');
        $this->assertSame(1, $this->vsBest('login-beta')->json('recordsFiltered'));
        $this->assertSame(0, $this->vsBest('login-alpha')->json('recordsFiltered'), 'The hidden login name is not searchable.');
    }

    private function vsBest(string $search)
    {
        return $this->withHeader('X-Requested-With', 'XMLHttpRequest')
            ->getJson(route('purchasing.comparison.vs-best.data').'?'.http_build_query(['draw' => 1, 'start' => 0, 'length' => 25, 'search' => ['value' => $search, 'regex' => 'false']]))
            ->assertOk();
    }
}
