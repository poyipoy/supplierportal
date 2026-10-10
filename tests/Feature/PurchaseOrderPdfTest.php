<?php

namespace Tests\Feature;

use App\Models\PrItemAward;
use App\Support\PurchaseOrderPdf;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Tests\TestCase;

class PurchaseOrderPdfTest extends TestCase
{
    public function test_fixed_english_form_preserves_commercial_values_and_does_not_change_locale(): void
    {
        $make = require base_path('tests/Support/purchase-order-pdf-fixture.php');
        $po = $make();
        $item = $po->quotations->first()->items->first();
        $item->forceFill(['available_qty' => 3, 'offered_weight_per_unit' => 1.2345, 'price_per_kg' => 7.1234, 'amount' => 100.0001]);
        $po->created_at = Carbon::parse('2026-09-15T23:30:00Z');
        app()->setLocale('id');
        $data = PurchaseOrderPdf::data($po);
        $html = view('pdf.po-pdf', ['po' => $po, 'document' => $data])->render();
        $this->assertSame('id', app()->getLocale());
        $this->assertSame('16 Sep 2026', $data['date']);
        $this->assertSame('100.00', $data['total']);
        $this->assertSame(['3.00 pcs'], $data['pages'][0]['rows'][0]['cells'][2]);
        $this->assertSame(['33.3334'], $data['pages'][0]['rows'][0]['cells'][3]);
        $this->assertStringContainsString('Weight: 3.7035 kg', $html);
        $this->assertStringContainsString('PT SURYA UTAMA TEKNOLOGI', $html);
        $this->assertStringContainsString('PURCHASE ORDER NO.', $html);
        $this->assertStringNotContainsString('PESANAN PEMBELIAN', $html);
        $this->assertStringContainsString('class="amount">-</td>', $html);
        $this->assertStringNotContainsString('Nani Sutarman', $html);
        $this->assertStringNotContainsString('Jessica Paune', $html);
        $this->assertStringNotContainsString('generated automatically', $html);
    }

    public function test_partial_awards_exclude_other_lines_and_keep_snapshot_amounts(): void
    {
        $make = require base_path('tests/Support/purchase-order-pdf-fixture.php');
        $po = $make(4);
        $po->setRelation('awards', collect([new PrItemAward(['quotation_item_id' => 1]), new PrItemAward(['quotation_item_id' => 2])]));
        $data = PurchaseOrderPdf::data($po);
        $html = view('pdf.po-pdf', ['po' => $po, 'document' => $data])->render();
        $this->assertSame('1,266,000.00', $data['total']);
        $this->assertStringContainsString('Ordered material 001', $html);
        $this->assertStringContainsString('Ordered material 002', $html);
        $this->assertStringNotContainsString('Ordered material 003', $html);
        $this->assertStringNotContainsString('Ordered material 004', $html);
        $this->assertStringContainsString('REQ/09/2026/001: 0 days', $html);
        $this->assertStringContainsString('REQ/09/2026/002: 30 days', $html);
    }

    public function test_many_items_render_all_pages_with_one_closing_block_and_one_total_box(): void
    {
        $make = require base_path('tests/Support/purchase-order-pdf-fixture.php');
        $po = $make(60);
        $data = PurchaseOrderPdf::data($po);
        $this->assertGreaterThan(1, count($data['pages']));
        $html = view('pdf.po-pdf', ['po' => $po, 'document' => $data])->render();
        $this->assertSame(1, substr_count($html, 'class="signatures"'));
        $this->assertSame(1, substr_count($html, 'class="totals"'));
        $this->assertSame(count($data['pages']), substr_count($html, 'class="company-header"'));
        $this->assertStringContainsString('37,980,000.00', $html);
        foreach ($data['pages'] as $page) {
            $this->assertLessThanOrEqual(277, $data['table_top'] + 10 + 8.5 + $page['height']);
        }
        $pdf = Pdf::loadView('pdf.po-pdf', ['po' => $po, 'document' => $data])->setPaper('a4', 'portrait');
        $pdf->render();
        $this->assertSame(count($data['pages']), $pdf->getDomPDF()->getCanvas()->get_page_count());
    }

