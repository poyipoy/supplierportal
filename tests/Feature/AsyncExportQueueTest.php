<?php

namespace Tests\Feature;

use App\Contracts\TracksExportProgress;
use App\Events\ExportProgressUpdated;
use App\Exports\InspectionsExport;
use App\Exports\PrImportTemplateExport;
use App\Exports\PurchaseOrderDetailExport;
use App\Exports\PurchaseOrdersExport;
use App\Exports\PurchaseRequisitionDetailExport;
use App\Exports\QuotationDetailExport;
use App\Exports\QuotationsExport;
use App\Exports\RequisitionsExport;
use App\Exports\SupplierPriceHistoryExport;
use App\Jobs\FinalizeExportJob;
use App\Jobs\Middleware\TrackExportChunkProgress;
use App\Jobs\ProcessExportJob;
use App\Models\ExchangeRate;
use App\Models\ExportJob;
use App\Models\Period;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequisition;
use App\Models\QcInspection;
use App\Models\Quotation;
use App\Models\User;
use App\Services\ExportProgressService;
use App\Services\NotificationService;
use App\Support\ExportDispatcher;
use App\Support\NotificationCategory;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithCustomChunkSize;
use Maatwebsite\Excel\Files\TemporaryFile;
use Maatwebsite\Excel\Jobs\AppendDataToSheet;
use Maatwebsite\Excel\Jobs\QueueExport;
use RuntimeException;
use Tests\TestCase;

class AsyncExportQueueTest extends TestCase
{
    use RefreshDatabase;

    private User $purchasing;

    private User $supplier;

    private User $otherSupplier;

    private User $qc;

    private Period $period;

    private PurchaseRequisition $requisition;

    private Quotation $quotation;

    private PurchaseOrder $purchaseOrder;

    private int $exportSequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->purchasing = User::factory()->create(['role' => 'purchasing', 'is_active' => true]);
        $this->supplier = User::factory()->create(['role' => 'supplier', 'is_active' => true]);
        $this->otherSupplier = User::factory()->create(['role' => 'supplier', 'is_active' => true]);
        $this->qc = User::factory()->create(['role' => 'qc', 'is_active' => true]);

