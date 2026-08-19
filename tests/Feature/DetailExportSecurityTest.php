<?php

namespace Tests\Feature;

use App\Exports\PurchaseOrderDetailExport;
use App\Exports\PurchaseOrdersExport;
use App\Exports\QuotationDetailExport;
use App\Jobs\ProcessExportJob;
use App\Models\ExchangeRate;
use App\Models\ExportJob;
use App\Models\MaterialClaim;
use App\Models\Period;
use App\Models\PoDocument;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequisition;
use App\Models\QcInspection;
use App\Models\Quotation;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Maatwebsite\Excel\Excel as ExcelFormat;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Tests\TestCase;

class DetailExportSecurityTest extends TestCase
{
    use RefreshDatabase;

    private User $purchasing;

    private User $supplierA;

    private User $supplierB;

    private Period $period;

    private ExchangeRate $rate;

    protected function setUp(): void
    {
        parent::setUp();

        $this->purchasing = User::factory()->create(['role' => 'purchasing', 'is_active' => true]);
        $this->supplierA = User::factory()->create(['name' => 'Alpha = Supplier', 'role' => 'supplier', 'is_active' => true]);
        $this->supplierB = User::factory()->create(['name' => 'Beta Supplier', 'role' => 'supplier', 'is_active' => true]);

        Supplier::create(['user_id' => $this->supplierA->id, 'company_name' => '=Alpha Steel']);
        Supplier::create(['user_id' => $this->supplierB->id, 'company_name' => 'Beta Metals']);

        $this->period = Period::create([
            'name' => 'August 2026',
            'month' => 8,
            'year' => 2026,
            'status' => 'open',
            'created_by' => $this->purchasing->id,
        ]);

        $this->rate = ExchangeRate::create([
            'currency' => 'USD',
            'rate_to_idr' => 16000,
            'valid_from' => '2026-08-01',
            'created_by' => $this->purchasing->id,
        ]);
    }

    public function test_quotation_detail_is_per_item_numeric_formula_safe_and_supplier_isolated(): void
    {
        $pr = $this->createPr('REQ/08/2026/501', [
            ['name' => '=Unsafe Material', 'remark' => '+Unsafe Remark'],
            ['name' => 'Second Material', 'remark' => null],
        ]);
        $quotation = $this->createQuotation($pr, $this->supplierA, [
            'reviewer_notes' => '@Internal Note',
            'general_notes' => '-General Note',
            'payment_terms' => '30 days',
        ]);

        $export = new QuotationDetailExport($quotation->id);
        $rows = $export->collection();

        $this->assertCount(2, $rows);
        $this->assertContains('Reviewer Notes', $export->headings());
        $this->assertSame("'=Unsafe Material", $rows->first()[11]);
        $this->assertSame("'@Internal Note", $rows->first()[10]);
        $this->assertIsInt($rows->first()[13]);
        $this->assertIsFloat($rows->first()[17]);
        $this->assertIsFloat($rows->first()[22]);

        $supplierExport = new QuotationDetailExport($quotation->id, $this->supplierA->id, false);
        $this->assertNotContains('Reviewer Notes', $supplierExport->headings());
        $this->assertCount(2, $supplierExport->collection());

        $sheet = $this->spreadsheetFor($export)->getActiveSheet();
        $this->assertSame(DataType::TYPE_NUMERIC, $sheet->getCell('N2')->getDataType());
        $this->assertSame(DataType::TYPE_NUMERIC, $sheet->getCell('R2')->getDataType());

        Queue::fake();

        $this->actingAs($this->purchasing)
            ->get(route('purchasing.export.quotations.detail', $quotation))
            ->assertRedirect();
        $purchasingJob = ExportJob::query()->latest('id')->firstOrFail();
        $this->assertSame(QuotationDetailExport::class, $purchasingJob->export_class);
        $this->assertSame([$quotation->id], $purchasingJob->export_args);

        $this->actingAs($this->supplierA)
            ->get(route('supplier.export.quotations.detail', $quotation))
            ->assertRedirect();
        $supplierJob = ExportJob::query()->latest('id')->firstOrFail();
        $this->assertSame([$quotation->id, $this->supplierA->id, false], $supplierJob->export_args);

        $this->actingAs($this->supplierB)
            ->get(route('supplier.export.quotations.detail', $quotation))
            ->assertForbidden();

        Queue::assertPushed(ProcessExportJob::class, 2);
    }