    public function test_long_notes_and_unbroken_descriptions_continue_without_losing_content(): void
    {
        $make = require base_path('tests/Support/purchase-order-pdf-fixture.php');
        $po = $make();
        $po->notes = str_repeat('Long note with all delivery requirements. ', 180).'FINAL_NOTE_MARKER';
        $item = $po->quotations->first()->items->first();
        $item->prItem->material_name = str_repeat('W', 2000).'FINAL_MATERIAL_MARKER';
        $data = PurchaseOrderPdf::data($po);
        $rows = collect($data['pages'])->flatMap(fn ($page) => $page['rows']);
        $text = $rows->flatMap(fn ($row) => $row['cells'][1])->implode('');
        $this->assertStringContainsString('FINAL_MATERIAL_MARKER', $text);
        $this->assertSame(1, $rows->filter(fn ($row) => $row['cells'][4] !== [])->count());
        $noteText = collect($data['pages'])->flatMap(fn ($page) => $page['note_lines'])->concat($data['footer_lines'])->pluck('text')->implode('');
        $this->assertStringContainsString('FINAL_NOTE_MARKER', $noteText);
        $html = view('pdf.po-pdf', ['po' => $po, 'document' => $data])->render();
        $this->assertSame(1, substr_count($html, 'Payment Term'));
        $this->assertSame(1, substr_count($html, 'This PO is valid until'));
        $pdf = Pdf::loadView('pdf.po-pdf', ['po' => $po, 'document' => $data])->setPaper('a4', 'portrait');
        $pdf->render();
        $this->assertSame(count($data['pages']), $pdf->getDomPDF()->getCanvas()->get_page_count());
    }

    public function test_supported_currencies_missing_profile_legacy_and_empty_lines_render(): void
    {
        $make = require base_path('tests/Support/purchase-order-pdf-fixture.php');
        foreach (['IDR', 'USD', 'JPY', 'CNY'] as $currency) {
            $po = $make(1, $currency);
            $po->supplier->setRelation('supplier', null);
            $item = $po->quotations->first()->items->first();
            $item->forceFill(['available_qty' => null, 'offered_weight_per_unit' => null, 'available_thickness' => null, 'available_width' => null, 'available_length' => null]);
            $data = PurchaseOrderPdf::data($po);
            $this->assertSame($currency, $data['currency']);
            $this->assertSame(['6.00 pcs'], $data['pages'][0]['rows'][0]['cells'][2]);
            $this->assertSame(['105,500.00'], $data['pages'][0]['rows'][0]['cells'][3]);
            $this->assertSame('Supplier Account', $data['supplier_lines'][0]);
            $pdf = Pdf::loadView('pdf.po-pdf', ['po' => $po, 'document' => $data])->setPaper('a4', 'portrait');
            $pdf->render();
            $this->assertSame(1, $pdf->getDomPDF()->getCanvas()->get_page_count());
        }
        $po = $make(0);
        $data = PurchaseOrderPdf::data($po);
        $this->assertSame('0.00', $data['total']);
        $this->assertCount(1, $data['pages']);
        $this->assertStringContainsString('Supplier Confirmation', view('pdf.po-pdf', ['po' => $po])->render());
        $pdf = Pdf::loadView('pdf.po-pdf', ['po' => $po, 'document' => $data])->setPaper('a4', 'portrait');
        $pdf->render();
        $this->assertSame(1, $pdf->getDomPDF()->getCanvas()->get_page_count());
    }

