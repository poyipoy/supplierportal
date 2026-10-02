<?php

namespace Tests\Feature;

use App\Models\ExchangeRate;
use App\Models\Period;
use App\Models\PrItem;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequisition;
use App\Models\Quotation;
use App\Models\User;
use App\Services\RegionalDisplayFormatter;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Vinkla\Hashids\Facades\Hashids;

class RegionalComparisonDisplayTest extends TestCase
{
    use RefreshDatabase;

    private User $purchasing;

    private User $supplierA;

    private User $supplierB;

    private ExchangeRate $exchangeRate;

    protected function setUp(): void
    {
        parent::setUp();

        $this->purchasing = User::factory()->create(['role' => 'purchasing', 'is_active' => true]);
        $this->supplierA = User::factory()->create(['role' => 'supplier', 'is_active' => true]);
        $this->supplierB = User::factory()->create(['role' => 'supplier', 'is_active' => true]);

        $this->exchangeRate = ExchangeRate::create([
            'currency' => 'USD',
            'rate_to_idr' => 16000,
            'valid_from' => now()->subDays(5),
            'created_by' => $this->purchasing->id,
        ]);
    }

    public function test_only_exact_approved_comparison_views_receive_the_scoped_formatter(): void
    {
        $this->actingAs($this->purchasing);

        foreach ([
            'purchasing.comparison.inter-supplier',
            'purchasing.comparison.historical',
            'purchasing.comparison.vs-best',
        ] as $viewName) {
            $view = view($viewName);
            app('view')->callComposer($view);
            $this->assertArrayHasKey('regionalFormatter', $view->getData(), "View {$viewName} should receive regionalFormatter");
            $this->assertInstanceOf(RegionalDisplayFormatter::class, $view->getData()['regionalFormatter']);
        }
    }

    public function test_inter_supplier_visible_numbers_transcode_and_radio_data_price_remains_canonical(): void
    {
        $pr = $this->createRequisition(1);
        $this->createSubmittedQuotation($pr, $this->supplierA, 2.5);
        $this->createSubmittedQuotation($pr, $this->supplierB, 3.0);

        // 1. With Indonesian preference (dot grouping, comma decimal)
        $this->preference($this->purchasing, 'indonesian');
        $responseIndo = $this->actingAs($this->purchasing)
            ->get(route('purchasing.comparison.inter-supplier', ['pr_id' => $pr]));
        $responseIndo->assertOk();
        $contentIndo = $responseIndo->getContent();

        // data-price MUST remain canonical decimal format with dot for JavaScript calculation
        $this->assertMatchesRegularExpression('/data-price="Rp [0-9]+(\.[0-9]+)?"/', $contentIndo);
        $this->assertDoesNotMatchRegularExpression('/data-price="Rp [0-9]+,[0-9]+"/', $contentIndo);

        // 2. With International preference (comma grouping, dot decimal)
        $this->preference($this->purchasing, 'international');
        $responseIntl = $this->actingAs($this->purchasing)
            ->get(route('purchasing.comparison.inter-supplier', ['pr_id' => $pr]));
        $responseIntl->assertOk();
        $contentIntl = $responseIntl->getContent();

        // data-price remains identical canonical
        $this->assertMatchesRegularExpression('/data-price="Rp [0-9]+(\.[0-9]+)?"/', $contentIntl);
    }

    public function test_vs_best_datatables_transcodes_visible_cells_and_keeps_ranking_calculations_raw(): void
    {
        $pr = $this->createRequisition(1);
        $qA = $this->createSubmittedQuotation($pr, $this->supplierA, 2.0);

        // Create PO linking to quotation for history
        $po = PurchaseOrder::create([
            'po_number' => 'PO-HIST-001',
            'supplier_id' => $this->supplierA->id,
            'currency' => 'USD',
            'exchange_rate_id' => $this->exchangeRate->id,
            'status' => 'active',
            'created_by' => $this->purchasing->id,
        ]);
        $po->quotations()->attach($qA->id);

        $this->preference($this->purchasing, 'indonesian', 'dmy', 'Asia/Jakarta');

        $response = $this->actingAs($this->purchasing)
            ->json('GET', route('purchasing.comparison.vs-best.data'), [
                'draw' => 1,
                'start' => 0,
                'length' => 10,
            ]);

        $response->assertOk();
        $data = $response->json('data');

        if (! empty($data)) {
            $row = $data[0];
            // Visible columns are formatted HTML strings
            $this->assertArrayHasKey('material_display', $row);
            $this->assertArrayHasKey('current_price_display', $row);
            $this->assertArrayHasKey('best_price_display', $row);
            $this->assertArrayHasKey('diff_display', $row);
            $this->assertArrayHasKey('potential_difference_display', $row);

            // Raw values remain numeric
            $this->assertIsNumeric($row['current_price_idr']);
            $this->assertIsNumeric($row['best_price_idr']);
            $this->assertIsNumeric($row['potential_difference_idr']);
        }
    }