    public function test_consolidated_po_detail_contains_all_items_and_latest_statuses(): void
    {
        $prOne = $this->createPr('REQ/08/2026/511');
        $prTwo = $this->createPr('REQ/08/2026/512');
        $quotationOne = $this->createQuotation($prOne, $this->supplierA);
        $quotationTwo = $this->createQuotation($prTwo, $this->supplierA);
        $po = PurchaseOrder::create([
            'supplier_id' => $this->supplierA->id,
            'currency' => 'USD',
            'exchange_rate_id' => $this->rate->id,
            'po_number' => 'PO/08/2026/511',
            'status' => 'active',
            'created_by' => $this->purchasing->id,
            'estimated_arrival' => '2026-08-31',
            'notes' => '@PO Remark',
        ]);
        $po->quotations()->attach([$quotationOne->id, $quotationTwo->id]);

        foreach (['invoice', 'bl', 'packing_list', 'form_e'] as $type) {
            PoDocument::create(['po_id' => $po->id, 'doc_type' => $type, 'status' => 'verified']);
        }

        $older = QcInspection::create([
            'po_id' => $po->id,
            'inspected_by' => $this->purchasing->id,
            'status' => 'ng',
            'inspected_at' => '2026-08-20 09:00:00',
        ]);
        $latest = QcInspection::create([
            'po_id' => $po->id,
            'inspected_by' => $this->purchasing->id,
            'status' => 'ok',
            'inspected_at' => '2026-08-21 09:00:00',
        ]);
        $olderClaim = MaterialClaim::create([
            'inspection_id' => $older->id,
            'po_id' => $po->id,
            'submitted_by' => $this->purchasing->id,
            'supplier_id' => $this->supplierA->id,
            'status' => 'resolved',
        ]);
        $olderClaim->forceFill([
            'created_at' => '2026-08-20 10:00:00',
            'updated_at' => '2026-08-20 11:00:00',
        ])->save();
        $latestClaim = MaterialClaim::create([
            'inspection_id' => $latest->id,
            'po_id' => $po->id,
            'submitted_by' => $this->purchasing->id,
            'supplier_id' => $this->supplierA->id,
            'status' => 'pending',
        ]);
        $latestClaim->forceFill([
            'created_at' => '2026-08-22 10:00:00',
            'updated_at' => '2026-08-22 11:00:00',
        ])->save();

        $export = new PurchaseOrderDetailExport($po->id);
        $rows = $export->collection();

        $this->assertCount(2, $rows);
        $this->assertSame(['REQ/08/2026/511', 'REQ/08/2026/512'], $rows->pluck(1)->all());
        $this->assertSame('OK', $rows->first()[22]);
        $this->assertSame('Pending', $rows->first()[24]);
        $this->assertSame("'@PO Remark", $rows->first()[17]);
        $this->assertIsFloat($rows->first()[10]);
        $this->assertIsFloat($rows->first()[12]);
        $this->assertSame('Verified', $rows->first()[18]);

        Queue::fake();

        $this->actingAs($this->purchasing)
            ->get(route('purchasing.export.purchase-orders.detail', $po))
            ->assertRedirect();
        $purchasingJob = ExportJob::query()->latest('id')->firstOrFail();
        $this->assertSame(PurchaseOrderDetailExport::class, $purchasingJob->export_class);
        $this->assertSame([$po->id], $purchasingJob->export_args);

        $this->actingAs($this->supplierA)
            ->get(route('supplier.export.purchase-orders.detail', $po))
            ->assertRedirect();
        $supplierJob = ExportJob::query()->latest('id')->firstOrFail();
        $this->assertSame([$po->id, $this->supplierA->id], $supplierJob->export_args);

        $this->actingAs($this->supplierB)
            ->get(route('supplier.export.purchase-orders.detail', $po))
            ->assertForbidden();

        Queue::assertPushed(ProcessExportJob::class, 2);
    }

    public function test_supplier_po_list_ignores_spoofed_supplier(): void
    {
        $ownPr = $this->createPr('REQ/08/2026/521');
        $otherPr = $this->createPr('REQ/08/2026/522');
        $ownQuotation = $this->createQuotation($ownPr, $this->supplierA);
        $ownPo = $this->createPo($ownQuotation, 'PO/08/2026/521');
        $otherQuotation = $this->createQuotation($otherPr, $this->supplierB);
        $otherPo = $this->createPo($otherQuotation, 'PO/08/2026/522');

        $scopedRows = (new PurchaseOrdersExport($this->supplierA->id))->collection()->pluck(0)->all();
        $this->assertContains($ownPo->po_number, $scopedRows);
        $this->assertNotContains($otherPo->po_number, $scopedRows);

        Queue::fake();
        $this->actingAs($this->supplierA)
            ->get(route('supplier.export.purchase-orders', ['supplier_id' => $this->supplierB]))
            ->assertRedirect();

        $queued = ExportJob::query()->latest('id')->firstOrFail();
        $this->assertSame(PurchaseOrdersExport::class, $queued->export_class);
        $this->assertSame($this->supplierA->id, $queued->export_args[0]);
        Queue::assertPushed(ProcessExportJob::class, fn (ProcessExportJob $job) => $job->exportJobId === $queued->id);
    }

