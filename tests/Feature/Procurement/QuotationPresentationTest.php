<?php

namespace Tests\Feature\Procurement;

use App\Exports\QuotationDetailExport;
use App\Models\PurchaseRequisition;
use App\Models\Quotation;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Mission 03 presentation contract: section order (D24), Purchasing read-only
 * detail mirroring the Supplier form (D14), PIC (D10), server totals (D06),
 * sticky tables (D09) and the Q8 "Supplier Estimate Delivery" label.
 */
class QuotationPresentationTest extends TestCase
{
    use ProcurementFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpProcurementActors();
    }

    public function test_supplier_create_renders_sections_in_the_approved_order_with_pr_logistics(): void
    {
        $pr = $this->logisticsRequisition();

        $this->actingAs($this->supplier)
            ->get(route('supplier.quotations.create', $pr))
            ->assertOk()
            ->assertSeeInOrder([
                __('supplier.copy.procurement_context'),
                __('supplier.copy.commercial_terms_and_logistics'),
                __('procurement.quotation.status_follow_up'),
                __('supplier.copy.material_commercial_offer'),
            ])
            ->assertSee('Supplier Estimate Delivery')
            ->assertSee('Tanjung Perak')
            ->assertSee('FOB — Free On Board')
            ->assertSee('id="totalOfferedQty"', false)
            ->assertSee('id="totalOfferedKg"', false)
            ->assertSee('id="totalAmount"', false)
            ->assertSee('ui-table-sticky', false)
            // Selectors relied on by the form JavaScript are preserved.
            ->assertSee('id="quotationForm"', false)
            ->assertSee('data-async-submit', false)
            ->assertSee('data-availability-field="qty"', false)
            ->assertSee('max="10"', false); // ceiling hint while surplus is off
    }

    public function test_supplier_show_uses_the_same_order_and_server_totals(): void
    {
        $pr = $this->logisticsRequisition([['quantity' => 10], ['quantity' => 4]]);
        $quotation = $this->createQuotation($pr, [['available_qty' => 8], []]);

        $response = $this->actingAs($this->supplier)
            ->get(route('supplier.quotations.show', $quotation))
            ->assertOk()
            ->assertSeeInOrder([
                'data-quotation-section="context"',
                'data-quotation-section="terms"',
                'data-quotation-section="status"',
                'data-quotation-section="detail"',
            ], false)
            ->assertSee('Supplier Estimate Delivery')
            ->assertSee('Shortage −2');

        $html = $response->getContent();
        // Requested 10 + 4; offered 8 + 4; KG = qty × 3 kg/unit.
        $this->assertMatchesRegularExpression('/data-total="requested-qty">14</', $html);
        $this->assertMatchesRegularExpression('/data-total="offered-qty">12</', $html);
        $this->assertMatchesRegularExpression('/data-total="offered-kg">36</', $html);
    }

    public function test_purchasing_detail_is_read_only_mirrors_supplier_order_and_shows_pic(): void
    {
        Supplier::create([
            'user_id' => $this->supplier->id,
            'company_name' => 'PT Baja PIC',
            'pic_name' => 'Rina Hartono',
            'pic_phone' => '+62 812-3456-7890',
            'pic_email' => 'rina@example.test',
        ]);
        $pr = $this->logisticsRequisition();
        $quotation = $this->createQuotation($pr, [['available_qty' => 15]]);

        $html = $this->actingAs($this->purchasing)
            ->get(route('purchasing.quotations.show', $quotation))
            ->assertOk()
            ->assertSeeInOrder([
                'data-quotation-section="context"',
                'data-quotation-section="terms"',
                'data-quotation-section="status"',
                'data-quotation-section="detail"',
            ], false)
            ->assertSee('Rina Hartono')
            ->assertSee('href="tel:+6281234567890"', false)
            ->assertSee('href="mailto:rina@example.test"', false)
            ->assertSee('Supplier Estimate Delivery')
            ->assertSee('Surplus +5')
            ->getContent();

        $detail = $this->between($html, 'data-quotation-section="detail"', '</table>');
        $this->assertDoesNotMatchRegularExpression('/<(input|select|textarea|form)\b/i', $detail);
        $this->assertStringContainsString('ui-table-sticky', $detail);
        $this->assertStringContainsString('role="region"', $detail);
    }

    public function test_pic_falls_back_to_a_dash_without_a_supplier_profile(): void
    {
        $quotation = $this->createQuotation($this->logisticsRequisition());

        $html = $this->actingAs($this->purchasing)
            ->get(route('purchasing.quotations.show', $quotation))
            ->assertOk()
            ->getContent();

        $pic = $this->between($html, 'data-supplier-pic', '</dl>');
        $this->assertSame(3, substr_count($pic, '–'));
    }

    public function test_purchasing_detail_query_count_does_not_grow_with_items(): void
    {
        $small = $this->createQuotation($this->logisticsRequisition([[]]));
        $large = $this->createQuotation(
            $this->logisticsRequisition([[], [], [], [], []]),
            [],
            Quotation::STATUS_SUBMITTED,
            User::factory()->create(['role' => 'supplier', 'is_active' => true])
        );

        $this->actingAs($this->purchasing);
        $count = function (Quotation $quotation): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->get(route('purchasing.quotations.show', $quotation))->assertOk();
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $queries;
        };

        $count($small); // warm per-process caches (exchange rate, preferences)
        $this->assertSame($count($small), $count($large));
    }

    public function test_q8_label_is_exact_in_both_locales_and_estimated_ready_labels_are_unchanged(): void
    {
        $quotation = $this->createQuotation($this->logisticsRequisition());
        $this->purchasing->preference()->create([...config('user_preferences.defaults'), 'locale' => 'id']);

        $this->actingAs($this->purchasing)
            ->get(route('purchasing.quotations.show', $quotation))
            ->assertOk()
            ->assertSee('Estimasi Pengiriman Supplier')
            ->assertDontSee('Perkiraan Tanggal Siap / Kirim Supplier');

        foreach (['supplier.copy.supplier_estimate_delivery', 'purchasing.copy.supplier_estimate_delivery', 'exports.closure.estimated_delivery'] as $key) {
            $this->assertSame('Supplier Estimate Delivery', __($key, [], 'en'));
            $this->assertSame('Estimasi Pengiriman Supplier', __($key, [], 'id'));
        }
        $this->assertSame('PO Target Arrival Date is earlier than the selected Supplier Estimate Delivery. Review the target before generating the PO.', __('purchasing.copy.po_target_earlier_than_supplier_estimate_delivery', [], 'en'));
        $this->assertSame('Tanggal target kedatangan PO lebih awal dari Estimasi Pengiriman Supplier terpilih. Tinjau target sebelum membuat PO.', __('purchasing.copy.po_target_earlier_than_supplier_estimate_delivery', [], 'id'));

        // Progress "Estimated Ready" is a different field and keeps its labels.
        $this->assertSame('Current Estimated Ready', __('purchasing.copy.current_estimated_ready', [], 'en'));
        $this->assertSame('Estimated Ready:', __('supplier.copy.estimated_ready', [], 'en'));

        app()->setLocale('en');
        $this->assertContains('Supplier Estimate Delivery', (new QuotationDetailExport($quotation->id))->headings());
        app()->setLocale('id');
        $this->assertContains('Estimasi Pengiriman Supplier', (new QuotationDetailExport($quotation->id))->headings());
        app()->setLocale('en');
    }

    public function test_comparison_header_shows_pic_and_the_q8_label(): void
    {
        Supplier::create(['user_id' => $this->supplier->id, 'company_name' => 'PT Baja PIC', 'pic_name' => 'Rina Hartono']);
        $pr = $this->logisticsRequisition();
        $this->createQuotation($pr);

        $this->actingAs($this->purchasing)
            ->get(route('purchasing.comparison.inter-supplier', ['pr_id' => $pr]))
            ->assertOk()
            ->assertSee('Rina Hartono')
            ->assertSee('Supplier Estimate Delivery:');
    }

    private function logisticsRequisition(array $items = [[]]): PurchaseRequisition
    {
        $pr = $this->createRequisition($items);
        $pr->forceFill(['port_of_discharge' => 'TANJUNG_PERAK', 'incoterm' => 'FOB'])->save();
        $pr->invitedSuppliers()->syncWithoutDetaching([$this->supplier->id]);

        return $pr->fresh(['items']);
    }

    private function between(string $html, string $from, string $to): string
    {
        $start = strpos($html, $from);
        $this->assertNotFalse($start, "Marker {$from} not found");
        $end = strpos($html, $to, $start);

        return substr($html, $start, $end === false ? null : $end - $start);
    }
}
