<?php

namespace Tests\Feature;

use App\Exports\PurchaseOrdersExport;
use App\Exports\PurchaseRequisitionDetailExport;
use App\Exports\QuotationsExport;
use App\Exports\RequisitionsExport;
use App\Models\ExchangeRate;
use App\Models\Period;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequisition;
use App\Models\Quotation;
use App\Models\Supplier;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Maatwebsite\Excel\Excel as ExcelFormat;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Tests\TestCase;

class MissionFourExportTest extends TestCase
{
    use RefreshDatabase;

    private User $purchasing;

    private User $supplierA;

    private User $supplierB;

    private Period $august;

    private Period $september;

    private ExchangeRate $rate;

    protected function setUp(): void
    {
        parent::setUp();

        $this->purchasing = User::factory()->create([
            'name' => 'Mission Four Purchasing',
            'role' => 'purchasing',
            'is_active' => true,
        ]);
        $this->supplierA = User::factory()->create([
            'name' => 'Supplier Alpha User',
            'role' => 'supplier',
            'is_active' => true,
        ]);
        $this->supplierB = User::factory()->create([
            'name' => 'Supplier Beta User',
            'role' => 'supplier',
            'is_active' => true,
        ]);

        Supplier::create([
            'user_id' => $this->supplierA->id,
            'company_name' => 'Alpha Steel',
        ]);
        Supplier::create([
            'user_id' => $this->supplierB->id,
            'company_name' => 'Beta Metals',
        ]);

        $this->august = $this->createPeriod('August 2026', 8);
        $this->september = $this->createPeriod('September 2026', 9);

        $this->rate = ExchangeRate::create([
            'currency' => 'USD',
            'rate_to_idr' => 16000,
            'valid_from' => '2026-08-01',
            'created_by' => $this->purchasing->id,
        ]);
    }

    public function test_requisition_list_and_detail_exports_honor_filters_and_isolate_the_target_pr(): void
    {
        $target = $this->createRequisition(
            $this->august,
            'REQ/08/2026/401',
            'submitted',
            [
                ['material_name' => '=Formula Material', 'remark' => '+Formula Remark'],
                ['material_name' => 'Second Material', 'remark' => 'Second remark'],
            ]
        );
        $this->createRequisition($this->august, 'REQ/08/2026/402', 'completed');
        $this->createRequisition($this->september, 'REQ/09/2026/401', 'submitted');

        $listExport = new RequisitionsExport($this->august->id, 'submitted', 'REQ/08/2026/401');
        $listRows = $listExport->collection();

        $this->assertCount(2, $listRows);
        $this->assertSame("'=Formula Material", $listRows->first()[2]);
        $this->assertSame("'+Formula Remark", $listRows->first()[7]);
        $this->assertIsInt($listRows->first()[4]);
        $this->assertIsFloat($listRows->first()[5]);

        $detailExport = new PurchaseRequisitionDetailExport($target->id);
        $detailRows = $detailExport->collection();

        $this->assertCount(2, $detailRows);
        $this->assertSame(['REQ/08/2026/401'], $detailRows->pluck(0)->unique()->values()->all());
        $this->assertNotContains('REQ/08/2026/402', $detailRows->pluck(0)->all());

        $this->actingAs($this->purchasing)
            ->get(route('purchasing.export.requisitions.detail', $target))
            ->assertOk()
            ->assertHeader('content-disposition');

        $this->actingAs($this->supplierA)
            ->get(route('purchasing.export.requisitions.detail', $target))
            ->assertForbidden();

        $this->actingAs($this->purchasing)
            ->get('/purchasing/export/requisitions/not-a-valid-id')
            ->assertNotFound();
    }

