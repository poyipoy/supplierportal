<?php

namespace Tests\Feature\LocalInvoice;

use App\Jobs\ProcessLocalProcurementImport;
use App\Models\Attachment;
use App\Models\LocalFinanceAuditLog;
use App\Models\LocalGoodsReceipt;
use App\Models\LocalProcurementImport;
use App\Models\LocalPurchaseOrder;
use App\Models\Supplier;
use App\Models\SupplierScope;
use App\Models\User;
use App\Services\LocalInvoice\LocalPoImportService;
use App\Services\LocalInvoice\LocalProcurementImportService;
use App\Services\LocalInvoice\LocalProcurementStreamingReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Tests\TestCase;

class LocalProcurementBackgroundImportTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private User $supplier;

    private LocalPurchaseOrder $po;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('private');
        Queue::fake();
        $this->actor = User::factory()->create(['role' => 'finance', 'is_active' => true]);
        $this->supplier = User::factory()->create(['role' => 'supplier', 'is_active' => true]);
        SupplierScope::create(['supplier_id' => $this->supplier->id, 'scope' => 'local']);
        Supplier::create(['user_id' => $this->supplier->id, 'company_name' => 'PT Import Test', 'category' => 'Parts', 'is_pkp' => false, 'payment_term_days' => 30]);
        $this->po = LocalPurchaseOrder::create(['po_number' => 'IMPORT-PARENT', 'supplier_id' => $this->supplier->id, 'po_date' => '2026-10-01',
            'total_amount' => '1000000.00', 'currency' => 'IDR', 'status' => 'OPEN', 'source' => 'MANUAL']);
    }

    public function test_background_gr_over_old_limit_is_staged_grouped_and_confirmed_once(): void
    {
        $service = app(LocalProcurementImportService::class);
        [$import, $token] = $service->start($this->actor, $this->workbook('GR', 2001), 'GR');
        $this->assertSame('QUEUED', $import->status);
        Queue::assertPushed(ProcessLocalProcurementImport::class, fn ($job) => $job->queue === 'imports' && strlen(serialize($job)) < 10240);
        $service->preview($import->id);
        $this->assertSame('READY', $import->fresh()->status, $import->fresh()->failure ?? '');
        $this->assertSame(2001, $import->fresh()->source_rows);
        $this->assertDatabaseMissing('local_goods_receipts', ['gr_number' => 'IMPORT-GR']);
        $service->confirm($this->actor, $token, 'GR');
        $service->commit($import->id);
        $this->assertSame('COMPLETED', $import->fresh()->status);
        $gr = LocalGoodsReceipt::where('gr_number', 'IMPORT-GR')->firstOrFail();
        $this->assertSame('2001.0000', $gr->qty);
        $this->assertSame('pcs', $gr->uom);
        $this->assertStringContainsString('2001 line rows', $gr->notes);
        $service->confirm($this->actor, $token, 'GR');
        $service->commit($import->id);
        $this->assertSame(1, LocalFinanceAuditLog::where('action', 'gr_created')->count());
        $this->assertSame(1, LocalFinanceAuditLog::where('action', 'gr_import_confirmed')->count());
    }

    public function test_po_and_gr_create_domain_records_with_per_record_audits(): void
    {
        foreach (['PO', 'GR'] as $kind) {
            $service = app(LocalProcurementImportService::class);
            [$import, $token] = $service->start($this->actor, $this->workbook($kind, 3), $kind);
            $service->preview($import->id);
            $this->assertSame('READY', $import->fresh()->status);
            $service->confirm($this->actor, $token, $kind);
            $service->commit($import->id);
            $this->assertSame('COMPLETED', $import->fresh()->status, $import->fresh()->failure ?? '');
        }
        $this->assertSame(3, LocalFinanceAuditLog::where('action', 'po_created')->count());
        $this->assertSame(1, LocalFinanceAuditLog::where('action', 'gr_created')->count());
        foreach (LocalFinanceAuditLog::whereIn('action', ['po_created', 'gr_created'])->get() as $audit) {
            $subject = $audit->auditable_type::findOrFail($audit->auditable_id);
            $this->assertEquals($subject->toArray(), $audit->after_values);
        }
        $this->assertDatabaseHas('local_goods_receipts', ['gr_number' => 'IMPORT-GR', 'qty' => '3.0000', 'uom' => 'pcs']);
    }

    public function test_cross_batch_mixed_uom_and_over_limit_block_all_domain_writes(): void
    {
        $service = app(LocalProcurementImportService::class);
        [$import] = $service->start($this->actor, $this->workbook('GR', 501, true), 'GR');
        $service->preview($import->id);
        $this->assertSame('VALIDATION_FAILED', $import->fresh()->status);
        $this->assertSame(0, LocalGoodsReceipt::count());
        config(['local_procurement_imports.max_rows' => 500]);
        [$import] = $service->start($this->actor, $this->workbook('GR', 501), 'GR');
        $service->preview($import->id);
        $this->assertSame('VALIDATION_FAILED', $import->fresh()->status);
        $this->assertSame(0, LocalGoodsReceipt::count());
    }

    public function test_master_change_after_preview_blocks_commit(): void
    {
        $service = app(LocalProcurementImportService::class);
        [$import, $token] = $service->start($this->actor, $this->workbook('GR', 1), 'GR');
        $service->preview($import->id);
        $this->po->update(['status' => 'CLOSED']);
        $service->confirm($this->actor, $token, 'GR');
        $service->commit($import->id);
        $this->assertSame('VALIDATION_FAILED', $import->fresh()->status);
        $this->assertSame(0, LocalGoodsReceipt::count());
    }

    public function test_failure_after_first_write_batch_rolls_back_domain_and_audit(): void
    {
        $service = app(LocalProcurementImportService::class);
        [$import, $token] = $service->start($this->actor, $this->workbook('PO', 501), 'PO');
        $service->preview($import->id);
        $this->assertSame('READY', $import->fresh()->status);
        $service->confirm($this->actor, $token, 'PO');
        $initial = LocalPurchaseOrder::count();
        DB::listen(function ($query) {
            if (str_contains($query->sql, 'insert into `local_purchase_orders`') && str_contains(implode(' ', array_filter($query->bindings, 'is_scalar')), 'PO-ROW-500')) {
                throw new \RuntimeException('Injected later batch failure');
            }
        });
        try {
            $service->commit($import->id);
            $this->fail('Expected injected failure');
        } catch (\RuntimeException $e) {
            $this->assertSame('Injected later batch failure', $e->getMessage());
        }
        $this->assertSame($initial, LocalPurchaseOrder::count());
        $this->assertSame(0, LocalFinanceAuditLog::where('action', 'po_created')->count());
        $this->assertSame('IMPORTING', $import->fresh()->status);
    }

    public function test_http_endpoints_are_asynchronous_paginated_and_owned(): void
    {
        $response = $this->actingAs($this->actor)->post(route('finance.local-procurement.import.po.preview'), ['import_file' => $this->workbook('PO', 501)], ['Accept' => 'application/json']);
        $response->assertStatus(202);
        $this->assertArrayNotHasKey('rows', $response->json());
        $import = LocalProcurementImport::firstOrFail();
        app(LocalProcurementImportService::class)->preview($import->id);
        $this->getJson($response->json('status_url'))->assertOk()->assertJsonPath('status', 'READY')->assertJsonCount(100, 'preview.rows');
        $this->getJson(route('finance.local-procurement.imports.records', $import).'?page=6')->assertOk()->assertJsonCount(1, 'data');
        $other = User::factory()->create(['role' => 'finance', 'is_active' => true]);
        $this->actingAs($other)->getJson($response->json('status_url'))->assertForbidden();
        $this->actingAs($this->supplier)->getJson($response->json('status_url'))->assertForbidden();
    }

    public function test_external_queue_is_rejected_before_work(): void
    {
        config(['queue.default' => 'redis']);
        $this->expectException(\RuntimeException::class);
        app(LocalProcurementImportService::class)->start($this->actor, $this->workbook('PO', 1), 'PO');
    }

    public function test_queue_insert_failure_rolls_back_import_attachment_and_private_upload(): void
    {
        config(['queue.default' => 'database']);
        $queue = \Mockery::mock(\Illuminate\Contracts\Queue\Queue::class);
        $queue->shouldReceive('push')->once()->andThrow(new \RuntimeException('Injected queue failure'));
        Queue::shouldReceive('connection')->andReturn($queue);
        try {
            app(LocalProcurementImportService::class)->start($this->actor, $this->workbook('PO', 1), 'PO');
            $this->fail('Expected handoff failure');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Injected queue failure', $exception->getMessage());
        }
        $this->assertSame(0, LocalProcurementImport::count());
        $this->assertSame(0, Attachment::count());
        $this->assertSame([], Storage::disk('private')->allFiles('imports/uploads'));
    }

    public function test_stale_delivery_does_not_publish_or_fail_newer_live_attempt(): void
    {
        $service = app(LocalProcurementImportService::class);
        [$import] = $service->start($this->actor, $this->workbook('GR', 1), 'GR');
        $job = new ProcessLocalProcurementImport($import->id, 'preview');
        $job->runKey = $import->owner_job_key;
        $changed = false;
        DB::listen(function ($query) use ($import, &$changed) {
            if (! $changed && str_contains($query->sql, 'insert into `local_procurement_import_rows`')) {
                $changed = true;
                LocalProcurementImport::whereKey($import->id)->update(['attempt' => 2, 'status' => 'READING', 'lease_expires_at' => now()->addSeconds(600)]);
            }
        });
        $job->handle($service);
        unserialize(serialize($job))->failed(new \RuntimeException('Stale delivery'));
        $this->assertSame(2, $import->fresh()->attempt);
        $this->assertSame('READING', $import->fresh()->status);
        $this->assertSame(0, LocalGoodsReceipt::count());
    }

    public function test_cleanup_expires_previews_and_preserves_master_and_audit_records(): void
    {
        $service = app(LocalProcurementImportService::class);
        [$import] = $service->start($this->actor, $this->workbook('GR', 1), 'GR');
        $service->preview($import->id);
        $import->update(['expires_at' => now()->subDay()]);
        $this->actingAs($this->actor)->getJson(route('finance.local-procurement.imports.status', $import))
            ->assertOk()->assertJsonPath('status', 'EXPIRED')->assertJsonPath('token', null);
        $this->artisan('imports:cleanup')->assertSuccessful();
        $this->assertSame('EXPIRED', $import->fresh()->status);
        $import->update(['finished_at' => now()->subDays(4)]);
        $auditCount = LocalFinanceAuditLog::count();
        $this->artisan('imports:cleanup')->assertSuccessful();
        $this->assertNotNull($import->fresh()->cleaned_at);
        $this->assertSame(0, DB::table('local_procurement_import_rows')->where('import_id', $import->id)->count());
        $this->assertSame($auditCount, LocalFinanceAuditLog::count());
        $this->assertSame(1, LocalPurchaseOrder::count());
    }

    public function test_supplier_deactivation_and_zero_amount_are_blocked(): void
    {
        $this->assertFalse(app(LocalPoImportService::class)->validate([['_row' => 2, 'po_number' => 'ZERO', 'supplier_name' => 'PT Import Test', 'po_date' => '2026-10-01', 'po_amount' => 0, '_formula_columns' => []]])['success']);
        $service = app(LocalProcurementImportService::class);
        [$import, $token] = $service->start($this->actor, $this->workbook('PO', 1), 'PO');
        $service->preview($import->id);
        $this->supplier->update(['is_active' => false]);
        $service->confirm($this->actor, $token, 'PO');
        $service->commit($import->id);
        $this->assertSame('VALIDATION_FAILED', $import->fresh()->status);
        $this->assertDatabaseMissing('local_purchase_orders', ['po_number' => 'PO-ROW-0']);
    }

    public function test_po_and_gr_over_old_limit_and_existing_preview_counters(): void
    {
        foreach (['PO', 'GR'] as $kind) {
            $service = app(LocalProcurementImportService::class);
            [$import, $token] = $service->start($this->actor, $this->workbook($kind, 2001), $kind);
            $service->preview($import->id);
            $this->assertSame('READY', $import->fresh()->status);
            $service->confirm($this->actor, $token, $kind);
            $service->commit($import->id);
            $this->assertSame('COMPLETED', $import->fresh()->status);
        }
        $this->assertSame(1, LocalGoodsReceipt::count());
        $this->assertSame(2002, LocalPurchaseOrder::count());
    }

    public function test_existing_po_and_missing_gr_parent_counters_are_authoritative(): void
    {
        LocalPurchaseOrder::create(['po_number' => 'PO-ROW-0', 'supplier_id' => $this->supplier->id,
            'po_date' => '2026-10-01', 'total_amount' => '100.00', 'currency' => 'IDR', 'status' => 'OPEN', 'source' => 'MANUAL']);
        $service = app(LocalProcurementImportService::class);
        [$import] = $service->start($this->actor, $this->workbook('PO', 1), 'PO');
        $service->preview($import->id);
        $this->assertSame('READY', $import->fresh()->status);
        $this->assertSame(1, $import->fresh()->summary['existing_po']);
        $this->assertSame(0, $import->fresh()->summary['new_po']);
        $this->po->delete();
        [$grImport] = $service->start($this->actor, $this->workbook('GR', 1), 'GR');
        $service->preview($grImport->id);
        $this->assertSame('VALIDATION_FAILED', $grImport->fresh()->status);
        $this->assertSame(1, $grImport->fresh()->summary['unmatched_po']);
    }

    public function test_active_import_prevents_destructive_staging_rollback(): void
    {
        app(LocalProcurementImportService::class)->start($this->actor, $this->workbook('GR', 1), 'GR');
        $migration = require database_path('migrations/2026_10_08_000001_create_local_procurement_import_staging.php');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Drain or cancel active imports');
        $migration->down();
    }

    public function test_removed_routes_and_legacy_jobs_cannot_create_master_data(): void
    {
        foreach (['finance', 'purchasing'] as $role) {
            $actor = $role === 'finance' ? $this->actor : User::factory()->create(['role' => 'purchasing', 'is_active' => true]);
            foreach (['template' => 'get', 'preview' => 'post', 'confirm' => 'post'] as $action => $method) {
                $this->assertFalse(Route::has($role.'.local-procurement.import.'.$action));
                $this->actingAs($actor)->{$method}('/'.$role.'/local-procurement/import/'.$action)->assertNotFound();
            }
        }
        $service = app(LocalProcurementImportService::class);
        [$import, $token] = $service->start($this->actor, $this->workbook('PO', 1), 'PO');
        // Compatibility fixture only: this kind belonged to the removed workflow.
        $import->update(['kind' => 'COMBINED', 'status' => 'READY']);
        $this->actingAs($this->actor)->getJson(route('finance.local-procurement.imports.status', $import))
            ->assertOk()->assertJsonPath('token', null)->assertJsonPath('confirm_url', null);
        $this->postJson(route('finance.local-procurement.import.po.confirm'), ['token' => $token])->assertStatus(419);
        $service->commit($import->id);
        $service->preview($import->id);
        $this->assertSame('CANCELLED', $import->fresh()->status);
        $this->assertSame(1, $import->attachments()->count());
        $this->assertDatabaseMissing('local_purchase_orders', ['po_number' => 'PO-ROW-0']);
        $this->assertSame(0, LocalFinanceAuditLog::where('action', 'po_created')->count());
    }

    public function test_deployment_pause_blocks_admission_and_allows_existing_work_to_drain(): void
    {
        $service = app(LocalProcurementImportService::class);
        [$import, $token] = $service->start($this->actor, $this->workbook('GR', 1), 'GR');
        Cache::put('local_procurement_imports.paused', true, 60);
        $this->actingAs($this->actor)->postJson(route('finance.local-procurement.import.po.preview'), ['import_file' => $this->workbook('PO', 1)])->assertStatus(503);
        $service->preview($import->id);
        $this->assertSame('READY', $import->fresh()->status);
        $this->postJson(route('finance.local-procurement.import.gr.confirm'), ['token' => $token])->assertStatus(503);
        Cache::forget('local_procurement_imports.paused');
        $this->postJson(route('finance.local-procurement.import.gr.confirm'), ['token' => $token])->assertStatus(202);
        $service->commit($import->id);
        $this->assertSame('COMPLETED', $import->fresh()->status);
    }

    public function test_csv_and_xls_http_uploads_match_xlsx_domain_values(): void
    {
        foreach (['PO', 'GR'] as $kind) {
            foreach (['xlsx', 'csv', 'xls'] as $format) {
                $xlsx = $this->workbook($kind, 2001);
                if ($format === 'xlsx') {
                    $file = $xlsx;
                } else {
                    $book = IOFactory::load($xlsx->getPathname());
                    if ($format === 'xls') {
                        // Generate BIFF from values so format parity does not
                        // depend on cross-format style/shared-string state.
                        $values = $book->getActiveSheet()->toArray(null, false, false);
                        $book->disconnectWorksheets();
                        $book = new Spreadsheet;
                        foreach ($values as $rowIndex => $cells) {
                            foreach ($cells as $column => $value) {
                                if ($value !== null && $value !== '') {
                                    $book->getActiveSheet()->setCellValueExplicit(Coordinate::stringFromColumnIndex($column + 1).($rowIndex + 1), (string) $value, DataType::TYPE_STRING);
                                }
                            }
                        }
                        unset($values);
                    }
                    $path = Storage::disk('private')->path('format-'.uniqid().'.'.$format);
                    $writer = IOFactory::createWriter($book, $format === 'csv' ? 'Csv' : 'Xls');
                    $writer->setPreCalculateFormulas(false);
                    $writer->save($path);
                    $book->disconnectWorksheets();
                    unset($book, $writer);
                    $file = new UploadedFile($path, 'upload.'.$format, null, null, true);
                }
                $response = $this->actingAs($this->actor)->postJson(route('finance.local-procurement.import.'.strtolower($kind).'.preview'), ['import_file' => $file])->assertStatus(202);
                $import = LocalProcurementImport::latest('id')->firstOrFail();
                $this->assertStringEndsWith('.'.$format, $import->attachments()->firstOrFail()->file_path);
                $this->assertSame(LocalProcurementStreamingReader::MIME_TYPES[$format], $import->attachments()->firstOrFail()->file_type);
                app(LocalProcurementImportService::class)->preview($import->id);
                $this->assertSame('READY', $import->fresh()->status, $kind.'/'.$format.' '.($import->fresh()->failure ?? ''));
                $this->assertSame(2001, $import->fresh()->source_rows);
                $this->postJson(route('finance.local-procurement.import.'.strtolower($kind).'.confirm'), ['token' => $response->json('token')])->assertStatus(202);
                app(LocalProcurementImportService::class)->commit($import->id);
                $this->assertSame('COMPLETED', $import->fresh()->status);
            }
        }
        $this->assertSame(2002, LocalPurchaseOrder::count());
        $this->assertSame(1, LocalGoodsReceipt::count());
        $this->assertSame('2001.0000', LocalGoodsReceipt::firstOrFail()->qty);
        $this->assertSame(2001, LocalFinanceAuditLog::where('action', 'po_created')->count());
        $this->assertSame(1, LocalFinanceAuditLog::where('action', 'gr_created')->count());
    }

    private function workbook(string $kind, int $count, bool $mixed = false): UploadedFile
    {
        $path = Storage::disk('private')->path('fixture-'.uniqid().'.xlsx');
        $writer = new Writer;
        $writer->openToFile($path);
        $header = array_fill(0, 21, '');
        $writer->addRow(Row::fromValues($header));
        for ($i = 0; $i < $count; $i++) {
            $row = array_fill(0, 21, null);
            if ($kind === 'PO') {
                $row[4] = 'PO-ROW-'.$i;
                $row[6] = 'PT Import Test';
                $row[9] = '2026-10-01';
                $row[10] = '100.00';
            } elseif ($kind === 'GR') {
                $row[8] = 'Long material description';
                $row[9] = 'IMPORT-GR';
                $row[11] = 'IMPORT-PARENT';
                $row[15] = 1;
                $row[16] = $mixed && $i === 500 ? 'kg' : 'pcs';
                $row[19] = '2026-10-01';
            }
            $writer->addRow(Row::fromValues($row));
        }
        $writer->close();

        return new UploadedFile($path, 'fixture.xlsx', null, null, true);
    }
}
