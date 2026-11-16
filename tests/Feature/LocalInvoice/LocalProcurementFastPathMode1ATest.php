<?php

namespace Tests\Feature\LocalInvoice;

use App\Jobs\ProcessLocalProcurementImport;
use App\Models\LocalFinanceAuditLog;
use App\Models\LocalGoodsReceipt;
use App\Models\LocalProcurementImport;
use App\Models\LocalPurchaseOrder;
use App\Models\Supplier;
use App\Models\SupplierScope;
use App\Models\User;
use App\Services\LocalInvoice\LocalProcurementImportService;
use App\Services\LocalInvoice\LocalProcurementMasterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class LocalProcurementFastPathMode1ATest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private User $supplier;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('private');
        Queue::fake();
        config([
            'local_procurement_imports.fast_path_max_rows' => 2000,
            'local_procurement_imports.fast_path_commit_max_records' => 1500,
        ]);

        $this->actor = User::factory()->create(['role' => 'finance', 'is_active' => true]);
        $this->supplier = User::factory()->create(['role' => 'supplier', 'is_active' => true]);
        SupplierScope::create(['supplier_id' => $this->supplier->id, 'scope' => 'local']);
        Supplier::create([
            'user_id' => $this->supplier->id,
            'company_name' => 'PT Mode 1A',
            'category' => 'Parts',
            'is_pkp' => false,
            'payment_term_days' => 30,
        ]);
    }

    public static function kindsAndFormats(): array
    {
        $cases = [];
        foreach (['PO', 'GR'] as $kind) {
            foreach (['xlsx', 'csv', 'xls'] as $format) {
                $cases["{$kind} {$format}"] = [$kind, $format];
            }
        }

        return $cases;
    }

    #[DataProvider('kindsAndFormats')]
    public function test_preview_routes_synchronously_at_threshold_and_queues_above_threshold(string $kind, string $format): void
    {
        config([
            'local_procurement_imports.fast_path_max_rows' => 3,
            'local_procurement_imports.active_per_user' => 10,
        ]);

        if ($kind === 'GR') {
            $this->po('M1A-PARENT');
        }

        $withinLimit = $this->file($this->sampleRows($kind, 3, 'M1A-PARENT'), $kind, $format);
        $routePrefix = 'finance.local-procurement.import.'.strtolower($kind);

        $syncResponse = $this->actingAs($this->actor)->postJson(route("{$routePrefix}.preview"), [
            'import_file' => $withinLimit,
        ]);

        $syncResponse->assertOk()
            ->assertJsonPath('status', 'READY')
            ->assertJsonPath('source_rows', 3)
            ->assertJsonPath('processed_rows', 3)
            ->assertJsonPath('validation_attempt', 1);
        $this->assertNotEmpty($syncResponse->json('token'));
        $this->assertNotEmpty($syncResponse->json('status_url'));
        $this->assertIsArray($syncResponse->json('preview'));
        Queue::assertNothingPushed();

        $syncImport = LocalProcurementImport::latest('id')->firstOrFail();
        $this->assertSame(0, DB::table('local_procurement_import_rows')->where('import_id', $syncImport->id)->count());
        $this->assertGreaterThan(0, DB::table('local_procurement_import_records')->where('import_id', $syncImport->id)->count());

        $aboveLimit = $this->file($this->sampleRows($kind, 4, 'M1A-PARENT', 100), $kind, $format);
        $asyncResponse = $this->actingAs($this->actor)->postJson(route("{$routePrefix}.preview"), [
            'import_file' => $aboveLimit,
        ]);

        $asyncResponse->assertStatus(202)
            ->assertJsonPath('status', 'QUEUED');
        $this->assertNotEmpty($asyncResponse->json('status_url'));

        $asyncImport = LocalProcurementImport::latest('id')->firstOrFail();
        Queue::assertPushed(
            ProcessLocalProcurementImport::class,
            fn (ProcessLocalProcurementImport $job) => $job->importId === $asyncImport->id && $job->stage === 'preview',
        );

        app(LocalProcurementImportService::class)->preview($asyncImport->id);
        $this->assertSame('READY', $asyncImport->fresh()->status);
        $this->assertSame(4, $asyncImport->fresh()->source_rows);
    }

    #[DataProvider('kindsAndFormats')]
    public function test_header_only_workbook_fails_synchronously_without_queue_dispatch(string $kind, string $format): void
    {
        $file = $this->file([], $kind, $format);

        $response = $this->actingAs($this->actor)->postJson(
            route('finance.local-procurement.import.'.strtolower($kind).'.preview'),
            ['import_file' => $file],
        );

        $response->assertOk()
            ->assertJsonPath('status', 'VALIDATION_FAILED')
            ->assertJsonPath('failure', __('local_procurement.import.empty_rows'))
            ->assertJsonPath('token', null);
        Queue::assertNothingPushed();
    }

    public function test_fast_path_succeeds_when_redis_queue_configured_while_above_threshold_rejects(): void
    {
        config([
            'queue.default' => 'redis',
            'local_procurement_imports.fast_path_max_rows' => 2,
            'local_procurement_imports.fast_path_commit_max_records' => 2,
            'local_procurement_imports.active_per_user' => 10,
        ]);

        $this->po('REDIS-PARENT');

        foreach (['PO', 'GR'] as $kind) {
            $preview = $this->actingAs($this->actor)->postJson(
                route('finance.local-procurement.import.'.strtolower($kind).'.preview'),
                ['import_file' => $this->file($this->sampleRows($kind, 2, 'REDIS-PARENT'), $kind)],
            );
            $preview->assertOk()->assertJsonPath('status', 'READY');

            $confirm = $this->actingAs($this->actor)->postJson(
                route('finance.local-procurement.import.'.strtolower($kind).'.confirm'),
                ['token' => $preview->json('token')],
            );
            $confirm->assertOk()->assertJsonPath('status', 'COMPLETED');
        }

        $service = app(LocalProcurementImportService::class);
        try {
            $service->start($this->actor, $this->file($this->sampleRows('PO', 3), 'PO'), 'PO');
            $this->fail('Rows above fast_path_max_rows must enforce assertQueue().');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('database queue', $exception->getMessage());
        }

        // Above commit threshold with fast_path_commit_max_records=1 must reject external queue cleanly.
        [$readyImport, $readyToken] = $service->start($this->actor, $this->file($this->sampleRows('PO', 2, '', 500), 'PO'), 'PO');
        $this->assertSame('READY', $readyImport->fresh()->status);
        config(['local_procurement_imports.fast_path_commit_max_records' => 1]);

        try {
            $service->confirm($this->actor, $readyToken, 'PO');
            $this->fail('Records above fast_path_commit_max_records must enforce assertQueue().');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('database queue', $exception->getMessage());
        }
        $this->assertSame('READY', $readyImport->fresh()->status);
        $this->assertDatabaseMissing('local_purchase_orders', ['po_number' => 'M1A-PO-500']);
    }

    public function test_commit_threshold_boundary_commits_sync_at_limit_and_queues_above_limit(): void
    {
        config([
            'local_procurement_imports.fast_path_max_rows' => 20,
            'local_procurement_imports.fast_path_commit_max_records' => 2,
            'local_procurement_imports.active_per_user' => 10,
        ]);

        // 2 PO records (including NEW + FILL_PR) <= 2 -> sync 200 COMPLETED.
        $existingEmptyPr = $this->po('PO-FILL-TARGET');
        $poSyncPreview = $this->actingAs($this->actor)->postJson(route('finance.local-procurement.import.po.preview'), [
            'import_file' => $this->file([
                ['po_number' => 'PO-SYNC-NEW', 'supplier_name' => 'PT Mode 1A', 'po_date' => '2026-10-01', 'po_amount' => '1000.00', 'pr_number' => 'PR-100'],
                ['po_number' => $existingEmptyPr->po_number, 'supplier_name' => 'PT Mode 1A', 'po_date' => '2026-10-01', 'po_amount' => '1000.00', 'pr_number' => 'PR-FILLED'],
            ], 'PO'),
        ])->assertOk();

        $poSyncConfirm = $this->actingAs($this->actor)->postJson(route('finance.local-procurement.import.po.confirm'), [
            'token' => $poSyncPreview->json('token'),
        ]);
        $poSyncConfirm->assertOk()
            ->assertJsonPath('status', 'COMPLETED')
            ->assertJsonPath('preview.summary.newPo', 1)
            ->assertJsonPath('preview.summary.filledPoPr', 1);
        $this->assertNotEmpty($poSyncConfirm->json('status_url'));
        $this->assertSame('PR-FILLED', $existingEmptyPr->fresh()->pr_number);
        $this->assertDatabaseHas('local_purchase_orders', ['po_number' => 'PO-SYNC-NEW', 'pr_number' => 'PR-100']);
        Queue::assertNothingPushed();

        // 3 PO records > 2 -> 202 IMPORTING queued confirm job.
        $poAsyncPreview = $this->actingAs($this->actor)->postJson(route('finance.local-procurement.import.po.preview'), [
            'import_file' => $this->file($this->sampleRows('PO', 3, '', 10), 'PO'),
        ])->assertOk();

        $poAsyncConfirm = $this->actingAs($this->actor)->postJson(route('finance.local-procurement.import.po.confirm'), [
            'token' => $poAsyncPreview->json('token'),
        ]);
        $poAsyncConfirm->assertStatus(202)->assertJsonPath('status', 'IMPORTING');
        $this->assertDatabaseMissing('local_purchase_orders', ['po_number' => 'M1A-PO-10']);

        $poAsyncImport = LocalProcurementImport::latest('id')->firstOrFail();
        Queue::assertPushed(
            ProcessLocalProcurementImport::class,
            fn (ProcessLocalProcurementImport $job) => $job->importId === $poAsyncImport->id && $job->stage === 'confirm',
        );

        app(LocalProcurementImportService::class)->commit($poAsyncImport->id);
        $this->assertSame('COMPLETED', $poAsyncImport->fresh()->status);
        $this->assertDatabaseHas('local_purchase_orders', ['po_number' => 'M1A-PO-10']);

        // 2 consolidated GR records <= 2 -> sync 200 COMPLETED.
        $grParent = $this->po('GR-SYNC-PO');
        $grSyncPreview = $this->actingAs($this->actor)->postJson(route('finance.local-procurement.import.gr.preview'), [
            'import_file' => $this->file([
                ['po_number' => $grParent->po_number, 'gr_number' => 'GR-SYNC-1', 'gr_date' => '2026-10-02', 'qty' => '2', 'uom' => 'pcs', 'description' => 'Part A'],
                ['po_number' => $grParent->po_number, 'gr_number' => 'GR-SYNC-1', 'gr_date' => '2026-10-02', 'qty' => '3', 'uom' => 'pcs', 'description' => 'Part B'],
                ['po_number' => $grParent->po_number, 'gr_number' => 'GR-SYNC-2', 'gr_date' => '2026-10-02', 'qty' => '4', 'uom' => 'pcs', 'description' => 'Part C'],
            ], 'GR'),
        ])->assertOk();

        $grSyncConfirm = $this->actingAs($this->actor)->postJson(route('finance.local-procurement.import.gr.confirm'), [
            'token' => $grSyncPreview->json('token'),
        ]);
        $grSyncConfirm->assertOk()
            ->assertJsonPath('status', 'COMPLETED')
            ->assertJsonPath('preview.summary.newGr', 2);
        $this->assertDatabaseHas('local_goods_receipts', ['gr_number' => 'GR-SYNC-1', 'qty' => '5.0000']);
        $this->assertDatabaseHas('local_goods_receipts', ['gr_number' => 'GR-SYNC-2', 'qty' => '4.0000']);
        Queue::assertNotPushed(ProcessLocalProcurementImport::class, fn (ProcessLocalProcurementImport $job) => $job->stage === 'confirm' && $job->importId !== $poAsyncImport->id);
    }

    public function test_revalidate_adapts_between_sync_200_and_async_202(): void
    {
        config([
            'local_procurement_imports.fast_path_max_rows' => 10,
            'local_procurement_imports.fast_path_commit_max_records' => 10,
        ]);

        // Initial validation fails because parent PO is CLOSED; fixing parent then revalidating runs synchronously.
        $po = $this->po('REVAL-PO', null, ['status' => 'CLOSED']);

        $preview = $this->actingAs($this->actor)->postJson(route('finance.local-procurement.import.gr.preview'), [
            'import_file' => $this->file([
                ['po_number' => $po->po_number, 'gr_number' => 'REVAL-GR-1', 'gr_date' => '2026-10-02', 'qty' => '5', 'uom' => 'pcs', 'description' => 'Valve'],
            ], 'GR'),
        ]);
        $preview->assertOk()->assertJsonPath('status', 'VALIDATION_FAILED');

        $import = LocalProcurementImport::latest('id')->firstOrFail();
        $this->assertSame(1, $import->attempt);
        $this->assertSame(0, DB::table('local_procurement_import_rows')->where('import_id', $import->id)->count());
        $this->assertCount(1, $this->actingAs($this->actor)->getJson(route('finance.local-procurement.imports.errors', $import))->assertOk()->json('data'));

        $po->update(['status' => 'OPEN']);

        $syncReval = $this->actingAs($this->actor)->postJson(route('finance.local-procurement.imports.revalidate', $import), [
            'expected_attempt' => 1,
        ]);
        $syncReval->assertOk()
            ->assertJsonPath('status', 'READY')
            ->assertJsonPath('validation_attempt', 2);
        $this->assertCount(0, $this->actingAs($this->actor)->getJson(route('finance.local-procurement.imports.errors', $import))->assertOk()->json('data'));
        $firstToken = $syncReval->json('token');
        $this->assertNotEmpty($firstToken);
        $this->assertNotEmpty($syncReval->json('preview'));
        Queue::assertNothingPushed();

        // Stale expected_attempt must be rejected cleanly.
        $this->actingAs($this->actor)->postJson(route('finance.local-procurement.imports.revalidate', $import), [
            'expected_attempt' => 1,
        ])->assertStatus(409);

        // Switching threshold below source_rows forces async 202 revalidation and invalidates prior token.
        config(['local_procurement_imports.fast_path_max_rows' => 0]);
        $asyncReval = $this->actingAs($this->actor)->postJson(route('finance.local-procurement.imports.revalidate', $import), [
            'expected_attempt' => 2,
        ]);
        $asyncReval->assertStatus(202)
            ->assertJsonPath('status', 'QUEUED');

        $this->actingAs($this->actor)->postJson(route('finance.local-procurement.import.gr.confirm'), [
            'token' => $firstToken,
        ])->assertStatus(419);

        Queue::assertPushed(
            ProcessLocalProcurementImport::class,
            fn (ProcessLocalProcurementImport $job) => $job->importId === $import->id && $job->stage === 'preview',
        );

        app(LocalProcurementImportService::class)->preview($import->id);
        $this->actingAs($this->actor)->getJson(route('finance.local-procurement.imports.status', $import))
            ->assertOk()
            ->assertJsonPath('status', 'READY')
            ->assertJsonPath('validation_attempt', 3);
    }

    public function test_results_url_on_fast_path_gr_completed_uses_records_table(): void
    {
        foreach (['finance', 'purchasing'] as $role) {
            $actor = $role === 'finance'
                ? $this->actor
                : User::factory()->create(['role' => 'purchasing', 'is_active' => true]);

            $poOne = $this->po("RES-{$role}-1");
            $poTwo = $this->po("RES-{$role}-2");

            $singlePoPreview = $this->actingAs($actor)->postJson(route("{$role}.local-procurement.import.gr.preview"), [
                'import_file' => $this->file([
                    ['po_number' => $poOne->po_number, 'gr_number' => "GR-{$role}-A", 'gr_date' => '2026-10-02', 'qty' => '2', 'uom' => 'pcs', 'description' => 'One'],
                    ['po_number' => $poOne->po_number, 'gr_number' => "GR-{$role}-B", 'gr_date' => '2026-10-02', 'qty' => '3', 'uom' => 'pcs', 'description' => 'Two'],
                ], 'GR'),
            ])->assertOk();

            $singleConfirm = $this->actingAs($actor)->postJson(route("{$role}.local-procurement.import.gr.confirm"), [
                'token' => $singlePoPreview->json('token'),
            ]);
            $singleConfirm->assertOk()
                ->assertJsonPath('status', 'COMPLETED')
                ->assertJsonPath('results_url', route("{$role}.local-procurement.show", $poOne));

            $singleImport = LocalProcurementImport::latest('id')->firstOrFail();
            $this->assertSame(0, DB::table('local_procurement_import_rows')->where('import_id', $singleImport->id)->count());

            $multiPoPreview = $this->actingAs($actor)->postJson(route("{$role}.local-procurement.import.gr.preview"), [
                'import_file' => $this->file([
                    ['po_number' => $poOne->po_number, 'gr_number' => "GR-{$role}-C", 'gr_date' => '2026-10-02', 'qty' => '1', 'uom' => 'pcs', 'description' => 'Multi 1'],
                    ['po_number' => $poTwo->po_number, 'gr_number' => "GR-{$role}-D", 'gr_date' => '2026-10-02', 'qty' => '1', 'uom' => 'pcs', 'description' => 'Multi 2'],
                ], 'GR'),
            ])->assertOk();

            $multiConfirm = $this->actingAs($actor)->postJson(route("{$role}.local-procurement.import.gr.confirm"), [
                'token' => $multiPoPreview->json('token'),
            ]);
            $multiConfirm->assertOk()
                ->assertJsonPath('status', 'COMPLETED')
                ->assertJsonPath('results_url', route("{$role}.local-procurement.index"));
        }
    }

    public static function parityKinds(): array
    {
        return [['PO'], ['GR']];
    }

    #[DataProvider('parityKinds')]
    public function test_fast_path_and_worker_produce_identical_validation_errors_summaries_and_master_state(string $kind): void
    {
        // Case 1: Error workbook with NEW, FILL_PR/EXISTING, IDENTITY_CONFLICT, CANCELLED restore error, and invalid field -> all-or-nothing blocking.
        $conflictSnapshot = [];
        foreach (['fast' => 2000, 'worker' => 0] as $mode => $threshold) {
            $this->resetProcurementTables();
            $this->seedParityFixtures();
            config([
                'local_procurement_imports.fast_path_max_rows' => $threshold,
                'local_procurement_imports.fast_path_commit_max_records' => $threshold,
                'local_procurement_imports.batch_size' => 2,
            ]);

            $rows = $this->parityConflictRows($kind);
            $snapshot = $this->runPreviewAndCollect($kind, $rows, $mode === 'worker');
            $conflictSnapshot[$mode] = $snapshot;

            $this->assertSame('VALIDATION_FAILED', $snapshot['status']);
        }

        $this->assertSame($conflictSnapshot['fast']['status'], $conflictSnapshot['worker']['status']);
        $this->assertSame($conflictSnapshot['fast']['failure'], $conflictSnapshot['worker']['failure']);
        $this->assertSame($conflictSnapshot['fast']['summary'], $conflictSnapshot['worker']['summary']);
        $this->assertSame($conflictSnapshot['fast']['errors'], $conflictSnapshot['worker']['errors']);
        $this->assertSame($conflictSnapshot['fast']['records'], $conflictSnapshot['worker']['records']);

        // Case 2: Valid mixed workbook (NEW + FILL_PR + EXISTING for PO; NEW aggregated + EXISTING for GR).
        $validSnapshot = [];
        foreach (['fast' => 2000, 'worker' => 0] as $mode => $threshold) {
            $this->resetProcurementTables();
            $this->seedParityFixtures();
            config([
                'local_procurement_imports.fast_path_max_rows' => $threshold,
                'local_procurement_imports.fast_path_commit_max_records' => $threshold,
                'local_procurement_imports.batch_size' => 2,
            ]);

            $rows = $this->parityValidRows($kind);
            $snapshot = $this->runPreviewConfirmAndCollect($kind, $rows, $mode === 'worker');
            $validSnapshot[$mode] = $snapshot;

            $this->assertSame('COMPLETED', $snapshot['status']);
        }

        $this->assertSame($validSnapshot['fast']['preview_summary'], $validSnapshot['worker']['preview_summary']);
        $this->assertSame($validSnapshot['fast']['completed_summary'], $validSnapshot['worker']['completed_summary']);
        $this->assertSame($validSnapshot['fast']['records'], $validSnapshot['worker']['records']);
        $this->assertSame($validSnapshot['fast']['pos'], $validSnapshot['worker']['pos']);
        $this->assertSame($validSnapshot['fast']['grs'], $validSnapshot['worker']['grs']);
        $this->assertSame($validSnapshot['fast']['audits'], $validSnapshot['worker']['audits']);
    }

    public function test_fast_path_slot_lifecycle_occupies_only_when_ready_and_frees_on_validation_fail_cancel_or_confirm(): void
    {
        config([
            'local_procurement_imports.active_per_user' => 1,
            'local_procurement_imports.fast_path_max_rows' => 10,
            'local_procurement_imports.fast_path_commit_max_records' => 10,
        ]);

        // Recoverable VALIDATION_FAILED does not occupy the user's single active slot.
        $this->actingAs($this->actor)->postJson(route('finance.local-procurement.import.po.preview'), [
            'import_file' => $this->file([
                ['po_number' => 'BAD-PO', 'supplier_name' => 'Unknown Supplier', 'po_date' => '2026-10-01', 'po_amount' => '100.00', 'pr_number' => ''],
            ], 'PO'),
        ])->assertOk()->assertJsonPath('status', 'VALIDATION_FAILED');

        // Ready fast-path import succeeds immediately and occupies the single slot.
        $readyResponse = $this->actingAs($this->actor)->postJson(route('finance.local-procurement.import.po.preview'), [
            'import_file' => $this->file($this->sampleRows('PO', 1, '', 1), 'PO'),
        ])->assertOk()->assertJsonPath('status', 'READY');

        // Attempting a second preview while READY still occupies the slot is rejected with capacity 422.
        $this->actingAs($this->actor)->postJson(route('finance.local-procurement.import.po.preview'), [
            'import_file' => $this->file($this->sampleRows('PO', 1, '', 2), 'PO'),
        ])->assertStatus(422)->assertJsonValidationErrors('import_file');

        // Synchronous confirm completes immediately and frees the active slot.
        $this->actingAs($this->actor)->postJson(route('finance.local-procurement.import.po.confirm'), [
            'token' => $readyResponse->json('token'),
        ])->assertOk()->assertJsonPath('status', 'COMPLETED');

        $this->actingAs($this->actor)->postJson(route('finance.local-procurement.import.po.preview'), [
            'import_file' => $this->file($this->sampleRows('PO', 1, '', 3), 'PO'),
        ])->assertOk()->assertJsonPath('status', 'READY');
    }

    public function test_sync_confirm_failure_marks_failed_clears_lease_and_frees_operator_slot(): void
    {
        config(['local_procurement_imports.active_per_user' => 1]);
        $service = app(LocalProcurementImportService::class);
        [$import, $token] = $service->start($this->actor, $this->file($this->sampleRows('PO', 1, '', 2), 'PO'), 'PO');
        $this->assertSame('READY', $import->status);

        $this->mock(LocalProcurementMasterService::class)
            ->shouldReceive('createImportBatch')
            ->once()
            ->andThrow(new RuntimeException('Simulated sync commit failure'));

        try {
            $service->confirm($this->actor, $token, 'PO');
            $this->fail('Expected sync commit failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated sync commit failure', $exception->getMessage());
        }

        $failed = $import->fresh();
        $this->assertSame('FAILED', $failed->status);
        $this->assertNull($failed->lease_expires_at);
        $this->assertNotNull($failed->finished_at);
        $this->assertSame(0, LocalPurchaseOrder::count());

        // Slot was cleanly released so starting another import at capacity 1 succeeds without 422.
        [$nextImport] = $service->start($this->actor, $this->file($this->sampleRows('PO', 1, '', 10), 'PO'), 'PO');
        $this->assertSame('READY', $nextImport->status);
    }

    public function test_revalidate_detects_checksum_mismatch_before_re_parsing(): void
    {
        $service = app(LocalProcurementImportService::class);

        // 1. Tampered file on disk is rejected at FileAccessGuard before re-parsing.
        [$importTampered] = $service->start($this->actor, $this->file($this->sampleRows('PO', 1, '', 2), 'PO'), 'PO');
        $attachment = $importTampered->attachments()->firstOrFail();
        Storage::disk('private')->put($attachment->file_path, 'tampered-bytes');

        try {
            $service->revalidate($this->actor, $importTampered, (int) $importTampered->attempt);
            $this->fail('Expected FileAccessGuard rejection for tampered file.');
        } catch (ValidationException $exception) {
            $this->assertSame([__('local_procurement.revalidation.file_unavailable')], $exception->errors()['import_file'] ?? []);
        }
        $this->assertSame('READY', $importTampered->fresh()->status);

        // 2. Mismatched import checksum (with intact inspection digest) is rejected by defense-in-depth check.
        [$importChecksum] = $service->start($this->actor, $this->file($this->sampleRows('PO', 1, '', 3), 'PO'), 'PO');
        $importChecksum->update(['checksum' => str_repeat('0', 64)]);

        try {
            $service->revalidate($this->actor, $importChecksum->fresh(), (int) $importChecksum->attempt);
            $this->fail('Expected checksum mismatch.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Import checksum mismatch.', $exception->getMessage());
        }
        $this->assertSame('READY', $importChecksum->fresh()->status);
    }

    private function seedParityFixtures(): void
    {
        $this->po('PAR-PO-OPEN', null, ['total_amount' => '1000.00']);
        $this->po('PAR-PO-EXIST', 'PR-FIXED', ['total_amount' => '1000.00']);
        $this->po('PAR-PO-CANCELLED', null, ['total_amount' => '1000.00', 'status' => 'CANCELLED']);
        $this->po('PAR-PO-CONFLICT', null, ['total_amount' => '500.00']);

        $grParent = $this->po('PAR-GR-PARENT', null, ['total_amount' => '5000.00']);
        LocalGoodsReceipt::create([
            'local_purchase_order_id' => $grParent->id,
            'gr_number' => 'PAR-GR-EXIST',
            'gr_date' => '2026-10-02',
            'qty' => '5.0000',
            'uom' => 'pcs',
            'description' => 'Existing item',
            'status' => 'AVAILABLE',
            'source' => 'MANUAL',
            'created_by' => $this->actor->id,
        ]);
        LocalGoodsReceipt::create([
            'local_purchase_order_id' => $grParent->id,
            'gr_number' => 'PAR-GR-CANCELLED',
            'gr_date' => '2026-10-02',
            'qty' => '5.0000',
            'uom' => 'pcs',
            'description' => 'Cancelled item',
            'status' => 'CANCELLED',
            'source' => 'MANUAL',
            'created_by' => $this->actor->id,
        ]);
        LocalGoodsReceipt::create([
            'local_purchase_order_id' => $grParent->id,
            'gr_number' => 'PAR-GR-CONFLICT',
            'gr_date' => '2026-10-02',
            'qty' => '5.0000',
            'uom' => 'pcs',
            'description' => 'Conflict item',
            'status' => 'AVAILABLE',
            'source' => 'MANUAL',
            'created_by' => $this->actor->id,
        ]);
    }

    private function resetProcurementTables(): void
    {
        DB::table('local_finance_audit_logs')->delete();
        DB::table('local_goods_receipts')->delete();
        DB::table('local_purchase_orders')->delete();
        DB::table('local_procurement_import_rows')->delete();
        DB::table('local_procurement_import_records')->delete();
        DB::table('local_procurement_imports')->delete();
    }

    private function parityConflictRows(string $kind): array
    {
        if ($kind === 'PO') {
            return [
                ['po_number' => 'PAR-PO-NEW-1', 'supplier_name' => 'PT Mode 1A', 'po_date' => '2026-10-01', 'po_amount' => '1200.00', 'pr_number' => 'PR-NEW-1'],
                ['po_number' => 'PAR-PO-OPEN', 'supplier_name' => 'PT Mode 1A', 'po_date' => '2026-10-01', 'po_amount' => '1000.00', 'pr_number' => 'PR-FILL-1'],
                ['po_number' => 'PAR-PO-EXIST', 'supplier_name' => 'PT Mode 1A', 'po_date' => '2026-10-01', 'po_amount' => '1000.00', 'pr_number' => 'PR-FIXED'],
                ['po_number' => 'PAR-PO-CONFLICT', 'supplier_name' => 'PT Mode 1A', 'po_date' => '2026-10-01', 'po_amount' => '999.00', 'pr_number' => ''],
                ['po_number' => 'PAR-PO-CANCELLED', 'supplier_name' => 'PT Mode 1A', 'po_date' => '2026-10-01', 'po_amount' => '1000.00', 'pr_number' => ''],
                ['po_number' => 'PAR-PO-BAD', 'supplier_name' => 'Unknown Vendor', 'po_date' => 'not-a-date', 'po_amount' => '-5', 'pr_number' => ''],
            ];
        }

        return [
            ['po_number' => 'PAR-GR-PARENT', 'gr_number' => 'PAR-GR-NEW', 'gr_date' => '2026-10-02', 'qty' => '2.5', 'uom' => 'pcs', 'description' => 'Part A'],
            ['po_number' => 'PAR-GR-PARENT', 'gr_number' => 'PAR-GR-NEW', 'gr_date' => '2026-10-02', 'qty' => '1.5', 'uom' => 'pcs', 'description' => 'Part B'],
            ['po_number' => 'PAR-GR-PARENT', 'gr_number' => 'PAR-GR-EXIST', 'gr_date' => '2026-10-02', 'qty' => '5', 'uom' => 'pcs', 'description' => 'Existing item'],
            ['po_number' => 'PAR-GR-PARENT', 'gr_number' => 'PAR-GR-CONFLICT', 'gr_date' => '2026-10-02', 'qty' => '9', 'uom' => 'pcs', 'description' => 'Mismatch'],
            ['po_number' => 'PAR-GR-PARENT', 'gr_number' => 'PAR-GR-CANCELLED', 'gr_date' => '2026-10-02', 'qty' => '5', 'uom' => 'pcs', 'description' => 'Cancelled'],
            ['po_number' => 'PAR-MISSING-PO', 'gr_number' => 'PAR-GR-ORPHAN', 'gr_date' => '2026-10-02', 'qty' => '1', 'uom' => 'pcs', 'description' => 'Orphan'],
        ];
    }

    private function parityValidRows(string $kind): array
    {
        if ($kind === 'PO') {
            return [
                ['po_number' => 'PAR-PO-NEW-A', 'supplier_name' => 'PT Mode 1A', 'po_date' => '2026-10-01', 'po_amount' => '1200.00', 'pr_number' => 'PR-A'],
                ['po_number' => 'PAR-PO-NEW-B', 'supplier_name' => 'PT Mode 1A', 'po_date' => '2026-10-01', 'po_amount' => '1500.00', 'pr_number' => ''],
                ['po_number' => 'PAR-PO-OPEN', 'supplier_name' => 'PT Mode 1A', 'po_date' => '2026-10-01', 'po_amount' => '1000.00', 'pr_number' => 'PR-FILL-OK'],
                ['po_number' => 'PAR-PO-EXIST', 'supplier_name' => 'PT Mode 1A', 'po_date' => '2026-10-01', 'po_amount' => '1000.00', 'pr_number' => 'pr-fixed'],
            ];
        }

        return [
            ['po_number' => 'PAR-GR-PARENT', 'gr_number' => 'PAR-GR-NEW-A', 'gr_date' => '2026-10-02', 'qty' => '2.25', 'uom' => 'pcs', 'description' => 'Bolt'],
            ['po_number' => 'PAR-GR-PARENT', 'gr_number' => 'PAR-GR-NEW-A', 'gr_date' => '2026-10-02', 'qty' => '1.75', 'uom' => 'pcs', 'description' => 'Nut'],
            ['po_number' => 'PAR-GR-PARENT', 'gr_number' => 'PAR-GR-EXIST', 'gr_date' => '2026-10-02', 'qty' => '5', 'uom' => 'pcs', 'description' => 'Existing item'],
        ];
    }

    private function runPreviewAndCollect(string $kind, array $rows, bool $runWorker): array
    {
        $response = $this->actingAs($this->actor)->postJson(
            route('finance.local-procurement.import.'.strtolower($kind).'.preview'),
            ['import_file' => $this->file($rows, $kind)],
        );
        $runWorker ? $response->assertStatus(202) : $response->assertOk();

        $import = LocalProcurementImport::latest('id')->firstOrFail();
        if ($runWorker) {
            app(LocalProcurementImportService::class)->preview($import->id);
            $import->refresh();
        }

        $errorsJson = $this->actingAs($this->actor)->getJson(
            route('finance.local-procurement.imports.errors', $import),
        )->assertOk()->json('data');
        $this->assertIsArray($errorsJson);
        $this->assertNotEmpty($errorsJson);
        $errorsJson = array_map(function (array $error): array {
            if (isset($error['action']['url'])) {
                $error['action']['url'] = preg_replace('~/local-procurement/[^/?]+~', '/local-procurement/{id}', $error['action']['url']);
            }

            return $error;
        }, $errorsJson);

        $poNumbersById = LocalPurchaseOrder::pluck('po_number', 'id')->all();
        $records = DB::table('local_procurement_import_records')
            ->where('import_id', $import->id)
            ->orderBy('source_row')
            ->get(['document_key', 'source_row', 'source_count', 'values', 'errors'])
            ->map(function ($row) use ($poNumbersById) {
                $values = json_decode($row->values, true);
                if (array_key_exists('po_id', $values)) {
                    $values['po_id'] = $poNumbersById[$values['po_id']] ?? null;
                }

                return [
                    'document_key' => $row->document_key,
                    'source_row' => $row->source_row,
                    'source_count' => $row->source_count,
                    'values' => $values,
                    'errors' => json_decode($row->errors, true),
                ];
            })
            ->all();

        return [
            'status' => $import->status,
            'failure' => $import->failure,
            'summary' => $import->summary,
            'errors' => $errorsJson,
            'records' => $records,
        ];
    }

    private function runPreviewConfirmAndCollect(string $kind, array $rows, bool $runWorker): array
    {
        $routePrefix = 'finance.local-procurement.import.'.strtolower($kind);
        $previewResponse = $this->actingAs($this->actor)->postJson(route("{$routePrefix}.preview"), [
            'import_file' => $this->file($rows, $kind),
        ]);
        $runWorker ? $previewResponse->assertStatus(202) : $previewResponse->assertOk();

        $import = LocalProcurementImport::latest('id')->firstOrFail();
        if ($runWorker) {
            app(LocalProcurementImportService::class)->preview($import->id);
            $import->refresh();
        }
        $previewSummary = $import->summary;

        $statusResponse = $this->actingAs($this->actor)->getJson(route('finance.local-procurement.imports.status', $import))->assertOk();
        $confirmResponse = $this->actingAs($this->actor)->postJson(route("{$routePrefix}.confirm"), [
            'token' => $statusResponse->json('token'),
        ]);
        $runWorker ? $confirmResponse->assertStatus(202) : $confirmResponse->assertOk();

        if ($runWorker) {
            app(LocalProcurementImportService::class)->commit($import->id);
        }
        $import->refresh();

        $poNumbersById = LocalPurchaseOrder::pluck('po_number', 'id')->all();
        $records = DB::table('local_procurement_import_records')
            ->where('import_id', $import->id)
            ->orderBy('source_row')
            ->get(['document_key', 'source_row', 'source_count', 'values'])
            ->map(function ($row) use ($poNumbersById) {
                $values = json_decode($row->values, true);
                if (array_key_exists('po_id', $values)) {
                    $values['po_id'] = $poNumbersById[$values['po_id']] ?? null;
                }

                return [
                    'document_key' => $row->document_key,
                    'source_row' => $row->source_row,
                    'source_count' => $row->source_count,
                    'values' => $values,
                ];
            })
            ->all();

        return [
            'status' => $import->status,
            'preview_summary' => $previewSummary,
            'completed_summary' => $import->summary,
            'records' => $records,
            'pos' => LocalPurchaseOrder::orderBy('po_number')->get(['po_number', 'pr_number', 'po_date', 'total_amount', 'status', 'source'])->toArray(),
            'grs' => LocalGoodsReceipt::orderBy('gr_number')->get(['gr_number', 'gr_date', 'qty', 'uom', 'description', 'status', 'source'])->toArray(),
            'audits' => LocalFinanceAuditLog::orderBy('action')->selectRaw('action, count(*) as total')->groupBy('action')->pluck('total', 'action')->toArray(),
        ];
    }

    private function po(string $number, ?string $pr = null, array $override = []): LocalPurchaseOrder
    {
        return LocalPurchaseOrder::create(array_merge([
            'po_number' => $number,
            'pr_number' => $pr,
            'supplier_id' => $this->supplier->id,
            'po_date' => '2026-10-01',
            'total_amount' => '1000.00',
            'currency' => 'IDR',
            'status' => 'OPEN',
            'source' => 'MANUAL',
            'description' => 'Manual description',
        ], $override));
    }

    private function sampleRows(string $kind, int $count, string $poNumber = '', int $offset = 0): array
    {
        $rows = [];
        for ($i = 0; $i < $count; $i++) {
            $n = $offset + $i;
            if ($kind === 'PO') {
                $rows[] = [
                    'po_number' => "M1A-PO-{$n}",
                    'supplier_name' => 'PT Mode 1A',
                    'po_date' => '2026-10-01',
                    'po_amount' => '1000.00',
                    'pr_number' => '',
                ];
            } else {
                $rows[] = [
                    'po_number' => $poNumber,
                    'gr_number' => "M1A-GR-{$n}",
                    'gr_date' => '2026-10-02',
                    'qty' => '1',
                    'uom' => 'pcs',
                    'description' => "Line {$n}",
                ];
            }
        }

        return $rows;
    }

    private function file(array $rows, string $kind, string $format = 'xlsx'): UploadedFile
    {
        $path = Storage::disk('private')->path("mode1a-{$kind}-".uniqid().'.'.$format);
        $header = array_fill(0, 21, '');
        if ($kind === 'PO') {
            $header[4] = 'Order';
            $header[6] = 'Supplier Name';
            $header[9] = 'Order Date';
            $header[10] = 'Order Amount';
            $header[16] = 'PR';
        } else {
            $header[8] = 'Description';
            $header[9] = 'Receipt';
            $header[11] = 'Order Line';
            $header[15] = 'Received Quantity';
            $header[16] = 'UOM';
            $header[19] = 'Actual Receipt Date';
        }

        $values = [$header];
        foreach ($rows as $row) {
            $cells = array_fill(0, 21, '');
            if ($kind === 'PO') {
                $cells[4] = $row['po_number'];
                $cells[6] = $row['supplier_name'];
                $cells[9] = $row['po_date'];
                $cells[10] = $row['po_amount'];
                $cells[16] = $row['pr_number'] ?? '';
            } else {
                $cells[8] = $row['description'];
                $cells[9] = $row['gr_number'];
                $cells[11] = $row['po_number'];
                $cells[15] = $row['qty'];
                $cells[16] = $row['uom'];
                $cells[19] = $row['gr_date'];
            }
            $values[] = $cells;
        }

        if ($format === 'csv') {
            $stream = fopen($path, 'wb');
            foreach ($values as $cells) {
                fputcsv($stream, $cells, ',', '"', '');
            }
            fclose($stream);
        } elseif ($format === 'xls') {
            $workbook = new Spreadsheet;
            foreach ($values as $rowIndex => $cells) {
                foreach ($cells as $column => $value) {
                    $workbook->getActiveSheet()->setCellValueExplicit(
                        Coordinate::stringFromColumnIndex($column + 1).($rowIndex + 1),
                        (string) $value,
                        DataType::TYPE_STRING,
                    );
                }
            }
            IOFactory::createWriter($workbook, 'Xls')->save($path);
            $workbook->disconnectWorksheets();
        } else {
            $writer = new Writer;
            $writer->openToFile($path);
            foreach ($values as $cells) {
                $writer->addRow(Row::fromValues($cells));
            }
            $writer->close();
        }

        return new UploadedFile($path, "mode1a.{$format}", null, null, true);
    }
}