    public function test_purchase_order_export_honors_overdue_and_search_filters_and_keeps_numeric_cells(): void
    {
        $targetPr = $this->createRequisition($this->august, 'REQ/08/2026/410', 'completed');
        $targetQuotation = $this->createQuotation($targetPr, $this->supplierA, 'accepted', '2026-08-15 10:00:00');
        $overdue = $this->createPurchaseOrder(
            $this->supplierA,
            $targetQuotation,
            'PO/08/2026/410',
            '-Formula PO remark',
            'active',
            '2026-07-31'
        );

        $otherPr = $this->createRequisition($this->september, 'REQ/09/2026/499', 'completed');
        $otherQuotation = $this->createQuotation($otherPr, $this->supplierB, 'accepted', '2026-09-15 10:00:00');
        $this->createPurchaseOrder(
            $this->supplierB,
            $otherQuotation,
            'PO/09/2026/499',
            'Other PO',
            'completed',
            '2026-10-01'
        );

        Carbon::setTestNow('2026-08-03 12:00:00');

        try {
            $export = new PurchaseOrdersExport(null, null, null, 'PO/08/2026', 'overdue', 'Alpha');
            $rows = $export->collection();
        } finally {
            Carbon::setTestNow();
        }

        $this->assertCount(1, $rows);
        $this->assertSame($overdue->po_number, $rows->first()[0]);
        $this->assertSame('USD', $rows->first()[4]);
        $this->assertIsFloat($rows->first()[5]);
        $this->assertIsFloat($rows->first()[6]);
        $this->assertSame("'-Formula PO remark", $rows->first()[8]);
        $this->assertSame('Overdue', $rows->first()[9]);

        $spreadsheet = $this->spreadsheetFor($export);
        $sheet = $spreadsheet->getActiveSheet();
        $this->assertSame(DataType::TYPE_NUMERIC, $sheet->getCell('F2')->getDataType());
        $this->assertSame(DataType::TYPE_NUMERIC, $sheet->getCell('G2')->getDataType());

        $emptySheet = $this->spreadsheetFor(new PurchaseOrdersExport(null, null, null, 'NO-MATCH'))->getActiveSheet();
        $this->assertSame(1, $emptySheet->getHighestRow());
        $this->assertSame('PO Number', $emptySheet->getCell('A1')->getValue());
    }

