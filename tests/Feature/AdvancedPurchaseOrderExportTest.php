<?php

namespace Tests\Feature;

use App\Events\ExportProgressUpdated;
use App\Exports\Advanced\ExportColumn;
use App\Exports\PurchaseOrdersExport;
use App\Http\Requests\Export\Filters\PurchaseOrderExportFilters;
use App\Jobs\ProcessExportJob;
use App\Models\ExportJob;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Services\ExportProgressService;
use App\Support\Export\ExportDefinitions;
use App\Support\Export\ExportOptions;
use App\Support\Export\ExportOptionsResolver;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Maatwebsite\Excel\Excel as ExcelFormat;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class AdvancedPurchaseOrderExportTest extends TestCase
{
    use RefreshDatabase;

    private User $purchasing;

    private User $supplier;

    private User $foreignSupplier;

    private PurchaseOrder $po;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.business_timezone' => 'Asia/Jakarta']);
        $this->purchasing = User::factory()->create(['role' => 'purchasing']);
        $this->supplier = User::factory()->create(['role' => 'supplier', 'name' => 'Pilot Supplier']);
        $this->foreignSupplier = User::factory()->create(['role' => 'supplier']);
        $this->po = $this->po($this->supplier, 'PO/08/2026/911', '=Pilot é, "note"');
        $this->po($this->foreignSupplier, 'PO/08/2026/912', 'Foreign');
    }

    private function po(User $owner, string $number, string $note): PurchaseOrder
    {
        $po = PurchaseOrder::create(['supplier_id' => $owner->id, 'currency' => 'USD', 'po_number' => $number, 'status' => 'active', 'created_by' => $this->purchasing->id, 'estimated_arrival' => '2099-01-01', 'notes' => $note]);
        $po->forceFill(['created_at' => '2026-08-15 18:30:00'])->save();

        return $po;
    }

    public static function locales(): array
    {
        return ['en' => ['en'], 'id' => ['id']];
    }

    #[DataProvider('locales')]
    public function test_subset_order_sanitization_selective_loading_and_warmed_serialization(string $locale): void
    {
        app()->setLocale($locale);
        $export = new PurchaseOrdersExport($this->supplier->id);
        $export->setExportLocale($locale);
        $export->applyOptions(new ExportOptions(['remark', 'po_number', 'status'], 'csv', 'supplier'));
        $this->assertSame($locale === 'en' ? ['Remark', 'PO Number', 'Status'] : ['Catatan', 'Nomor PO', 'Status'], $export->headings());
        $this->assertSame([], $export->query()->getEagerLoads());
        $row = $export->query()->firstOrFail();
        $expected = ["'=Pilot é, \"note\"", 'PO/08/2026/911', $locale === 'en' ? 'Active' : 'Aktif'];
        $this->assertSame($expected, $export->map($row));
        $this->assertSame([], $export->columnWidths());
        $export->querySize();
        $restored = unserialize(serialize($export));
        $this->assertSame($expected, $restored->map($restored->query()->firstOrFail()));
        $this->assertSame($export->headings(), $restored->headings());
        $this->assertSame($locale, $restored->preferredLocale());
    }

    public function test_advanced_default_columns_match_legacy_po_contract(): void
    {
        $legacy = new PurchaseOrdersExport($this->supplier->id);
        $advanced = new PurchaseOrdersExport($this->supplier->id);
        $advanced->applyOptions(ExportOptionsResolver::resolve('supplier.po', $this->supplier, []));
        $this->assertSame($legacy->headings(), $advanced->headings());
        $this->assertSame($legacy->collection()->all(), $advanced->collection()->all());
        $this->assertSame($legacy->columnWidths(), $advanced->columnWidths());
    }

    public function test_options_are_validated_again_at_export_boundary(): void
    {
        $export = new PurchaseOrdersExport;
        $this->expectException(InvalidArgumentException::class);
        $export->applyOptions(new ExportOptions(['po_number', 'reviewer_notes'], 'xlsx', 'supplier'));
    }

    public function test_column_audience_metadata_is_conservative(): void
    {
        $internal = new ExportColumn('internal_note', 'remark', fn ($row) => $row->notes, audiences: ['purchasing']);
        $this->assertTrue($internal->visibleTo('purchasing'));
        $this->assertFalse($internal->visibleTo('supplier'));
        $this->assertCount(10, ExportDefinitions::allowedColumns(ExportDefinitions::get('supplier.po')));
    }

    public function test_post_and_legacy_get_force_supplier_and_reject_audience_or_column_spoofing(): void
    {
        Queue::fake();
        $this->actingAs($this->supplier)->postJson(route('supplier.export.purchase-orders'), [
            'supplier_id' => $this->foreignSupplier->hash,
            'options' => ['columns' => ['po_number', 'remark'], 'format' => 'csv'],
        ])->assertAccepted();
        $record = ExportJob::sole();
        $this->assertSame($this->supplier->id, $record->export_args[0]);
        $this->assertSame('supplier', $record->export_options['audience']);
        $this->assertSame('csv', $record->format);
        $this->actingAs($this->supplier)->postJson(route('supplier.export.purchase-orders'), ['options' => ['columns' => ['po_number'], 'audience' => 'purchasing']])->assertUnprocessable();
        foreach ([[], ['status'], ['po_number', 'internal_note'], ['po_number', 'po_number']] as $keys) {
            $this->postJson(route('supplier.export.purchase-orders'), ['options' => ['columns' => $keys]])->assertUnprocessable();
        }
        $this->actingAs($this->purchasing)->getJson(route('purchasing.export.purchase-orders'))->assertAccepted();
        $this->postJson(route('purchasing.export.purchase-orders'), ['supplier_id' => (string) $this->supplier->id, 'options' => ['columns' => ['po_number']]])->assertNotFound();
    }

    public function test_relative_business_dates_and_index_export_parity(): void
    {
        $this->travelTo(new Carbon('2026-08-16 01:00:00', 'UTC'));
        try {
            $request = Request::create('/', 'POST', ['date_range' => ['mode' => 'relative', 'days' => 1], 'options' => ['columns' => ['po_number']]]);
            $filters = PurchaseOrderExportFilters::validated($request);
            $this->assertSame('2026-08-16', $filters['start_date']);
            $this->assertSame('2026-08-16', $filters['end_date']);
            $export = new PurchaseOrdersExport($this->supplier->id, $filters['start_date'], $filters['end_date']);
            $export->applyOptions(new ExportOptions(['po_number'], 'xlsx', 'supplier'));
            $this->assertSame(['PO/08/2026/911'], $export->collection()->pluck(0)->all());
            $this->actingAs($this->supplier)->getJson(route('supplier.purchase-orders.index', ['start_date' => '2026-08-16', 'end_date' => '2026-08-16']), ['X-Requested-With' => 'XMLHttpRequest'])
                ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.po_number_display', 'PO/08/2026/911');
        } finally {
            $this->travelBack();
        }
    }

    public function test_tampered_stored_audience_is_rejected_before_generation(): void
    {
        Queue::fake();
        $this->actingAs($this->supplier)->postJson(route('supplier.export.purchase-orders'), ['options' => ['columns' => ['po_number']]])->assertAccepted();
        $record = ExportJob::sole();
        $record->forceFill(['export_options' => ['columns' => ['po_number'], 'format' => 'xlsx', 'audience' => 'purchasing']])->save();
        $this->expectException(HttpException::class);
        (new ProcessExportJob($record->id))->handle(app(ExportProgressService::class));
    }

    public function test_row_and_concurrent_limits_fail_at_request_boundary(): void
    {
        Queue::fake();
        $this->actingAs($this->purchasing);
        config(['exports.max_rows' => 1]);
        $options = ['columns' => ['po_number']];
        $this->postJson(route('purchasing.export.purchase-orders'), ['options' => $options])->assertUnprocessable();
        $this->assertSame(0, ExportJob::count());
        config(['exports.max_rows' => 100000]);
        for ($i = 0; $i < 5; $i++) {
            $this->postJson(route('purchasing.export.purchase-orders'), ['options' => $options])->assertAccepted();
        }
        $this->postJson(route('purchasing.export.purchase-orders'), ['options' => $options])->assertUnprocessable();
        $this->assertSame(5, ExportJob::count());
        ExportJob::firstOrFail()->update(['status' => 'cancelled']);
        $this->postJson(route('purchasing.export.purchase-orders'), ['options' => $options])->assertAccepted();
    }

    public function test_native_queued_po_csv_has_one_heading_and_bom_across_chunks(): void
    {
        config(['queue.default' => 'sync']);
        Storage::fake('private');
        for ($i = 0; $i < 500; $i++) {
            $this->po($this->supplier, 'PO-PILOT-'.$i, 'é, "quoted"');
        }
        $export = new PurchaseOrdersExport($this->supplier->id);
        $export->applyOptions(new ExportOptions(['po_number', 'remark'], 'csv', 'supplier'));
        $pending = Excel::queue($export, 'exports/po-pilot.csv', 'private', ExcelFormat::CSV)->allOnQueue('exports');
        unset($pending);
        $contents = Storage::disk('private')->get('exports/po-pilot.csv');
        $this->assertSame(1, substr_count($contents, "\xEF\xBB\xBF"));
        $this->assertSame(1, substr_count($contents, 'PO Number'));
        $this->assertCount(502, array_filter(explode("\n", trim($contents))));
        $this->assertStringContainsString("'=Pilot é", $contents);
    }

    public function test_supplier_csv_runs_through_dispatcher_worker_and_finalizer(): void
    {
        config(['queue.default' => 'sync']);
        Storage::fake('private');
        Event::fake([ExportProgressUpdated::class]);
        $this->actingAs($this->supplier)->postJson(route('supplier.export.purchase-orders'), ['options' => ['columns' => ['po_number', 'remark'], 'format' => 'csv']])->assertAccepted();
        $record = ExportJob::sole();
        $this->assertSame('completed', $record->status);
        $this->assertSame(100, $record->progress);
        $this->assertSame(1, $record->total_rows);
        $contents = Storage::disk('private')->get($record->file_path);
        $this->assertSame(1, substr_count($contents, "\xEF\xBB\xBF"));
        $this->assertStringContainsString('PO/08/2026/911', $contents);
        $this->assertStringNotContainsString('PO/08/2026/912', $contents);
    }

    public function test_advanced_xlsx_queue_preserves_normal_widths_and_compact_header(): void
    {
        config(['queue.default' => 'sync']);
        Storage::fake('private');
        Event::fake([ExportProgressUpdated::class]);
        $this->actingAs($this->supplier)->postJson(route('supplier.export.purchase-orders'), [
            'options' => ['columns' => ['remark', 'po_number', 'currency'], 'format' => 'xlsx'],
        ])->assertAccepted();
        $record = ExportJob::sole();
        $this->assertSame('completed', $record->status);
        $book = IOFactory::load(Storage::disk('private')->path($record->file_path));
        try {
            $sheet = $book->getActiveSheet();
            $this->assertSame(['Remark', 'PO Number', 'Currency'], $sheet->toArray()[0]);
            $this->assertSame('PO/08/2026/911', $sheet->getCell('B2')->getValue());
            $this->assertEquals(24, $sheet->getRowDimension(1)->getRowHeight('px'));
            $this->assertEquals(-1, $sheet->getRowDimension(2)->getRowHeight());
            foreach (['A' => 24, 'B' => 18, 'C' => 10] as $column => $width) {
                $this->assertEquals($width, $sheet->getColumnDimension($column)->getWidth());
                $header = $sheet->getStyle($column.'1');
                $this->assertSame('9C4A0F', $header->getFill()->getStartColor()->getRGB());
                $this->assertFalse($header->getAlignment()->getWrapText());
                $this->assertTrue($header->getAlignment()->getShrinkToFit());
            }
            $this->assertTrue($sheet->getStyle('A2')->getAlignment()->getWrapText());
            $this->assertSame(2, $sheet->getHighestDataRow());
        } finally {
            $book->disconnectWorksheets();
        }
    }
}