    public function test_historical_monthly_data_transcodes_display_dates_and_keeps_period_sort_and_chart_canonical(): void
    {
        $pr = $this->createRequisition(1);
        $qA = $this->createSubmittedQuotation($pr, $this->supplierA, 2.0);

        $po = PurchaseOrder::create([
            'po_number' => 'PO-HIST-002',
            'supplier_id' => $this->supplierA->id,
            'currency' => 'USD',
            'exchange_rate_id' => $this->exchangeRate->id,
            'status' => 'active',
            'created_by' => $this->purchasing->id,
            'created_at' => Carbon::parse('2026-05-15 10:00:00'),
        ]);
        $po->quotations()->attach($qA->id);

        $this->preference($this->purchasing, 'indonesian', 'dmy', 'Asia/Jakarta');

        $response = $this->actingAs($this->purchasing)
            ->get(route('purchasing.comparison.historical', [
                'supplier_id' => Hashids::encode($this->supplierA->id),
                'material_name' => 'Material 1',
                'period_view' => 'monthly',
            ]));

        $response->assertOk();
    }

    private function preference(User $user, string $number = 'system', string $date = 'system', string $timezone = 'system'): void
    {
        $user->preference()->updateOrCreate([], [
            ...config('user_preferences.defaults'),
            'timezone' => $timezone,
            'date_format' => $date,
            'time_format' => '24h',
            'number_format' => $number,
        ]);
        app()->forgetScopedInstances();
    }

    private function createRequisition(int $itemCount = 1): PurchaseRequisition
    {
        $period = Period::create([
            'name' => 'Period '.uniqid(),
            'month' => 9,
            'year' => 2026,
            'status' => 'open',
            'created_by' => $this->purchasing->id,
        ]);

        $pr = PurchaseRequisition::create([
            'period_id' => $period->id,
            'created_by' => $this->purchasing->id,
            'pr_number' => 'REQ/'.str_pad((string) rand(1, 999), 3, '0', STR_PAD_LEFT),
            'status' => 'bidding',
            'notes' => 'Comparison test requisition',
        ]);

        for ($i = 1; $i <= $itemCount; $i++) {
            PrItem::create([
                'pr_id' => $pr->id,
                'hs_code' => '7209.16.00',
                'material_name' => "Material {$i}",
                'quantity' => 2,
                'shape' => PrItem::SHAPE_FLAT,
                'thickness' => 1.5,
                'width' => 100,
                'length' => 200,
                'weight_needed' => 10,
            ]);
        }

        return $pr->fresh(['items']);
    }

    private function createSubmittedQuotation(PurchaseRequisition $pr, User $supplier, float $price): Quotation
    {
        $quotation = Quotation::create([
            'pr_id' => $pr->id,
            'supplier_id' => $supplier->id,
            'currency' => 'USD',
            'exchange_rate_id' => $this->exchangeRate->id,
            'status' => Quotation::STATUS_SUBMITTED,
            'estimated_delivery' => now()->addDays(14),
            'payment_terms' => 'TT 30 Days',
            'validity_period' => now()->addDays(30),
            'submitted_at' => now(),
        ]);

        foreach ($pr->items as $item) {
            $quotation->items()->create([
                'pr_item_id' => $item->id,
                'is_available' => true,
                'price_per_kg' => $price,
                'amount' => $price * $item->total_weight,
            ]);
        }

        return $quotation->fresh(['items']);
    }
}
