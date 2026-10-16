<?php

namespace Tests\Feature\Procurement;

use App\Exports\Advanced\Definitions\PurchaseOrderDefinition;
use App\Exports\PurchaseOrderDetailExport;
use App\Models\PurchaseOrder;
use App\Support\PurchaseOrderPdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Mission 04-W outputs: PO PDF logistics rows (Q7, AC-31; release gated by
 * G-PDF), PO detail export ordered values + logistics, the additive Advanced
 * Export catalog columns, and the ordered-vs-PR delta on the PO pages.
 */
class PoOrderedOutputsTest extends TestCase
{
    use ProcurementFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpProcurementActors();
        config(['procurement.ordered_quantity_write_enabled' => true, 'procurement.allow_offer_surplus' => true]);
    }

    public function test_pdf_adds_port_and_incoterms_after_currency_and_keeps_the_total(): void
    {
        $po = $this->generatedPo('TANJUNG_PERAK', 'FOB');
        $data = PurchaseOrderPdf::data($po);
        $html = view('pdf.po-pdf', ['po' => $po, 'document' => $data])->render();
        $dates = $this->between($html, 'class="dates"', '</table>');

        $this->assertSame('Tanjung Perak', $data['port_of_discharge']);
        $this->assertSame('FOB', $data['incoterm']);
        $this->assertSame('96.00', $data['total']);
        $this->assertMatchesRegularExpression('/Currency.*?USD.*?Port of Discharge.*?Tanjung Perak.*?Incoterms.*?FOB/s', $dates);
        $this->assertSame(1, substr_count($dates, 'Port of Discharge'));
    }

    public function test_pdf_shows_a_dash_for_a_legacy_po_without_logistics(): void
    {
        $data = PurchaseOrderPdf::data($this->generatedPo(null, null));

        $this->assertSame('-', $data['port_of_discharge']);
        $this->assertSame('-', $data['incoterm']);
    }

    public function test_detail_export_appends_ordered_values_and_logistics_without_shifting_columns(): void
    {
        $po = $this->generatedPo('TANJUNG_PRIOK', 'CIF');
        $export = new PurchaseOrderDetailExport($po->id);
        $headings = $export->headings();
        $row = $export->collection()->first();

        $this->assertCount(count($headings), $row);
        $this->assertSame(['Ordered Quantity', 'Ordered Weight (KG)', 'Port of Discharge', 'Incoterms'], array_slice($headings, -4));
        $this->assertSame([12, 38.4, 'Tanjung Priok', 'CIF — Cost, Insurance and Freight'], array_slice($row, -4));
        $this->assertEqualsWithDelta(96.0, $row[10], 0.0001); // amount column index unchanged
    }

    public function test_advanced_catalog_offers_logistics_as_opt_in_columns(): void
    {
        $columns = collect(PurchaseOrderDefinition::columns())->keyBy('key');

        $this->assertTrue($columns->has('port_of_discharge'));
        $this->assertTrue($columns->has('incoterm'));
        $this->assertFalse($columns['port_of_discharge']->default);
        $this->assertFalse($columns['incoterm']->default);
        $this->assertSame('Tanjung Perak', ($columns['port_of_discharge']->value)($this->generatedPo('TANJUNG_PERAK', 'FOB')));
    }

    public function test_po_pages_show_ordered_quantity_with_the_delta_against_the_pr(): void
    {
        $po = $this->generatedPo('TANJUNG_PERAK', 'FOB');

        $this->actingAs($this->purchasing)
            ->get(route('purchasing.purchase-orders.show', $po))
            ->assertOk()
            ->assertSee('PR: 10 · Δ vs PR: +2');

        $this->actingAs($this->supplier)
            ->get(route('supplier.purchase-orders.show', $po))
            ->assertOk()
            ->assertSee('PR: 10 · Δ vs PR: +2');
    }

    /**
     * PR 10, offer 15 at 3.2 kg/unit and 2.5/kg, ordered 12 (38.4 kg, 96.00).
     */
    private function generatedPo(?string $port, ?string $incoterm): PurchaseOrder
    {
        $pr = $this->createRequisition([['quantity' => 10, 'weight_needed' => 3]]);
        $pr->forceFill(['port_of_discharge' => $port, 'incoterm' => $incoterm])->save();
        $quotation = $this->createQuotation($pr, [['available_qty' => 15, 'offered_weight_per_unit' => 3.2]]);

        $this->actingAs($this->purchasing)
            ->post(route('purchasing.comparison.save-awards'), [
                'pr_id' => $pr->getRouteKey(),
                'awards' => [$pr->items[0]->id => $quotation->items[0]->id],
                'order_qty' => [$pr->items[0]->id => 12],
                'action' => 'generate_pos',
                'estimated_arrival' => now()->addDays(14)->toDateString(),
            ])
            ->assertSessionHasNoErrors();

        return PurchaseOrder::query()->latest('id')->firstOrFail();
    }

    private function between(string $html, string $from, string $to): string
    {
        $start = strpos($html, $from);
        $this->assertNotFalse($start);

        return substr($html, $start, strpos($html, $to, $start) - $start);
    }
}