    public function test_async_export_controls_use_the_global_no_refresh_downloader(): void
    {
        $layout = file_get_contents(resource_path('views/layouts/app.blade.php'));
        $script = file_get_contents(public_path('assets/js/async-export.js'));

        $this->assertStringContainsString("asset('assets/js/async-export.js')", $layout);
        $this->assertStringContainsString('window.fetch(requestUrl', $script);
        $this->assertStringContainsString('const blob = await response.blob()', $script);
        $this->assertStringContainsString('window.URL.createObjectURL(blob)', $script);
        $this->assertStringContainsString("document.addEventListener('click'", $script);
        $this->assertStringNotContainsString("document.createElement('iframe')", $script);
        $this->assertStringNotContainsString('window.location.href', $script);
        $this->assertStringContainsString('this.href = exportUrl.toString()', file_get_contents(resource_path('views/supplier/po/index.blade.php')));
        $this->assertStringContainsString('data-async-export', file_get_contents(resource_path('views/purchasing/pr/index.blade.php')));
        $this->assertStringContainsString('data-async-export', file_get_contents(resource_path('views/purchasing/reports/index.blade.php')));
        $this->assertStringContainsString('data-async-export', file_get_contents(resource_path('views/supplier/price-history/historical.blade.php')));
        $this->assertStringNotContainsString('data-async-export', file_get_contents(resource_path('views/purchasing/pr/_import_controls.blade.php')));
        $this->assertStringNotContainsString('data-async-export', file_get_contents(resource_path('views/supplier/quotations/_import_controls.blade.php')));
    }

    private function createPr(string $number, array $items = []): PurchaseRequisition
    {
        $pr = PurchaseRequisition::create([
            'period_id' => $this->period->id,
            'created_by' => $this->purchasing->id,
            'pr_number' => $number,
            'status' => 'bidding',
        ]);

        $items = $items ?: [['name' => 'Material', 'remark' => null]];
        foreach ($items as $index => $item) {
            $pr->items()->create([
                'hs_code' => '7209.16.'.str_pad((string) $index, 2, '0', STR_PAD_LEFT),
                'material_name' => $item['name'],
                'quantity' => 2,
                'shape' => 'Flat',
                'thickness' => 2.5,
                'width' => 1000,
                'length' => 2000,
                'weight_needed' => 100,
                'remark' => $item['remark'] ?? null,
            ]);
        }

        return $pr;
    }

    private function createQuotation(PurchaseRequisition $pr, User $supplier, array $attributes = []): Quotation
    {
        $quotation = Quotation::create(array_merge([
            'pr_id' => $pr->id,
            'supplier_id' => $supplier->id,
            'exchange_rate_id' => $this->rate->id,
            'currency' => 'USD',
            'status' => 'accepted',
            'submitted_at' => '2026-08-15 10:00:00',
            'estimated_delivery' => '2026-08-30',
            'validity_period' => '2026-09-30',
            'payment_terms' => '30 days',
            'general_notes' => 'General note',
            'reviewer_notes' => 'Reviewer note',
        ], $attributes));

        foreach ($pr->items as $item) {
            $quotation->items()->create([
                'pr_item_id' => $item->id,
                'price_per_kg' => 9.99,
                'amount' => 123.45,
                'available_qty' => 7,
                'available_thickness' => 2.5,
                'available_width' => 1000,
                'available_length' => 2000,
                'notes' => '=Item Note',
            ]);
        }

        return $quotation;
    }

    private function createPo(Quotation $quotation, string $number): PurchaseOrder
    {
        $po = PurchaseOrder::create([
            'supplier_id' => $quotation->supplier_id,
            'currency' => $quotation->currency,
            'exchange_rate_id' => $this->rate->id,
            'po_number' => $number,
            'status' => 'active',
            'created_by' => $this->purchasing->id,
            'estimated_arrival' => '2026-08-31',
        ]);
        $po->quotations()->attach($quotation->id);

        return $po;
    }

    private function spreadsheetFor(object $export): Spreadsheet
    {
        $contents = Excel::raw($export, ExcelFormat::XLSX);
        $path = tempnam(sys_get_temp_dir(), 'detail-export-');
        file_put_contents($path, $contents);

        try {
            return IOFactory::load($path);
        } finally {
            unlink($path);
        }
    }
}