        $this->period = Period::create([
            'name' => 'Async Export Period',
            'month' => 8,
            'year' => 2026,
            'status' => 'open',
            'created_by' => $this->purchasing->id,
        ]);
        $rate = ExchangeRate::create([
            'currency' => 'USD',
            'rate_to_idr' => 16000,
            'valid_from' => '2026-08-01',
            'created_by' => $this->purchasing->id,
        ]);
        $this->requisition = PurchaseRequisition::create([
            'period_id' => $this->period->id,
            'created_by' => $this->purchasing->id,
            'pr_number' => 'REQ/08/2026/801',
            'status' => 'bidding',
        ]);
        $item = $this->requisition->items()->create([
            'hs_code' => '7209.16.00',
            'material_name' => 'Async Export Steel',
            'quantity' => 2,
            'shape' => 'Flat',
            'thickness' => 2.5,
            'width' => 1000,
            'length' => 2000,
            'weight_needed' => 100,
        ]);
        $this->quotation = Quotation::create([
            'pr_id' => $this->requisition->id,
            'supplier_id' => $this->supplier->id,
            'exchange_rate_id' => $rate->id,
            'currency' => 'USD',
            'status' => 'submitted',
            'submitted_at' => '2026-08-15 09:00:00',
        ]);
        $this->quotation->items()->create([
            'pr_item_id' => $item->id,
            'price_per_kg' => 10,
            'amount' => 1000,
        ]);
        $this->purchaseOrder = PurchaseOrder::create([
            'supplier_id' => $this->supplier->id,
            'currency' => 'USD',
            'exchange_rate_id' => $rate->id,
            'po_number' => 'PO/08/2026/801',
            'status' => 'active',
            'created_by' => $this->purchasing->id,
            'estimated_arrival' => '2026-08-31',
        ]);
        $this->purchaseOrder->quotations()->attach($this->quotation->id);
        QcInspection::create([
            'po_id' => $this->purchaseOrder->id,
            'inspected_by' => $this->qc->id,
            'status' => 'ok',
            'inspected_at' => '2026-08-16 09:00:00',
        ]);
    }

    public function test_all_non_template_endpoints_queue_owner_scoped_records_and_return_hashids_for_json(): void
    {
        Queue::fake();
        Carbon::setTestNow('2026-08-18 10:00:00');

        try {
            $this->assertJsonQueued(
                $this->purchasing,
                route('purchasing.export.requisitions', [
                    'period_id' => $this->period->id,
                    'status' => 'bidding',
                    'search' => 'REQ/08',
                ]),
                RequisitionsExport::class,
                [$this->period->id, 'bidding', 'REQ/08'],
            );
            $this->assertJsonQueued(
                $this->purchasing,
                route('purchasing.export.requisitions.detail', $this->requisition),
                PurchaseRequisitionDetailExport::class,
                [$this->requisition->id],
            );
            $this->assertJsonQueued(
                $this->purchasing,
                route('purchasing.export.purchase-orders', [
                    'supplier_id' => $this->supplier,
                    'start_date' => '2026-08-01',
                    'end_date' => '2026-08-31',
                    'po_number' => 'PO/08',
                    'status' => 'active',
                    'search' => 'Steel',
                ]),
                PurchaseOrdersExport::class,
                [$this->supplier->id, '2026-08-01', '2026-08-31', 'PO/08', 'active', 'Steel'],
            );
            $this->assertJsonQueued(
                $this->purchasing,
                route('purchasing.export.purchase-orders.detail', $this->purchaseOrder),
                PurchaseOrderDetailExport::class,
                [$this->purchaseOrder->id],
            );
            $this->assertJsonQueued(
                $this->purchasing,
                route('purchasing.export.quotations', [
                    'pr_number' => $this->requisition->pr_number,
                    'date_from' => '2026-08',
                    'date_to' => '2026-08',
                    'supplier_id' => $this->supplier,
                    'status' => 'submitted',
                    'currency' => 'USD',
                ]),
                QuotationsExport::class,
                [[
                    'pr_number' => $this->requisition->pr_number,
                    'date_from' => '2026-08',
                    'date_to' => '2026-08',
                    'supplier_id' => $this->supplier->id,
                    'status' => 'submitted',
                    'currency' => 'USD',
                ]],
            );
            $this->assertJsonQueued(
                $this->purchasing,
                route('purchasing.export.quotations.detail', $this->quotation),
                QuotationDetailExport::class,
                [$this->quotation->id],
            );
            $this->assertJsonQueued(
                $this->supplier,
                route('supplier.export.quotations', [
                    'period_id' => $this->period->id,
                    'pr_number' => $this->requisition->pr_number,
                    'status' => 'submitted',
                    'search' => 'Async',
                ]),
                QuotationsExport::class,
                [[
                    'period_id' => $this->period->id,
                    'pr_number' => $this->requisition->pr_number,
                    'status' => 'submitted',
                    'search' => 'Async',
                ], $this->supplier->id, true],
            );
            $this->assertJsonQueued(
                $this->supplier,
                route('supplier.export.quotations.detail', $this->quotation),
                QuotationDetailExport::class,
                [$this->quotation->id, $this->supplier->id, false],
            );
            $this->assertJsonQueued(
                $this->supplier,
                route('supplier.export.purchase-orders', [
                    'start_date' => '2026-08-01',
                    'end_date' => '2026-08-31',
                    'po_number' => 'PO/08',
                    'status' => 'active',
                    'search' => 'Async',
                ]),
                PurchaseOrdersExport::class,
                [$this->supplier->id, '2026-08-01', '2026-08-31', 'PO/08', 'active', 'Async'],
            );
            $this->assertJsonQueued(
                $this->supplier,
                route('supplier.export.purchase-orders.detail', $this->purchaseOrder),
                PurchaseOrderDetailExport::class,
                [$this->purchaseOrder->id, $this->supplier->id],
            );
            $this->assertJsonQueued(
                $this->qc,
                route('qc.export.inspections', [
                    'start_date' => '2026-08-01',
                    'end_date' => '2026-08-31',
                    'status' => 'ok',
                ]),
                InspectionsExport::class,
                ['2026-08-01', '2026-08-31', 'ok'],
            );
            $priceHistory = $this->assertJsonQueued(
                $this->supplier,
                route('supplier.price-history.export', [
                    'material_name' => 'Async Export Steel',
                    'period_view' => 'monthly',
                    'range' => '6m',
                    'thickness' => '2.5',
                    'width' => '1000',
                ]),
                SupplierPriceHistoryExport::class,
                [$this->supplier->id, 'monthly', 'Async Export Steel'],
                false,
            );

            $this->assertSame($this->supplier->id, $priceHistory->export_args[0]);
            $this->assertSame('monthly', $priceHistory->export_args[1]);
            $this->assertSame('Async Export Steel', $priceHistory->export_args[2]);
            $this->assertSame('2026-02-01', Carbon::parse($priceHistory->export_args[3])->toDateString());
            $this->assertEquals(['thickness' => '2.5', 'width' => '1000'], $priceHistory->export_args[4]);
        } finally {
            Carbon::setTestNow();
        }

        Queue::assertPushed(ProcessExportJob::class, 12);
    }

    public function test_dispatcher_normalizes_positional_arguments_sanitizes_filenames_and_excludes_templates(): void
    {
        Queue::fake();
        $this->actingAs($this->purchasing);

        $record = ExportDispatcher::dispatch(
            'Safe export',
            RequisitionsExport::class,
            ['first' => null, 'second' => 'submitted', 'third' => 'REQ'],
            '../unsafe report.csv',
        );

        $this->assertSame([null, 'submitted', 'REQ'], $record->export_args);
        $this->assertSame('unsafe_report.csv.xlsx', $record->file_name);
        $this->assertSame(ExportJob::STATUS_QUEUED, $record->status);
        Queue::assertPushed(ProcessExportJob::class, fn (ProcessExportJob $job) => $job->exportJobId === $record->id
            && $job->queue === 'exports');

        $this->expectException(InvalidArgumentException::class);
        ExportDispatcher::dispatch('Template', PrImportTemplateExport::class, [], 'template.xlsx');
    }

    public function test_qc_and_price_history_validation_happens_before_queue_dispatch(): void
    {
        Queue::fake();

        $this->actingAs($this->qc)
            ->getJson(route('qc.export.inspections', [
                'start_date' => '2026-08-31',
                'end_date' => '2026-08-01',
                'status' => 'invalid',
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['end_date', 'status']);
        $this->actingAs($this->supplier)
            ->getJson(route('supplier.price-history.export', [
                'period_view' => 'weekly',
                'range' => 'invalid',
                'thickness' => 'not-a-number',
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['material_name', 'period_view', 'range', 'thickness']);

        $this->assertDatabaseCount('export_jobs', 0);
        Queue::assertNothingPushed();
    }

    public function test_worker_stores_files_and_notifies_completed_or_terminal_failed_exports(): void
    {
        Event::fake([ExportProgressUpdated::class]);
        Queue::fake();
        Storage::fake('private');
        $sentNotifications = [];
        $notifications = \Mockery::mock(NotificationService::class);
        $notifications->shouldReceive('send')
            ->twice()
            ->andReturnUsing(function (...$arguments) use (&$sentNotifications): void {
                $sentNotifications[] = $arguments;
            });
        $this->app->instance(NotificationService::class, $notifications);
        $progress = new ExportProgressService($notifications);

        $completed = $this->createExportJob($this->purchasing);
        (new ProcessExportJob($completed->id))->handle($progress);

        $completed->refresh();
        $this->assertSame(ExportJob::STATUS_PROCESSING, $completed->status);
        $this->assertSame(ExportJob::STAGE_GENERATING, $completed->progress_stage);
        $this->assertGreaterThan(0, $completed->total_rows);
        $this->assertSame(0, $completed->processed_rows);
        $this->assertSame(0, $completed->progress);
        Queue::assertPushed(QueueExport::class, function (QueueExport $job): bool {
            $chain = collect($job->chained)->map(fn (string $serialized) => unserialize($serialized));

            return $job->queue === 'exports'
                && $chain->contains(fn (object $chainedJob) => $chainedJob instanceof FinalizeExportJob);
        });

        $progress->recordChunk($completed->id, 'query:0:1', 500);
        $completed->refresh();
        $this->assertSame($completed->total_rows, $completed->processed_rows);
        $this->assertSame(100, $completed->progress);
        $this->assertSame(ExportJob::STAGE_FINALIZING, $completed->progress_stage);

        Storage::disk('private')->put($completed->file_path, 'xlsx contents');
        (new FinalizeExportJob($completed->id))->handle($progress);

        $completed->refresh();
        $this->assertSame(ExportJob::STATUS_COMPLETED, $completed->status);
        $this->assertTrue($completed->isDownloadable());
        $this->assertTrue($completed->expires_at->isFuture());
        $this->assertSame(ExportJob::STAGE_COMPLETED, $completed->progress_stage);
        $this->assertSame(100, $completed->progress);

        $tracked = $this->createExportJob($this->purchasing);
        $trackedPath = 'exports/'.$this->purchasing->id.'/'.$tracked->id.'/'.$tracked->file_name;
        $progress->prepare($tracked, $trackedPath);
        $progress->startGenerating($tracked, 1200);
        $progress->recordChunk($tracked->id, 'query:0:1', 500);
        $this->assertSame(41, $tracked->fresh()->progress);
        $progress->recordChunk($tracked->id, 'query:0:1', 500);
        $this->assertSame(500, $tracked->fresh()->processed_rows);
        $progress->recordChunk($tracked->id, 'query:0:2', 500);
        $this->assertSame(83, $tracked->fresh()->progress);
        $progress->recordChunk($tracked->id, 'query:0:3', 500);
        $this->assertSame(1200, $tracked->fresh()->processed_rows);
        $this->assertSame(100, $tracked->fresh()->progress);

        $failed = $this->createExportJob($this->purchasing);
        $failedPath = 'exports/'.$this->purchasing->id.'/'.$failed->id.'/'.$failed->file_name;
        $progress->prepare($failed, $failedPath);
        $progress->startGenerating($failed, 500);
        Storage::disk('private')->put($failedPath, 'partial file');
        $progress->fail($failed->id, new RuntimeException(str_repeat('x', 600)));

        $failed->refresh();
        $this->assertSame(ExportJob::STATUS_FAILED, $failed->status);
        $this->assertSame(ExportJob::STAGE_FAILED, $failed->progress_stage);
        $this->assertNull($failed->progress);
        $this->assertLessThanOrEqual(500, strlen((string) $failed->error_message));
        $this->assertNull($failed->file_path);
        Storage::disk('private')->assertMissing($failedPath);
        $this->assertCount(2, $sentNotifications);
        $this->assertSame('export.completed', $sentNotifications[0][1]);
        $this->assertSame('Export Completed', $sentNotifications[0][3]);
        $this->assertSame('Export :label is ready to download.', $sentNotifications[0][4]);
        $this->assertSame(NotificationCategory::OTHER, $sentNotifications[0][7]['category']);
        $this->assertSame($completed->getRouteKey(), $sentNotifications[0][7]['export_job_id']);
        $this->assertSame('export.failed', $sentNotifications[1][1]);
        $this->assertSame('Export Failed', $sentNotifications[1][3]);
        $this->assertSame('The export could not be processed. Please try again.', $sentNotifications[1][4]);
        $this->assertSame(NotificationCategory::OTHER, $sentNotifications[1][7]['category']);
        Event::assertDispatched(ExportProgressUpdated::class, fn (ExportProgressUpdated $event) => $event->exportJobId === $completed->getRouteKey()
            && $event->stage === ExportJob::STAGE_GENERATING
            && $event->progress === 0
        );
        Event::assertDispatched(ExportProgressUpdated::class, fn (ExportProgressUpdated $event) => $event->exportJobId === $completed->getRouteKey()
            && $event->stage === ExportJob::STAGE_COMPLETED
            && $event->progress === 100
            && $event->processedRows === $event->totalRows
        );
        Event::assertDispatched(ExportProgressUpdated::class, fn (ExportProgressUpdated $event) => $event->exportJobId === $failed->getRouteKey()
            && $event->stage === ExportJob::STAGE_FAILED
        );
    }

    public function test_large_exports_are_query_chunked_and_all_async_exports_use_fixed_widths(): void
    {
        $largeExports = [
            new RequisitionsExport,
            new PurchaseOrdersExport,
            new QuotationsExport,
            new InspectionsExport,
        ];

        foreach ($largeExports as $export) {
            $this->assertInstanceOf(FromQuery::class, $export);
            $this->assertInstanceOf(WithCustomChunkSize::class, $export);
            $this->assertSame(500, $export->chunkSize());
        }

        $allAsyncExports = [
            ...$largeExports,
            new PurchaseRequisitionDetailExport($this->requisition->id),
            new QuotationDetailExport($this->quotation->id),
            new PurchaseOrderDetailExport($this->purchaseOrder->id),
            new SupplierPriceHistoryExport($this->supplier->id, 'monthly', 'Async Export Steel', null),
        ];

        foreach ($allAsyncExports as $export) {
            $this->assertInstanceOf(TracksExportProgress::class, $export);
            $this->assertInstanceOf(WithColumnWidths::class, $export);
            $this->assertNotEmpty($export->columnWidths());
        }
    }

    public function test_chunked_export_pipeline_generates_a_downloadable_file_end_to_end(): void
    {
        Event::fake([ExportProgressUpdated::class]);
        Storage::fake('private');
        config(['queue.default' => 'sync']);

        $notifications = \Mockery::mock(NotificationService::class);
        $notifications->shouldReceive('send')
            ->once()
            ->withArgs(fn (...$arguments) => $arguments[1] === 'export.completed');
        $this->app->instance(NotificationService::class, $notifications);

        $record = $this->createExportJob($this->purchasing);
        (new ProcessExportJob($record->id))->handle(new ExportProgressService($notifications));

        $record->refresh();
        $this->assertSame(ExportJob::STATUS_COMPLETED, $record->status);
        $this->assertSame(ExportJob::STAGE_COMPLETED, $record->progress_stage);
        $this->assertSame($record->total_rows, $record->processed_rows);
        $this->assertSame(100, $record->progress);
        $this->assertTrue($record->isDownloadable());
        Storage::disk('private')->assertExists($record->file_path);
    }

    public function test_chunk_middleware_records_only_rows_from_successful_chunks(): void
    {
        Event::fake([ExportProgressUpdated::class]);
        $notifications = \Mockery::mock(NotificationService::class);
        $notifications->shouldNotReceive('send');
        $this->app->instance(NotificationService::class, $notifications);
        $progress = new ExportProgressService($notifications);
        $record = $this->createExportJob($this->purchasing);
        $path = 'exports/'.$this->purchasing->id.'/'.$record->id.'/'.$record->file_name;
        $progress->prepare($record, $path);
        $progress->startGenerating($record, 3);

        $temporaryFile = \Mockery::mock(TemporaryFile::class);
        $export = new RequisitionsExport;
        $firstChunk = new AppendDataToSheet($export, $temporaryFile, 'Xlsx', 0, [['one'], ['two']]);
        $secondChunk = new AppendDataToSheet($export, $temporaryFile, 'Xlsx', 0, [['three']]);
        $middleware = new TrackExportChunkProgress($record->id);

        $middleware->handle($firstChunk, fn (): null => null);
        $record->refresh();
        $this->assertSame(2, $record->processed_rows);
        $this->assertSame(66, $record->progress);

        try {
            $middleware->handle($secondChunk, fn () => throw new RuntimeException('Chunk failed.'));
            $this->fail('A failed chunk must propagate its exception.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Chunk failed.', $exception->getMessage());
        }

        $this->assertSame(2, $record->fresh()->processed_rows);

        $middleware->handle($secondChunk, fn (): null => null);
        $record->refresh();
        $this->assertSame(3, $record->processed_rows);
        $this->assertSame(100, $record->progress);
        $this->assertSame(ExportJob::STAGE_FINALIZING, $record->progress_stage);
    }

    public function test_large_export_mappings_do_not_lazy_load_relations(): void
    {
        $secondItem = $this->requisition->items()->create([
            'hs_code' => '7209.17.00',
            'material_name' => 'Second Async Steel',
            'quantity' => 1,
            'shape' => 'Round',
            'd_outer' => 20,
            'length' => 1000,
            'weight_needed' => 25,
        ]);
        $this->quotation->items()->create([
            'pr_item_id' => $secondItem->id,
            'price_per_kg' => 12,
            'amount' => 300,
        ]);
        $secondPo = PurchaseOrder::create([
            'supplier_id' => $this->supplier->id,
            'currency' => 'USD',
            'po_number' => 'PO/08/2026/802',
            'status' => 'active',
            'created_by' => $this->purchasing->id,
            'estimated_arrival' => '2026-09-05',
        ]);
        $secondPo->quotations()->attach($this->quotation->id);

        $inspection = QcInspection::query()->firstOrFail();
        $inspection->items()->create(['pr_item_id' => $this->requisition->items()->firstOrFail()->id, 'status' => 'ok']);
        $inspection->items()->create(['pr_item_id' => $secondItem->id, 'status' => 'ok']);

        Model::preventLazyLoading();

        try {
            $this->assertCount(2, (new RequisitionsExport)->collection());
            $this->assertCount(2, (new QuotationsExport)->collection());
            $this->assertCount(2, (new PurchaseOrdersExport)->collection());
            $this->assertCount(2, (new InspectionsExport)->collection());
        } finally {
            Model::preventLazyLoading(false);
        }
    }

    public function test_export_index_download_and_cleanup_are_owner_only_and_enforce_file_rules(): void
    {
        Storage::fake('private');
        $downloadable = $this->createExportJob($this->purchasing, [
            'status' => ExportJob::STATUS_COMPLETED,
            'progress_stage' => ExportJob::STAGE_COMPLETED,
            'progress' => 100,
            'file_path' => 'exports/'.$this->purchasing->id.'/download/report.xlsx',
            'file_name' => 'report.xlsx',
            'completed_at' => now(),
            'expires_at' => now()->addDay(),
        ]);
        Storage::disk('private')->put($downloadable->file_path, 'xlsx contents');

        for ($index = 0; $index < 20; $index++) {
            $job = $this->createExportJob($this->purchasing, ['label' => 'Pending '.$index]);
            $job->forceFill(['created_at' => now()->subMinutes(2)])->save();
        }
        $foreign = $this->createExportJob($this->otherSupplier, ['label' => 'Other supplier export']);
        $queued = ExportJob::query()->where('user_id', $this->purchasing->id)->where('status', ExportJob::STATUS_QUEUED)->firstOrFail();
        $failed = $this->createExportJob($this->purchasing, ['status' => ExportJob::STATUS_FAILED, 'expires_at' => now()->addDay()]);
        $expired = $this->createExportJob($this->purchasing, [
            'status' => ExportJob::STATUS_COMPLETED,
            'file_path' => 'exports/'.$this->purchasing->id.'/expired/report.xlsx',
            'expires_at' => now()->subMinute(),
        ]);
        Storage::disk('private')->put($expired->file_path, 'expired contents');
        $missing = $this->createExportJob($this->purchasing, [
            'status' => ExportJob::STATUS_COMPLETED,
            'file_path' => 'exports/'.$this->purchasing->id.'/missing/report.xlsx',
            'expires_at' => now()->addDay(),
        ]);
        $unsafe = $this->createExportJob($this->purchasing, [
            'status' => ExportJob::STATUS_COMPLETED,
            'file_path' => 'exports/'.$this->purchasing->id.'/../outside/report.xlsx',
            'expires_at' => now()->addDay(),
        ]);
        $latestDownloadable = $this->createExportJob($this->purchasing, [
            'status' => ExportJob::STATUS_COMPLETED,
            'file_path' => 'exports/'.$this->purchasing->id.'/latest/report.xlsx',
            'file_name' => 'latest-report.xlsx',
            'completed_at' => now(),
            'expires_at' => now()->addDay(),
        ]);
        Storage::disk('private')->put($latestDownloadable->file_path, 'latest xlsx contents');

        $this->actingAs($this->purchasing)
            ->get(route('exports.index'))
            ->assertOk()
            ->assertSeeText('Export History');
        $this->actingAs($this->purchasing)
            ->getJson(route('exports.index'))
            ->assertOk()
            ->assertJsonCount(20, 'data')
            ->assertJsonPath('has_pending', true)
            ->assertJsonFragment([
                'id' => $latestDownloadable->getRouteKey(),
                'download_url' => route('exports.download', $latestDownloadable, absolute: false),
            ])
            ->assertJsonMissing(['id' => $foreign->getRouteKey()]);

        $this->actingAs($this->purchasing)
            ->get(route('exports.download', $downloadable))
            ->assertDownload('report.xlsx');
        $this->actingAs($this->purchasing)
            ->getJson(route('exports.status', $downloadable))
            ->assertOk()
            ->assertJson([
                'id' => $downloadable->getRouteKey(),
                'status' => ExportJob::STATUS_COMPLETED,
                'stage' => ExportJob::STAGE_COMPLETED,
                'progress' => 100,
                'message' => 'The export is complete and ready to download.',
                'file_name' => 'report.xlsx',
                'download_url' => route('exports.download', $downloadable, absolute: false),
            ]);
        $this->actingAs($this->otherSupplier)
            ->get(route('exports.download', $downloadable))
            ->assertForbidden();
        $this->actingAs($this->otherSupplier)
            ->getJson(route('exports.status', $downloadable))
            ->assertForbidden();

        $this->actingAs($this->purchasing)
            ->getJson(route('exports.status', $queued))
            ->assertOk()
            ->assertJsonPath('status', ExportJob::STATUS_QUEUED)
            ->assertJsonPath('stage', ExportJob::STAGE_QUEUED)
            ->assertJsonPath('progress', null)
            ->assertJsonPath('file_name', null)
            ->assertJsonPath('download_url', null);
        $this->actingAs($this->purchasing)
            ->getJson(route('exports.status', $failed))
            ->assertOk()
            ->assertJsonPath('message', 'The export could not be processed. Please try again.')
            ->assertJsonPath('download_url', null);
        $this->actingAs($this->purchasing)
            ->getJson(route('exports.status', $expired))
            ->assertOk()
            ->assertJsonPath('status', ExportJob::STATUS_COMPLETED)
            ->assertJsonPath('download_url', null);

        foreach ([$queued, $failed, $expired, $missing, $unsafe] as $unavailable) {
            $this->actingAs($this->purchasing)
                ->get(route('exports.download', $unavailable))
                ->assertNotFound();
        }
        $this->actingAs($this->purchasing)->get('/exports/'.$downloadable->id.'/download')->assertNotFound();
        $this->actingAs($this->purchasing)->get('/exports/invalid-hash/download')->assertNotFound();
        $this->actingAs($this->purchasing)->get('/exports/'.$downloadable->id.'/status')->assertNotFound();
        $this->actingAs($this->purchasing)->get('/exports/invalid-hash/status')->assertNotFound();

        $expiredForCleanup = $this->createExportJob($this->purchasing, [
            'status' => ExportJob::STATUS_COMPLETED,
            'file_path' => 'exports/'.$this->purchasing->id.'/cleanup/expired.xlsx',
            'expires_at' => now()->subMinute(),
        ]);
        Storage::disk('private')->put($expiredForCleanup->file_path, 'expired cleanup file');
        $future = $this->createExportJob($this->purchasing, [
            'status' => ExportJob::STATUS_COMPLETED,
            'file_path' => 'exports/'.$this->purchasing->id.'/cleanup/future.xlsx',
            'expires_at' => now()->addDay(),
        ]);
        Storage::disk('private')->put($future->file_path, 'future cleanup file');
        $unsafeExpired = $this->createExportJob($this->purchasing, [
            'status' => ExportJob::STATUS_FAILED,
            'file_path' => 'not-exports/retained.xlsx',
            'expires_at' => now()->subMinute(),
        ]);

        $this->artisan('exports:cleanup')->assertSuccessful();

        $this->assertDatabaseMissing('export_jobs', ['id' => $expiredForCleanup->id]);
        Storage::disk('private')->assertMissing($expiredForCleanup->file_path);
        $this->assertDatabaseHas('export_jobs', ['id' => $future->id]);
        Storage::disk('private')->assertExists($future->file_path);
        $this->assertDatabaseHas('export_jobs', ['id' => $unsafeExpired->id]);
    }

    private function assertJsonQueued(
        User $user,
        string $url,
        string $exportClass,
        array $expectedArgs,
        bool $assertExactArgs = true,
    ): ExportJob {
        $response = $this->actingAs($user)->getJson($url)->assertAccepted();
        $record = ExportJob::query()->orderByDesc('id')->firstOrFail();

        $this->assertSame($user->id, $record->user_id);
        $this->assertSame($exportClass, $record->export_class);
        $this->assertSame(ExportJob::STATUS_QUEUED, $record->status);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9._-]+\\.xlsx$/', $record->file_name);
        $this->assertSame($record->getRouteKey(), $response->json('export_job_id'));
        $this->assertNotSame((string) $record->id, $response->json('export_job_id'));
        $this->assertSame(route('exports.index', absolute: false), $response->json('exports_url'));
        $this->assertSame(route('exports.status', $record, absolute: false), $response->json('status_url'));
        Queue::assertPushed(ProcessExportJob::class, fn (ProcessExportJob $job) => $job->exportJobId === $record->id
            && $job->queue === 'exports');

        if ($assertExactArgs) {
            $this->assertEquals($expectedArgs, $record->export_args);
        } else {
            $this->assertSame($expectedArgs, array_slice($record->export_args, 0, count($expectedArgs)));
        }

        return $record;
    }

    private function createExportJob(User $owner, array $attributes = []): ExportJob
    {
        $this->exportSequence++;

        return ExportJob::create(array_merge([
            'user_id' => $owner->id,
            'label' => 'Test export '.$this->exportSequence,
            'export_class' => RequisitionsExport::class,
            'export_args' => [null, null, null],
            'file_name' => 'test_export_'.$this->exportSequence.'.xlsx',
            'disk' => 'private',
            'status' => ExportJob::STATUS_QUEUED,
        ], $attributes));
    }
}
