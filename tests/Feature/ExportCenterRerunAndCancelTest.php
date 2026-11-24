<?php

namespace Tests\Feature;

use App\Events\ExportProgressUpdated;
use App\Exports\LocalInvoicesExport;
use App\Exports\PurchaseOrdersExport;
use App\Exports\RequisitionsExport;
use App\Models\ExportJob;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Support\Export\ExportOptions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Excel as ExcelFormat;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class ExportCenterRerunAndCancelTest extends TestCase
{
    use RefreshDatabase;

    private User $purchasing;

    private User $otherPurchasing;

    private User $finance;

    private User $supplier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->purchasing = User::factory()->create(['role' => 'purchasing', 'is_active' => true]);
        $this->otherPurchasing = User::factory()->create(['role' => 'purchasing', 'is_active' => true]);
        $this->finance = User::factory()->create(['role' => 'finance', 'is_active' => true]);
        $this->supplier = User::factory()->create(['role' => 'supplier', 'is_active' => true]);
    }

    public function test_export_center_filters_progress_and_safe_failure_message(): void
    {
        $processing = ExportJob::create([
            'user_id' => $this->purchasing->id,
            'label' => 'Active PO Export',
            'export_class' => PurchaseOrdersExport::class,
            'export_args' => [null, null, null, null, null, null],
            'file_name' => 'active-po.xlsx',
            'format' => 'xlsx',
            'disk' => 'private',
            'status' => ExportJob::STATUS_PROCESSING,
            'progress_stage' => ExportJob::STAGE_GENERATING,
            'progress' => 45,
            'processed_rows' => 450,
            'total_rows' => 1000,
        ]);

        $failed = ExportJob::create([
            'user_id' => $this->purchasing->id,
            'label' => 'Failed CSV Export',
            'export_class' => PurchaseOrdersExport::class,
            'export_args' => [null, null, null, null, null, null],
            'file_name' => 'failed-po.csv',
            'format' => 'csv',
            'export_options' => ['columns' => ['po_number'], 'format' => 'csv', 'audience' => 'purchasing'],
            'disk' => 'private',
            'status' => ExportJob::STATUS_FAILED,
            'progress_stage' => ExportJob::STAGE_FAILED,
            'error_message' => 'SQLSTATE[HY000]: secret stack trace /var/www/app/secret.php',
        ]);

        $response = $this->actingAs($this->purchasing)->get(route('exports.index'))->assertOk();
        $response->assertSee('data-export-progress-block', false)
            ->assertSee('45%')
            ->assertSee('450 / 1,000 rows')
            ->assertSee(route('exports.cancel', $processing, absolute: false), false)
            ->assertSee(route('exports.rerun', $failed, absolute: false), false)
            ->assertSeeText(__('exports.progress.failed'))
            ->assertDontSee('SQLSTATE')
            ->assertDontSee('/var/www/app/secret.php');

        $this->actingAs($this->purchasing)
            ->getJson(route('exports.index', ['status' => 'failed', 'format' => 'csv']))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $failed->getRouteKey())
            ->assertJsonPath('data.0.failure_message', __('exports.progress.failed'))
            ->assertJsonPath('has_pending', false);

        $this->actingAs($this->purchasing)
            ->getJson(route('exports.index', ['status' => 'processing', 'format' => 'xlsx']))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $processing->getRouteKey())
            ->assertJsonPath('data.0.progress', 45)
            ->assertJsonPath('data.0.processed_rows', 450)
            ->assertJsonPath('data.0.total_rows', 1000)
            ->assertJsonPath('has_pending', true);
    }

    public function test_pending_job_can_be_cancelled_and_terminal_jobs_can_be_rerun(): void
    {
        Queue::fake();
        Event::fake([ExportProgressUpdated::class]);

        $pending = ExportJob::create([
            'user_id' => $this->purchasing->id,
            'label' => 'Pending Export',
            'export_class' => RequisitionsExport::class,
            'export_args' => [null, null, null],
            'file_name' => 'pending.xlsx',
            'format' => 'xlsx',
            'disk' => 'private',
            'status' => ExportJob::STATUS_QUEUED,
            'progress_stage' => ExportJob::STAGE_QUEUED,
        ]);

        // Pending job cannot be re-run while active
        $this->actingAs($this->purchasing)
            ->postJson(route('exports.rerun', $pending))
            ->assertStatus(409);

        // Cancel pending job
        $this->actingAs($this->purchasing)
            ->postJson(route('exports.cancel', $pending))
            ->assertOk()
            ->assertJsonPath('status', ExportJob::STATUS_CANCELLED)
            ->assertJsonPath('rerun_url', route('exports.rerun', $pending, absolute: false));

        // Now cancelled job can be re-run
        $rerunResponse = $this->actingAs($this->purchasing)
            ->postJson(route('exports.rerun', $pending))
            ->assertAccepted()
            ->assertJsonPath('item.status', ExportJob::STATUS_QUEUED);

        $this->assertSame(2, ExportJob::count());
        $this->assertNotSame($pending->getRouteKey(), $rerunResponse->json('export_job_id'));

        // Expired completed job with legacy export_options (without csv_delimiter) can be re-run
        $expired = ExportJob::create([
            'user_id' => $this->purchasing->id,
            'label' => 'Expired CSV Export',
            'export_class' => PurchaseOrdersExport::class,
            'export_args' => [null, null, null, null, null, null],
            'file_name' => 'expired.csv',
            'format' => 'csv',
            'export_options' => ['columns' => ['po_number', 'status'], 'format' => 'csv', 'audience' => 'purchasing'],
            'disk' => 'private',
            'status' => ExportJob::STATUS_COMPLETED,
            'progress_stage' => ExportJob::STAGE_COMPLETED,
            'completed_at' => now()->subDays(5),
            'expires_at' => now()->subDay(),
        ]);

        $this->actingAs($this->purchasing)
            ->postJson(route('exports.rerun', $expired))
            ->assertAccepted()
            ->assertJsonPath('item.format', 'csv');

        $cloned = ExportJob::latest('id')->firstOrFail();
        $this->assertSame(',', $cloned->export_options['csv_delimiter']);
    }

    public function test_rerun_enforces_ownership_domain_audience_and_limits(): void
    {
        Queue::fake();

        $po = PurchaseOrder::create([
            'supplier_id' => $this->supplier->id,
            'currency' => 'USD',
            'po_number' => 'PO/11/2026/001',
            'status' => 'active',
            'created_by' => $this->purchasing->id,
            'estimated_arrival' => '2099-01-01',
        ]);
        PurchaseOrder::create([
            'supplier_id' => $this->supplier->id,
            'currency' => 'USD',
            'po_number' => 'PO/11/2026/002',
            'status' => 'active',
            'created_by' => $this->purchasing->id,
            'estimated_arrival' => '2099-01-01',
        ]);

        $purchasingJob = ExportJob::create([
            'user_id' => $this->purchasing->id,
            'label' => 'PO Summary',
            'export_class' => PurchaseOrdersExport::class,
            'export_args' => [null, null, null, null, null, null],
            'file_name' => 'po.xlsx',
            'format' => 'xlsx',
            'export_options' => ['columns' => ['po_number'], 'format' => 'xlsx', 'audience' => 'purchasing'],
            'disk' => 'private',
            'status' => ExportJob::STATUS_FAILED,
            'progress_stage' => ExportJob::STAGE_FAILED,
        ]);

        // Anti-IDOR: another purchasing user cannot rerun
        $this->actingAs($this->otherPurchasing)
            ->postJson(route('exports.rerun', $purchasingJob))
            ->assertForbidden();

        // Cross-domain: local operator (finance) cannot rerun import export
        $this->actingAs($this->finance)
            ->postJson(route('exports.rerun', $purchasingJob))
            ->assertForbidden();

        // Cross-domain: purchasing cannot rerun local invoice job even if owned (e.g. role changed)
        $localJob = ExportJob::create([
            'user_id' => $this->purchasing->id,
            'label' => 'Local Invoice',
            'export_class' => LocalInvoicesExport::class,
            'export_args' => [$this->purchasing->id, [], false],
            'file_name' => 'local.xlsx',
            'format' => 'xlsx',
            'disk' => 'private',
            'status' => ExportJob::STATUS_FAILED,
            'progress_stage' => ExportJob::STAGE_FAILED,
        ]);
        $this->actingAs($this->purchasing)
            ->postJson(route('exports.rerun', $localJob))
            ->assertForbidden();

        // Stored tampered audience rejected
        $tampered = ExportJob::create([
            'user_id' => $this->supplier->id,
            'label' => 'Tampered',
            'export_class' => PurchaseOrdersExport::class,
            'export_args' => [$this->supplier->id, null, null, null, null, null],
            'file_name' => 'tampered.xlsx',
            'format' => 'xlsx',
            'export_options' => ['columns' => ['po_number'], 'format' => 'xlsx', 'audience' => 'purchasing'],
            'disk' => 'private',
            'status' => ExportJob::STATUS_FAILED,
            'progress_stage' => ExportJob::STAGE_FAILED,
        ]);
        $this->actingAs($this->supplier)
            ->postJson(route('exports.rerun', $tampered))
            ->assertForbidden();

        // Enforce max_rows on rerun
        config(['exports.max_rows' => 1]);
        $this->actingAs($this->purchasing)
            ->postJson(route('exports.rerun', $purchasingJob))
            ->assertUnprocessable();

        // Enforce max_concurrent_per_user on rerun
        config(['exports.max_rows' => 100000, 'exports.max_concurrent_per_user' => 1]);
        $this->actingAs($this->purchasing)
            ->postJson(route('exports.rerun', $purchasingJob))
            ->assertAccepted();
        $this->actingAs($this->purchasing)
            ->postJson(route('exports.rerun', $purchasingJob))
            ->assertUnprocessable();
    }

    public function test_csv_semicolon_delimiter_and_xlsx_freeze_autofilter_number_formats(): void
    {
        Storage::fake('private');

        PurchaseOrder::create([
            'supplier_id' => $this->supplier->id,
            'currency' => 'USD',
            'po_number' => 'PO/11/2026/009',
            'status' => 'active',
            'created_by' => $this->purchasing->id,
            'estimated_arrival' => '2099-01-01',
            'notes' => 'Normal note',
        ]);

        // Backward-compatible legacy ExportOptions::fromArray without csv_delimiter
        $legacyOptions = ExportOptions::fromArray(['columns' => ['po_number'], 'format' => 'csv', 'audience' => 'purchasing']);
        $this->assertSame(',', $legacyOptions->csvDelimiter);

        // Semicolon delimiter via HTTP request
        Queue::fake();
        $this->actingAs($this->purchasing)->postJson(route('purchasing.export.purchase-orders'), [
            'options' => ['columns' => ['po_number', 'currency'], 'format' => 'csv', 'csv_delimiter' => ';'],
        ])->assertAccepted();
        $job = ExportJob::latest('id')->firstOrFail();
        $this->assertSame(';', $job->export_options['csv_delimiter']);

        // Reject invalid delimiter
        $this->actingAs($this->purchasing)->postJson(route('purchasing.export.purchase-orders'), [
            'options' => ['columns' => ['po_number'], 'format' => 'csv', 'csv_delimiter' => '|'],
        ])->assertUnprocessable();

        // Actual CSV generation with semicolon delimiter & UTF-8 BOM
        $csvExport = new PurchaseOrdersExport;
        $csvExport->applyOptions(new ExportOptions(['po_number', 'currency'], 'csv', 'purchasing', ';'));
        $rawCsv = Excel::raw($csvExport, ExcelFormat::CSV);
        $this->assertStringStartsWith("\xEF\xBB\xBF", $rawCsv);
        $lines = array_values(array_filter(preg_split('/\r\n|\n/', substr($rawCsv, 3))));
        $this->assertCount(2, $lines);
        $this->assertStringContainsString(';', $lines[0]);
        $this->assertSame(['Purchase Order', 'Currency'], str_getcsv($lines[0], ';', '"', ''));
        $this->assertSame(['PO/11/2026/009', 'USD'], str_getcsv($lines[1], ';', '"', ''));

        // Actual XLSX ergonomics: freezePane A2, AutoFilter A1:C1, Money number format
        $xlsxExport = new PurchaseOrdersExport;
        $xlsxExport->applyOptions(new ExportOptions(['po_number', 'total_amount', 'currency'], 'xlsx', 'purchasing'));
        $tmpPath = tempnam(sys_get_temp_dir(), 'wave1-xlsx-');
        try {
            file_put_contents($tmpPath, Excel::raw($xlsxExport, ExcelFormat::XLSX));
            $book = IOFactory::load($tmpPath);
            $sheet = $book->getActiveSheet();
            $this->assertSame('A2', $sheet->getFreezePane());
            $this->assertSame('A1:C1', $sheet->getAutoFilter()->getRange());
            $this->assertSame('#,##0.00', $sheet->getStyle('B2')->getNumberFormat()->getFormatCode());
            $book->disconnectWorksheets();
        } finally {
            @unlink($tmpPath);
        }
    }
}