    public function test_purchasing_quotation_export_is_per_item_filterable_formula_safe_and_numeric(): void
    {
        $pr = $this->createRequisition(
            $this->august,
            'REQ/08/2026/420',
            'bidding',
            [
                ['material_name' => '@Formula Material', 'remark' => null],
                ['material_name' => 'Normal Material', 'remark' => null],
            ]
        );
        $quotation = $this->createQuotation($pr, $this->supplierA, 'submitted', '2026-08-20 09:30:00', true);

        $otherPr = $this->createRequisition($this->september, 'REQ/09/2026/421', 'bidding');
        $this->createQuotation($otherPr, $this->supplierB, 'submitted', '2026-09-20 09:30:00');

        $filters = [
            'pr_number' => 'REQ/08/2026/420',
            'date_from' => '2026-08',
            'date_to' => '2026-08',
            'supplier_id' => $this->supplierA->id,
            'status' => 'submitted',
            'currency' => 'USD',
        ];
        $export = new QuotationsExport($filters);
        $rows = $export->collection();

        $this->assertCount(2, $rows);
        $this->assertSame('Alpha Steel', $rows->first()[2]);
        $this->assertSame("'@Formula Material", $rows->first()[4]);
        $this->assertSame(7, $rows->first()[8]);
        $this->assertSame(123.45, $rows->first()[11]);
        $this->assertSame(16000.0, $rows->first()[12]);
        $this->assertSame(123.45 * 16000, $rows->first()[13]);
        $this->assertSame("'=Formula item note", $rows->first()[14]);

        $sheet = $this->spreadsheetFor($export)->getActiveSheet();
        $this->assertSame(3, $sheet->getHighestRow());
        $this->assertSame("'@Formula Material", $sheet->getCell('E2')->getValue());

        foreach (['G2', 'I2', 'K2', 'L2', 'M2', 'N2'] as $coordinate) {
            $this->assertSame(DataType::TYPE_NUMERIC, $sheet->getCell($coordinate)->getDataType(), $coordinate);
        }

        $emptySheet = $this->spreadsheetFor(new QuotationsExport(['pr_number' => 'NO-MATCH']))->getActiveSheet();
        $this->assertSame(1, $emptySheet->getHighestRow());
        $this->assertSame('Submitted At', $emptySheet->getCell('Q1')->getValue());

        Carbon::setTestNow('2026-08-03 10:11:12');
        Excel::fake();

        try {
            $this->actingAs($this->purchasing)
                ->get(route('purchasing.export.quotations', $filters))
                ->assertOk();

            Excel::assertDownloaded('rekap_quotations_20260803_101112.xlsx', function (QuotationsExport $download) use ($quotation) {
                return $download->collection()->pluck(0)->unique()->all() === [$quotation->purchaseRequisition->pr_number];
            });
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_supplier_export_forces_authenticated_scope_for_history_and_period(): void
    {
        $augustA = $this->createRequisition($this->august, 'REQ/08/2026/430', 'bidding');
        $this->createQuotation($augustA, $this->supplierA, 'draft', null);

        $septemberA = $this->createRequisition($this->september, 'REQ/09/2026/431', 'bidding');
        $this->createQuotation($septemberA, $this->supplierA, 'accepted', '2026-09-10 10:00:00');

        $augustB = $this->createRequisition($this->august, 'REQ/08/2026/499', 'bidding');
        $this->createQuotation($augustB, $this->supplierB, 'accepted', '2026-08-10 10:00:00');

        $spoofedHistory = new QuotationsExport(
            ['supplier_id' => $this->supplierB->id],
            $this->supplierA->id,
            true
        );
        $historyRows = $spoofedHistory->collection();

        $this->assertCount(2, $historyRows);
        $this->assertSame(['Alpha Steel'], $historyRows->pluck(2)->unique()->values()->all());

        $periodRows = (new QuotationsExport(
            ['period_id' => $this->august->id],
            $this->supplierA->id,
            true
        ))->collection();
        $this->assertCount(1, $periodRows);
        $this->assertSame('REQ/08/2026/430', $periodRows->first()[0]);

        $this->assertCount(0, (new QuotationsExport(
            ['status' => 'unresponded'],
            $this->supplierA->id,
            true
        ))->collection());

        Carbon::setTestNow('2026-08-03 11:12:13');
        Excel::fake();

        try {
            $this->actingAs($this->supplierA)
                ->get(route('supplier.export.quotations', ['supplier_id' => $this->supplierB->id]))
                ->assertOk();

            Excel::assertDownloaded('quotation_supplier_all_20260803_111213.xlsx', function (QuotationsExport $download) {
                return $download->collection()->pluck(2)->unique()->values()->all() === ['Alpha Steel'];
            });
        } finally {
            Carbon::setTestNow();
        }

        $this->actingAs($this->supplierA)
            ->get(route('purchasing.export.quotations'))
            ->assertForbidden();

        $this->actingAs($this->purchasing)
            ->get(route('supplier.export.quotations'))
            ->assertForbidden();

        $this->app['auth']->logout();

        $this->get(route('supplier.export.quotations'))
            ->assertRedirect(route('login'));
    }

    private function createPeriod(string $name, int $month): Period
    {
        return Period::create([
            'name' => $name,
            'month' => $month,
            'year' => 2026,
            'status' => 'open',
            'created_by' => $this->purchasing->id,
        ]);
    }

    private function createRequisition(
        Period $period,
        string $number,
        string $status,
        array $items = []
    ): PurchaseRequisition {
        $pr = PurchaseRequisition::create([
            'period_id' => $period->id,
            'created_by' => $this->purchasing->id,
            'pr_number' => $number,
            'status' => $status,
        ]);

        $items = $items ?: [['material_name' => 'Default Material', 'remark' => null]];

        foreach ($items as $index => $item) {
            $pr->items()->create([
                'hs_code' => '7209.16.'.str_pad((string) $index, 2, '0', STR_PAD_LEFT),
                'material_name' => $item['material_name'],
                'quantity' => 2,
                'shape' => 'Flat',
                'thickness' => 2.5,
                'width' => 1000,
                'length' => 2000,
                'weight_needed' => 100,
                'remark' => $item['remark'],
            ]);
        }

        return $pr;
    }

    private function createQuotation(
        PurchaseRequisition $pr,
        User $supplier,
        string $status,
        ?string $submittedAt,
        bool $useFormulaValues = false
    ): Quotation {
        $quotation = Quotation::create([
            'pr_id' => $pr->id,
            'supplier_id' => $supplier->id,
            'exchange_rate_id' => $this->rate->id,
            'currency' => 'USD',
            'status' => $status,
            'submitted_at' => $submittedAt,
        ]);

        foreach ($pr->items as $index => $prItem) {
            $quotation->items()->create([
                'pr_item_id' => $prItem->id,
                'price_per_kg' => $useFormulaValues && $index === 0 ? 9.99 : 2.5,
                'amount' => $useFormulaValues && $index === 0 ? 123.45 : 500,
                'available_qty' => $useFormulaValues && $index === 0 ? 7 : 2,
                'available_thickness' => 2.5,
                'available_width' => 1000,
                'available_length' => 2000,
                'notes' => $useFormulaValues && $index === 0 ? '=Formula item note' : 'Normal note',
            ]);
        }

        return $quotation;
    }

    private function createPurchaseOrder(
        User $supplier,
        Quotation $quotation,
        string $number,
        string $notes,
        string $status,
        string $estimatedArrival
    ): PurchaseOrder {
        $po = PurchaseOrder::create([
            'supplier_id' => $supplier->id,
            'currency' => 'USD',
            'exchange_rate_id' => $this->rate->id,
            'po_number' => $number,
            'status' => $status,
            'created_by' => $this->purchasing->id,
            'estimated_arrival' => $estimatedArrival,
            'notes' => $notes,
        ]);
        $po->quotations()->attach($quotation->id);

        return $po;
    }

    private function spreadsheetFor(object $export): Spreadsheet
    {
        $contents = Excel::raw($export, ExcelFormat::XLSX);
        $path = tempnam(sys_get_temp_dir(), 'mission-four-export-');

        file_put_contents($path, $contents);

        try {
            return IOFactory::load($path);
        } finally {
            unlink($path);
        }
    }
}