    public function test_barcode_encodes_current_document_number_and_reference_symbol_pattern(): void
    {
        $make = require base_path('tests/Support/purchase-order-pdf-fixture.php');
        $po = $make();
        $data = PurchaseOrderPdf::data($po);
        $svg = base64_decode(substr($data['barcode'], strpos($data['barcode'], ',') + 1));
        $this->assertStringContainsString('<desc>PNR261178</desc>', $svg);
        $xml = new \DOMDocument;
        $xml->loadXML($svg);
        $rectangles = $xml->getElementsByTagName('rect');
        $runs = '';
        foreach ($rectangles as $i => $rect) {
            $runs .= (string) (int) round((float) $rect->getAttribute('width') * 112 / 125);
            if ($i + 1 < $rectangles->length) {
                $next = $rectangles->item($i + 1);
                $gap = (float) $next->getAttribute('x') - (float) $rect->getAttribute('x') - (float) $rect->getAttribute('width');
                $runs .= (string) (int) round($gap * 112 / 125);
            }
        }
        // Independently extracted reference: Start B, P N R, switch C, 26 11 78, checksum, stop.
        $this->assertSame('2112143131211133212311311131413212212312122411122141212331112', $runs);
        $po->po_number = 'PO/09/2026/001';
        $this->assertNotSame($data['barcode'], PurchaseOrderPdf::data($po)['barcode']);
    }

    public function test_long_supplier_details_and_payment_terms_do_not_overlap_the_final_form(): void
    {
        $make = require base_path('tests/Support/purchase-order-pdf-fixture.php');
        $po = $make();
        $po->supplier->supplier->address = str_repeat('Industrial district and international delivery address. ', 12);
        $po->quotations->first()->payment_terms = str_repeat('Transfer upon verified delivery. ', 300).'FINAL_TERM_MARKER';
        $data = PurchaseOrderPdf::data($po);
        $this->assertGreaterThan(69.5, $data['table_top']);
        $this->assertGreaterThan(1, count($data['pages']));
        $this->assertNotEmpty($data['pages'][0]['rows'], 'Ordered items precede continued terms.');
        $this->assertSame([], end($data['pages'])['rows']);
        $this->assertStringContainsString('FINAL_TERM_MARKER', collect($data['pages'])->flatMap(fn ($page) => $page['note_lines'])->pluck('text')->implode(''));
        $this->assertLessThan($data['note_top'], $data['table_top'] + 8.5 + end($data['pages'])['height'] + 4 + 17);
        $this->assertLessThan(232.3, $data['note_top'] + count($data['footer_lines']) * 3.8);
        $pdf = Pdf::loadView('pdf.po-pdf', ['po' => $po, 'document' => $data])->setPaper('a4', 'portrait');
        $pdf->render();
        $this->assertSame(count($data['pages']), $pdf->getDomPDF()->getCanvas()->get_page_count());
    }

    public function test_pcs_unit_price_is_derived_from_total_without_changing_kg_pricing(): void
    {
        $make = require base_path('tests/Support/purchase-order-pdf-fixture.php');
        $po = $make();
        $item = $po->quotations->first()->items->first();
        $item->forceFill(['available_qty' => 6, 'offered_weight_per_unit' => 10, 'price_per_kg' => 100, 'amount' => 6000]);
        $before = $item->getAttributes();
        $data = PurchaseOrderPdf::data($po);
        $row = $data['pages'][0]['rows'][0];
        $this->assertSame(['6.00 pcs'], $row['cells'][2]);
        $this->assertSame(['1,000.00'], $row['cells'][3]);
        $this->assertSame(['6,000.00'], $row['cells'][4]);
        $this->assertSame('6,000.00', $data['total']);
        $this->assertStringContainsString('Weight: 60.00 kg', implode(' ', $row['cells'][1]));
        $this->assertSame($before, $item->getAttributes());
    }

    public function test_zero_pcs_uses_a_missing_unit_price_without_recalculating_the_total(): void
    {
        $make = require base_path('tests/Support/purchase-order-pdf-fixture.php');
        $po = $make();
        $po->quotations->first()->items->first()->forceFill(['available_qty' => 0, 'amount' => 6000]);
        $data = PurchaseOrderPdf::data($po);
        $this->assertSame(['0.00 pcs'], $data['pages'][0]['rows'][0]['cells'][2]);
        $this->assertSame(['-'], $data['pages'][0]['rows'][0]['cells'][3]);
        $this->assertSame(['6,000.00'], $data['pages'][0]['rows'][0]['cells'][4]);
        $this->assertSame('6,000.00', $data['total']);
    }
}
