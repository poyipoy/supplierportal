<?php

namespace Tests\Feature;

use App\Exports\InspectionsExport;
use App\Exports\LocalInvoicesExport;
use App\Exports\PurchaseOrdersExport;
use App\Exports\QuotationsExport;
use App\Exports\RequisitionsExport;
use App\Exports\ShipmentsExport;
use App\Models\ExchangeRate;
use App\Models\LocalInvoice;
use App\Models\Period;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequisition;
use App\Models\QcInspection;
use App\Models\Quotation;
use App\Models\Shipment;
use App\Models\Supplier;
use App\Models\User;
use App\Support\Export\ExportDefinitions;
use App\Support\Export\ExportOptions;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdvancedExportBaselineTest extends TestCase
{
    use RefreshDatabase;

    private User $finance;

    private User $accounting;

    protected function beforeRefreshingDatabase(): void
    {
        $this->assertSame('adasi_portal_test', config('database.connections.mysql.database'));
        $this->assertSame('adasi_portal_test', DB::selectOne('SELECT DATABASE() AS db')->db);
    }

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.business_timezone' => 'Asia/Jakarta']);
        $this->travelTo(new Carbon('2026-08-18 10:00:00', 'UTC'));
        $purchasing = User::factory()->create(['name' => 'Baseline Purchasing', 'role' => 'purchasing']);
        $supplier = User::factory()->create(['name' => 'Baseline Supplier', 'role' => 'supplier']);
        Supplier::create(['user_id' => $supplier->id, 'company_name' => 'Baseline Company']);
        $qc = User::factory()->create(['role' => 'qc']);
        $this->finance = User::factory()->create(['role' => 'finance']);
        $this->accounting = User::factory()->create(['role' => 'accounting']);
        $period = Period::create(['name' => 'Baseline Period', 'month' => 8, 'year' => 2026, 'status' => 'open', 'created_by' => $purchasing->id]);
        $rate = ExchangeRate::create(['currency' => 'USD', 'rate_to_idr' => 16000, 'valid_from' => '2026-08-01', 'created_by' => $purchasing->id]);
        $pr = PurchaseRequisition::create(['period_id' => $period->id, 'created_by' => $purchasing->id, 'pr_number' => 'REQ/08/2026/901', 'status' => 'bidding']);
        $pr->forceFill(['created_at' => '2026-08-15 18:30:00'])->save();
        $item = $pr->items()->create(['hs_code' => '7209.16.00', 'material_name' => '=Baseline Steel', 'quantity' => 2, 'shape' => 'Flat', 'thickness' => 2.5, 'width' => 1000, 'length' => 2000, 'weight_needed' => 100, 'remark' => '+Baseline remark']);
        $quotation = Quotation::create(['pr_id' => $pr->id, 'supplier_id' => $supplier->id, 'exchange_rate_id' => $rate->id, 'currency' => 'USD', 'status' => 'submitted', 'submitted_at' => '2026-08-15 18:30:00']);
        $quoteItem = $quotation->items()->create(['pr_item_id' => $item->id, 'price_per_kg' => 2.5, 'amount' => 500, 'is_available' => true, 'available_qty' => 2, 'available_thickness' => 2.5, 'available_width' => 1000, 'available_length' => 2000, 'offered_weight_per_unit' => 100, 'offered_weight_source' => 'supplier', 'notes' => '@Baseline note']);
        $po = PurchaseOrder::create(['supplier_id' => $supplier->id, 'currency' => 'USD', 'exchange_rate_id' => $rate->id, 'po_number' => 'PO/08/2026/901', 'status' => 'active', 'created_by' => $purchasing->id, 'estimated_arrival' => '2026-08-31', 'notes' => '-Baseline PO note']);
        $po->quotations()->attach($quotation->id);
        $inspection = QcInspection::create(['po_id' => $po->id, 'inspected_by' => $qc->id, 'status' => 'ok', 'inspected_at' => '2026-08-15 18:30:00']);
        $inspection->items()->create(['pr_item_id' => $item->id, 'actual_thickness' => 2.5, 'actual_width' => 1000, 'actual_length' => 2000, 'status' => 'ok']);
        $shipment = Shipment::create(['shipment_number' => 'SHP/08/2026/901', 'supplier_id' => $supplier->id, 'status' => 'draft', 'shipment_date' => '2026-08-17', 'estimated_arrival_date' => '2026-08-31', 'created_by' => $supplier->id, 'notes' => '=Baseline shipment']);
        $shipment->items()->create(['purchase_order_id' => $po->id, 'quotation_item_id' => $quoteItem->id, 'shipped_qty' => 2, 'actual_weight_kg' => '123.4500']);
        $invoice = LocalInvoice::create(['submission_number' => 'LSI-2026-00901', 'supplier_id' => $supplier->id, 'invoice_number' => 'INV-BASELINE', 'invoice_date' => '2026-08-15', 'po_number' => 'LOCAL-PO-901', 'currency' => 'IDR', 'invoice_amount' => '100000.25', 'tax_amount' => '11000.03', 'tax_invoice_number' => '010.000-26.12345678', 'status' => LocalInvoice::STATUS_READY_TO_PAY, 'submitted_at' => '2026-08-15 18:30:00', 'approved_at' => '2026-08-16 18:30:00', 'payment_term_days_snapshot' => 30, 'due_date' => '2026-09-15', 'scheduled_payment_date' => '2026-09-16']);
        $invoice->receipt()->create(['receipt_number' => 'RCP-BASELINE', 'issued_at' => '2026-08-15 18:30:00']);
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    public static function exports(): array
    {
        $cases = [];
        foreach (['en', 'id'] as $locale) {
            foreach (['po', 'quotation', 'pr', 'shipment', 'inspection', 'invoice'] as $key) {
                $cases["{$key}-{$locale}"] = [$key, $locale, 'finance', false];
            }
            foreach (['finance', 'accounting'] as $role) {
                foreach ([false, true] as $payments) {
                    if ($role === 'finance' && ! $payments) {
                        continue;
                    }
                    $cases["invoice-{$role}-{$locale}-".(int) $payments] = ['invoice', $locale, $role, $payments];
                }
            }
        }

        return $cases;
    }

    #[DataProvider('exports')]
    public function test_default_headings_and_first_mapped_row_are_golden(string $key, string $locale, string $role, bool $payments): void
    {
        app()->setLocale($locale);
        $export = match ($key) {
            'po' => new PurchaseOrdersExport,
            'quotation' => new QuotationsExport,
            'pr' => new RequisitionsExport,
            'shipment' => new ShipmentsExport,
            'inspection' => new InspectionsExport,
            'invoice' => new LocalInvoicesExport(($role === 'finance' ? $this->finance : $this->accounting)->id, [], $payments),
        };
        $export->setExportLocale($locale);
        $row = $export->query()->firstOrFail();
        $this->assertSame($this->headings($key, $locale), $export->headings());
        $this->assertSame($this->mappedRow($key, $locale), $export->map($row));
        $this->assertSame(count($export->headings()), count($export->map($row)));
        if ($key !== 'invoice') {
            $this->assertSame($this->mappedRow($key, $locale), $export->collection()->first());
        }

        // Warm the count cache and attach scalar progress/locale context before serialization.
        $this->assertSame(1, $export->querySize());
        $export->setExportProgressContext(901);
        $restored = unserialize(serialize($export));
        $this->assertSame($locale, $restored->preferredLocale());
        $this->assertSame(1, $restored->querySize());
        $this->assertSame($this->headings($key, $locale), $restored->headings());
        $this->assertSame($this->mappedRow($key, $locale), $restored->map($restored->query()->firstOrFail()));
    }

    private function headings(string $key, string $locale): array
    {
        // Literal expectations intentionally do not call translation/model helpers.
        $en = [
            'po' => ['PO Number', 'PR Number', 'Supplier', 'Material', 'Currency', 'Total Amount', 'Total IDR', 'Est. Arrival', 'Remark', 'Status'],
            'quotation' => ['PR Number', 'Period', 'Supplier', 'Currency', 'Material', 'HS Code', 'Requested Quantity', 'Requested Dimensions', 'Offered Quantity', 'Offered Dimensions', 'Price per Kg', 'Amount', 'Exchange Rate', 'Total IDR', 'Item Notes', 'Status', 'Submitted At (WIB)', 'Availability', 'Offered Length', 'Offer Weight/Unit', 'Offer Weight Source', 'Offer Total Weight', 'Requested Amount', 'Offer Amount'],
            'pr' => ['PR Number', 'Period', 'Material Name', 'Specification', 'Qty', 'Weight/Unit', 'Total Weight', 'PR Total KG', 'Remark', 'Status', 'Date Created (WIB)'],
            'shipment' => ['Shipment Number', 'Supplier', 'Consolidated POs', 'Items Count', 'Total Qty', 'Actual Weight (Kg)', 'Shipment Date', 'Est. Arrival Date', 'Actual Arrival Date', 'Status', 'Notes / Remarks'],
            'inspection' => ['PO Number', 'Supplier', 'Material', 'Requested Specification', 'Actual Dimensions', 'Item Status', 'Inspection Status', 'Inspection Date (WIB)'],
            'invoice' => ['Submission', 'Receipt', 'Invoice', 'Tax Invoice Number', 'PO Reference', 'Supplier', 'Currency', 'Invoice Amount', 'PPN', 'Status', 'Submitted (WIB)', 'Approved (WIB)', 'Payment Term Days', 'Due Date', 'Scheduled Payment', 'Completed (WIB)'],
        ];
        $id = [
            'po' => ['Nomor PO', 'Nomor PR', 'Pemasok', 'Material', 'Mata Uang', 'Total Nilai', 'Total IDR', 'Est. Kedatangan', 'Catatan', 'Status'],
            'quotation' => ['Nomor PR', 'Periode', 'Pemasok', 'Mata Uang', 'Material', 'Kode HS', 'Jumlah Permintaan', 'Dimensi Permintaan', 'Jumlah Penawaran', 'Dimensi Penawaran', 'Harga per Kg', 'Nilai', 'Kurs', 'Total IDR', 'Catatan Item', 'Status', 'Diajukan Pada (WIB)', 'Ketersediaan', 'Panjang Penawaran', 'Berat Penawaran/Unit', 'Sumber Berat Penawaran', 'Total Berat Penawaran', 'Nilai Permintaan', 'Nilai Penawaran'],
            'pr' => ['Nomor PR', 'Periode', 'Nama Material', 'Spesifikasi', 'Jumlah', 'Berat/Unit', 'Total Berat', 'Total KG PR', 'Catatan', 'Status', 'Tanggal Dibuat (WIB)'],
            'shipment' => ['Nomor Pengiriman', 'Pemasok', 'PO Terkonsolidasi', 'Jumlah Item', 'Total Jumlah', 'Berat Aktual (Kg)', 'Tanggal Pengiriman', 'Est. Tanggal Kedatangan', 'Tanggal Kedatangan Aktual', 'Status', 'Catatan'],
            'inspection' => ['Nomor PO', 'Pemasok', 'Material', 'Spesifikasi Permintaan', 'Dimensi Aktual', 'Status Item', 'Status Inspeksi', 'Tanggal Inspeksi (WIB)'],
            'invoice' => ['Pengajuan', 'Tanda Terima', 'Invoice', 'Nomor Faktur Pajak', 'Referensi PO', 'Pemasok', 'Mata Uang', 'Nilai Invoice', 'PPN', 'Status', 'Diajukan (WIB)', 'Disetujui (WIB)', 'Termin Pembayaran (Hari)', 'Jatuh Tempo', 'Jadwal Pembayaran', 'Selesai (WIB)'],
        ];

        return ($locale === 'en' ? $en : $id)[$key];
    }

    public static function rolledOutExports(): array
    {
        $cases = [];
        foreach (['en', 'id'] as $locale) {
            foreach (['quotation', 'pr', 'shipment', 'inspection', 'invoice'] as $key) {
                $cases[$key.'-'.$locale] = [$key, $locale];
            }
        }

        return $cases;
    }

    #[DataProvider('rolledOutExports')]
    public function test_rollout_catalog_default_and_serialization_match_literal_baseline(string $key, string $locale): void
    {
        app()->setLocale($locale);
        $export = match ($key) {
            'quotation' => new QuotationsExport,'pr' => new RequisitionsExport,'shipment' => new ShipmentsExport,
            'inspection' => new InspectionsExport, 'invoice' => new LocalInvoicesExport($this->finance->id),
        };
        $audience = match ($key) {
            'inspection' => 'qc', 'invoice' => 'finance', default => 'purchasing'
        };
        $definition = ExportDefinitions::forClass($export::class, $audience);
        $export->applyOptions(new ExportOptions(ExportDefinitions::defaultKeys($definition), 'xlsx', $audience));
        $export->setExportLocale($locale);
        $this->assertSame($this->headings($key, $locale), $export->headings());
        $this->assertSame($this->mappedRow($key, $locale), $export->map($export->query()->firstOrFail()));
        $restored = unserialize(serialize($export));
        $this->assertSame($this->mappedRow($key, $locale), $restored->map($restored->query()->firstOrFail()));
    }

    private function mappedRow(string $key, string $locale): array
    {
        $english = $locale === 'en';

        return match ($key) {
            'po' => ['PO/08/2026/901', 'REQ/08/2026/901', 'Baseline Supplier', "'=Baseline Steel", 'USD', 500.0, 8000000.0, '2026-08-31', "'-Baseline PO note", $english ? 'Active' : 'Aktif'],
            'quotation' => ['REQ/08/2026/901', 'Baseline Period (08/2026)', 'Baseline Company', 'USD', "'=Baseline Steel", '7209.16.00', 2, '2.5 × 1000 × 2000', 2, '2.5 × 1000 × 2000', 2.5, 500.0, 16000.0, 8000000.0, "'@Baseline note", $english ? 'Submitted' : 'Diajukan', '2026-08-16 01:30:00', $english ? 'Available' : 'Tersedia', '2000', 100.0, 'supplier', 200.0, 500.0, 500.0],
            'pr' => ['REQ/08/2026/901', 'Baseline Period (08/2026)', "'=Baseline Steel", 'Flat | 2.5 × 1000 × 2000', 2, 100.0, 200.0, 200.0, "'+Baseline remark", $english ? 'Bidding' : 'Penawaran Berlangsung', '2026-08-16 01:30:00'],
            'shipment' => ['SHP/08/2026/901', 'Baseline Supplier', 'PO/08/2026/901', 1, 2, '123.45', '2026-08-17', '2026-08-31', '-', $english ? 'Draft' : 'Draf', "'=Baseline shipment"],
            'inspection' => ['PO/08/2026/901', 'Baseline Supplier', "'=Baseline Steel", 'Flat | 2.5 × 1000 × 2000', 'T:2.5000 | W:1000.0000 | L:2000.0000', 'OK', 'OK', '16/08/2026 01:30'],
            'invoice' => ['LSI-2026-00901', 'RCP-BASELINE', 'INV-BASELINE', '010.000-26.12345678', 'LOCAL-PO-901', 'Baseline Company', 'IDR', '100000.25', '11000.03', $english ? 'Ready to Pay' : 'Siap Dibayar', '2026-08-16', '2026-08-17', '30', '2026-09-15', '2026-09-16', '-'],
        };
    }
}
