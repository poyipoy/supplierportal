<?php

namespace Tests\Feature;

use App\Events\ExportProgressUpdated;
use App\Exports\PurchaseOrdersExport;
use App\Exports\SupplierPriceHistoryExport;
use App\Jobs\ProcessExportJob;
use App\Jobs\StreamListExportJob;
use App\Models\ExchangeRate;
use App\Models\ExportJob;
use App\Models\Period;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequisition;
use App\Models\Quotation;
use App\Models\User;
use App\Services\Export\OpenSpoutListWriter;
use App\Services\ExportProgressService;
use App\Services\NotificationService;
use App\Support\Export\ExportOptions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Jobs\QueueExport;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class OpenSpoutStreamingExportTest extends TestCase
{
    use RefreshDatabase;

    private User $purchasing;

    private User $supplier;

    private Period $period;

    protected function beforeRefreshingDatabase(): void
    {
        $this->assertSame('adasi_portal_test', config('database.connections.mysql.database'));
        $this->assertSame('adasi_portal_test', DB::selectOne('SELECT DATABASE() AS db')->db);
    }

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.business_timezone' => 'Asia/Jakarta']);
        $this->purchasing = User::factory()->create(['role' => 'purchasing', 'is_active' => true]);
        $this->supplier = User::factory()->create(['role' => 'supplier', 'name' => 'Stream Supplier', 'is_active' => true]);
        $this->period = Period::create([
            'name' => 'Stream Period',
            'month' => 8,
            'year' => 2026,
            'status' => 'open',
            'created_by' => $this->purchasing->id,
        ]);
    }

    public function test_openspout_xlsx_streams_styled_header_freeze_pane_autofilter_formats_and_sanitized_formulas(): void
    {
        config(['queue.default' => 'sync', 'exports.list_writer' => 'openspout']);
        Storage::fake('private');
        Event::fake([ExportProgressUpdated::class]);

        $rate = ExchangeRate::create([
            'currency' => 'USD',
            'rate_to_idr' => 16000,
            'valid_from' => '2026-08-01',
            'created_by' => $this->purchasing->id,
        ]);
        $pr = PurchaseRequisition::create([
            'period_id' => $this->period->id,
            'created_by' => $this->purchasing->id,
            'pr_number' => 'REQ/STREAM/001',
            'status' => 'bidding',
        ]);
        $item = $pr->items()->create([
            'hs_code' => '7209',
            'material_name' => 'Stream Steel',
            'quantity' => 2,
            'shape' => 'Flat',
            'weight_needed' => 100,
        ]);
        foreach (['=CMD()', '+SUM(A1)', '-1+1', '@IMPORT'] as $index => $payload) {
            $quotation = Quotation::create([
                'pr_id' => $pr->id,
                'supplier_id' => $this->supplier->id,
                'exchange_rate_id' => $rate->id,
                'currency' => 'USD',
                'status' => 'submitted',
                'submitted_at' => '2026-08-15 09:00:00',
            ]);
            $quotation->items()->create([
                'pr_item_id' => $item->id,
                'price_per_kg' => 12.5,
                'amount' => 2500.5,
            ]);

            $po = PurchaseOrder::create([
                'supplier_id' => $this->supplier->id,
                'currency' => 'USD',
                'exchange_rate_id' => $rate->id,
                'po_number' => 'PO/STREAM/00'.($index + 1),
                'status' => 'active',
                'created_by' => $this->purchasing->id,
                'estimated_arrival' => '2026-09-10',
                'notes' => $payload,
            ]);
            $po->quotations()->attach($quotation->id);
        }

        $this->actingAs($this->purchasing)->postJson(route('purchasing.export.purchase-orders'), [
            'options' => [
                'columns' => ['po_number', 'total_amount', 'remark'],
                'format' => 'xlsx',
            ],
        ])->assertAccepted();

        $job = ExportJob::sole();
        $this->assertSame(ExportJob::STATUS_COMPLETED, $job->status);
        $this->assertSame(4, $job->total_rows);
        $this->assertSame(4, $job->processed_rows);
        $this->assertSame(100, $job->progress);
        Storage::disk('private')->assertExists($job->file_path);

        // Temp folder should not retain completed job's temp artifacts.
        $tmpFiles = Storage::disk('private')->allFiles('exports/.tmp');
        $this->assertSame([], $tmpFiles);

        $book = IOFactory::load(Storage::disk('private')->path($job->file_path));
        try {
            $sheet = $book->getActiveSheet();
            $this->assertSame('A2', $sheet->getFreezePane());
            $this->assertSame('A1:C5', $sheet->getAutoFilter()->getRange());
            $this->assertEquals(24, $sheet->getRowDimension(1)->getRowHeight('px'));
            $this->assertEquals(-1, $sheet->getRowDimension(2)->getRowHeight());

            foreach (['A' => 18, 'B' => 18, 'C' => 24] as $column => $width) {
                $this->assertEquals($width, $sheet->getColumnDimension($column)->getWidth());
                $header = $sheet->getStyle($column.'1');
                $this->assertSame('9C4A0F', $header->getFill()->getStartColor()->getRGB());
                $this->assertTrue($header->getFont()->getBold());
                $this->assertSame('FFFFFF', $header->getFont()->getColor()->getRGB());
                $this->assertFalse($header->getAlignment()->getWrapText());
                $this->assertTrue($header->getAlignment()->getShrinkToFit());
            }

            $this->assertSame('#,##0.00', $sheet->getStyle('B2')->getNumberFormat()->getFormatCode());
            $this->assertTrue($sheet->getStyle('C2')->getAlignment()->getWrapText());

            // Ordered DESC by id: PO/STREAM/004 (@IMPORT) down to PO/STREAM/001 (=CMD()).
            $expectedNotes = ["'@IMPORT", "'-1+1", "'+SUM(A1)", "'=CMD()"];
            foreach ($expectedNotes as $offset => $expectedNote) {
                $cell = $sheet->getCell('C'.($offset + 2));
                $this->assertSame($expectedNote, $cell->getValue());
                $this->assertSame('s', $cell->getDataType());
            }
        } finally {
            $book->disconnectWorksheets();
        }
    }

    public function test_openspout_csv_streams_multiple_chunks_with_single_bom_custom_delimiter_and_deterministic_order(): void
    {
        config([
            'queue.default' => 'sync',
            'exports.list_writer' => 'openspout',
            'exports.stream_chunk_size' => 25,
        ]);
        Storage::fake('private');
        Event::fake([ExportProgressUpdated::class]);

        $pr = PurchaseRequisition::create([
            'period_id' => $this->period->id,
            'created_by' => $this->purchasing->id,
            'pr_number' => 'REQ/CSV/MULTI',
            'status' => 'submitted',
        ]);

        $items = [];
        for ($i = 1; $i <= 65; $i++) {
            $items[] = [
                'pr_id' => $pr->id,
                'material_name' => sprintf('Material %03d', $i),
                'quantity' => $i,
                'shape' => 'Flat',
                'weight_needed' => 10,
                'remark' => $i === 1 ? '=DANGER;semi' : 'Note '.$i,
            ];
        }
        $pr->items()->insert($items);

        $this->actingAs($this->purchasing)->postJson(route('purchasing.export.requisitions'), [
            'options' => [
                'columns' => ['pr_number', 'material_name', 'remark'],
                'format' => 'csv',
                'csv_delimiter' => ';',
            ],
        ])->assertAccepted();

        $job = ExportJob::sole();
        $this->assertSame(ExportJob::STATUS_COMPLETED, $job->status);
        $this->assertSame(65, $job->total_rows);
        $this->assertSame(65, $job->processed_rows);
        $this->assertCount(3, $job->processed_chunks);

        $contents = Storage::disk('private')->get($job->file_path);
        $this->assertStringStartsWith("\xEF\xBB\xBF", $contents);
        $this->assertSame(1, substr_count($contents, "\xEF\xBB\xBF"));

        $stream = fopen('php://temp', 'r+');
        try {
            fwrite($stream, substr($contents, 3));
            rewind($stream);
            $rows = [];
            while (($row = fgetcsv($stream, 0, ';', '"', '')) !== false) {
                $rows[] = $row;
            }
        } finally {
            fclose($stream);
        }

        $this->assertCount(66, $rows);
        $this->assertSame([__('exports.headings.pr_number'), __('exports.headings.material_name'), __('exports.headings.remark')], $rows[0]);
        $this->assertSame(['REQ/CSV/MULTI', 'Material 001', "'=DANGER;semi"], $rows[1]);
        $this->assertSame(['REQ/CSV/MULTI', 'Material 065', 'Note 65'], $rows[65]);
    }

    public function test_openspout_stops_mid_chunk_on_cancellation_and_deletes_temp_files(): void
    {
        config([
            'exports.list_writer' => 'openspout',
            'exports.stream_chunk_size' => 10,
        ]);
        Storage::fake('private');
        Event::fake([ExportProgressUpdated::class]);

        for ($i = 1; $i <= 35; $i++) {
            PurchaseOrder::create([
                'supplier_id' => $this->supplier->id,
                'currency' => 'USD',
                'po_number' => sprintf('PO/CANCEL/%03d', $i),
                'status' => 'active',
                'created_by' => $this->purchasing->id,
                'estimated_arrival' => '2026-09-10',
            ]);
        }

        $record = ExportJob::create([
            'user_id' => $this->purchasing->id,
            'label' => 'Cancelled mid-stream',
            'export_class' => PurchaseOrdersExport::class,
            'export_args' => [],
            'file_name' => 'cancelled_mid_stream.xlsx',
            'format' => 'xlsx',
            'export_options' => (new ExportOptions(['po_number', 'currency'], 'xlsx', 'purchasing'))->toArray(),
            'disk' => 'private',
            'status' => ExportJob::STATUS_PROCESSING,
            'progress_stage' => ExportJob::STAGE_GENERATING,
            'progress' => 0,
            'total_rows' => 35,
            'processed_rows' => 0,
            'processed_chunks' => [],
            'file_path' => 'exports/'.$this->purchasing->id.'/cancel-test/cancelled_mid_stream.xlsx',
        ]);

        $notifications = \Mockery::mock(NotificationService::class);
        $notifications->shouldNotReceive('send');

        // Subclass progress service to simulate a user clicking Cancel right after chunk 1.
        $cancellingProgress = new class($notifications, $record->id) extends ExportProgressService
        {
            public int $chunksRecorded = 0;

            public function __construct(NotificationService $notifications, private readonly int $targetId)
            {
                parent::__construct($notifications);
            }

            public function recordChunk(int $exportJobId, string $chunkKey, int $processedRows): void
            {
                parent::recordChunk($exportJobId, $chunkKey, $processedRows);
                $this->chunksRecorded++;
                if ($this->chunksRecorded === 1) {
                    ExportJob::query()->whereKey($this->targetId)->update([
                        'status' => ExportJob::STATUS_CANCELLED,
                        'progress_stage' => ExportJob::STAGE_CANCELLED,
                    ]);
                }
            }
        };

        $export = new PurchaseOrdersExport;
        $export->applyOptions(ExportOptions::fromArray($record->export_options));

        $written = app(OpenSpoutListWriter::class)->write(
            $record,
            $export,
            $record->file_path,
            'private',
            $cancellingProgress,
        );

        $this->assertFalse($written);
        $this->assertSame(1, $cancellingProgress->chunksRecorded);
        Storage::disk('private')->assertMissing($record->file_path);
        $this->assertSame([], Storage::disk('private')->allFiles('exports/.tmp'));
    }

    public function test_list_writer_config_switches_between_openspout_and_maatwebsite_and_keeps_collection_exports_on_excel_queue(): void
    {
        Event::fake([ExportProgressUpdated::class]);
        Queue::fake();

        PurchaseOrder::create([
            'supplier_id' => $this->supplier->id,
            'currency' => 'USD',
            'po_number' => 'PO/SWITCH/001',
            'status' => 'active',
            'created_by' => $this->purchasing->id,
            'estimated_arrival' => '2026-09-10',
        ]);

        $notifications = \Mockery::mock(NotificationService::class);
        $notifications->shouldNotReceive('send');
        $progress = new ExportProgressService($notifications);

        // 1. Default openspout dispatches StreamListExportJob for FromQuery list exports.
        config(['exports.list_writer' => 'openspout']);
        $openSpoutJob = ExportJob::create([
            'user_id' => $this->purchasing->id,
            'label' => 'OpenSpout PO',
            'export_class' => PurchaseOrdersExport::class,
            'export_args' => [],
            'file_name' => 'po_openspout.xlsx',
            'disk' => 'private',
            'status' => ExportJob::STATUS_QUEUED,
        ]);
        (new ProcessExportJob($openSpoutJob->id))->handle($progress);
        Queue::assertPushed(StreamListExportJob::class, 1);
        Queue::assertNotPushed(QueueExport::class);

        // 2. FromCollection list export (SupplierPriceHistoryExport) stays on Excel::queue even under openspout.
        $collectionJob = ExportJob::create([
            'user_id' => $this->supplier->id,
            'label' => 'History Collection',
            'export_class' => SupplierPriceHistoryExport::class,
            'export_args' => [$this->supplier->id, 'monthly', 'Nonexistent Material', null],
            'file_name' => 'history.xlsx',
            'disk' => 'private',
            'status' => ExportJob::STATUS_QUEUED,
        ]);
        (new ProcessExportJob($collectionJob->id))->handle($progress);
        Queue::assertPushed(QueueExport::class, 1);

        // 3. Explicit rollback EXPORTS_LIST_WRITER=maatwebsite routes FromQuery back to Excel::queue.
        config(['exports.list_writer' => 'maatwebsite']);
        $fallbackJob = ExportJob::create([
            'user_id' => $this->purchasing->id,
            'label' => 'Maatwebsite Fallback PO',
            'export_class' => PurchaseOrdersExport::class,
            'export_args' => [],
            'file_name' => 'po_maatwebsite.xlsx',
            'disk' => 'private',
            'status' => ExportJob::STATUS_QUEUED,
        ]);
        (new ProcessExportJob($fallbackJob->id))->handle($progress);
        Queue::assertPushed(QueueExport::class, 2);
        Queue::assertPushed(StreamListExportJob::class, 1);
    }
}
