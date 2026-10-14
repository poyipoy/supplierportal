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
use App\Services\RegionalDisplayFormatter;
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
use PHPUnit\Framework\Attributes\DataProvider;
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
        $this->assertSame('VALIDATION_FAILED', $import->fresh()->status);
        $this->assertSame(__('local_procurement.import.no_new_records'), $import->fresh()->failure);
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
            foreach (['xlsx', 'csv', 'xls'] as $formatIndex => $format) {
                $xlsx = $this->workbook($kind, 2001, offset: $formatIndex * 2001);
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
        $this->assertSame(6004, LocalPurchaseOrder::count());
        $this->assertSame(3, LocalGoodsReceipt::count());
        $this->assertSame('2001.0000', LocalGoodsReceipt::firstOrFail()->qty);
        $this->assertSame(6003, LocalFinanceAuditLog::where('action', 'po_created')->count());
        $this->assertSame(3, LocalFinanceAuditLog::where('action', 'gr_created')->count());
    }

    public function test_unfinished_previews_can_be_resumed_and_cancelled_to_free_a_shared_slot(): void
    {
        $service = app(LocalProcurementImportService::class);
        [$poImport] = $service->start($this->actor, $this->workbook('PO', 1), 'PO');
        [$grImport] = $service->start($this->actor, $this->workbook('GR', 1), 'GR');
        $service->preview($poImport->id);
        $service->preview($grImport->id);
        $this->assertSame('READY', $poImport->fresh()->status);
        $this->assertSame('READY', $grImport->fresh()->status);

        $this->actingAs($this->actor)->getJson(route('finance.local-procurement.imports.index', ['active' => 1]))
            ->assertOk()->assertJsonCount(2)->assertJsonFragment(['id' => $poImport->hash, 'kind' => 'PO', 'status' => 'READY']);
        $this->getJson(route('finance.local-procurement.imports.status', $grImport))
            ->assertOk()->assertJsonPath('status', 'READY')->assertJsonPath('token', $service->tokenFor($grImport));
        $other = User::factory()->create(['role' => 'purchasing']);
        $this->actingAs($other)->getJson(route('purchasing.local-procurement.imports.index', ['active' => 1]))
            ->assertOk()->assertExactJson([]);
        $this->postJson(route('purchasing.local-procurement.imports.cancel', $poImport))->assertForbidden();

        $this->actingAs($this->actor)->postJson(route('finance.local-procurement.imports.cancel', $poImport))
            ->assertOk()->assertJsonPath('status', 'CANCELLED');
        $this->assertSame('CANCELLED', $poImport->fresh()->status);
        [$replacement] = $service->start($this->actor, $this->workbook('PO', 1), 'PO');
        $this->assertSame('QUEUED', $replacement->status);
        $this->assertSame('READY', $grImport->fresh()->status);
    }

    public function test_active_import_list_excludes_expired_previews_and_terminal_imports(): void
    {
        $create = function (string $status, bool $expired = false): LocalProcurementImport {
            return LocalProcurementImport::create(['user_id' => $this->actor->id, 'kind' => 'GR', 'locale' => 'en',
                'status' => $status, 'token_hash' => hash('sha256', uniqid('', true)), 'checksum' => str_repeat('a', 64),
                'original_filename' => 'preview.xlsx', 'expires_at' => $expired ? now()->subHour() : now()->addHour()]);
        };
        $create('READY', true);
        $create('COMPLETED');
        $reading = $create('READING', true);
        $ready = $create('READY');
        $response = $this->actingAs($this->actor)->getJson(route('finance.local-procurement.imports.index', ['active' => 1]));
        $response->assertOk()->assertJsonCount(2)->assertJsonFragment(['id' => $reading->hash])->assertJsonFragment(['id' => $ready->hash]);
        $response->assertHeader('Cache-Control', 'no-store, private');
        // The recovery panel shows when each import was uploaded and how far it got.
        $response->assertJsonStructure([['id', 'kind', 'status', 'filename', 'status_url', 'created_at', 'processed_rows']]);
        $ready->forceFill(['processed_rows' => 544])->save();
        $entry = collect($this->getJson(route('finance.local-procurement.imports.index', ['active' => 1]))->json())->firstWhere('id', $ready->hash);
        $this->assertSame(544, $entry['processed_rows']);
        $this->assertSame(app(RegionalDisplayFormatter::class)->timestamp($ready->created_at), $entry['created_at']);
    }

    public static function importKinds(): array
    {
        return [['PO'], ['GR']];
    }

    #[DataProvider('importKinds')]
    public function test_reimporting_identical_data_blocks_preview_and_preserves_master_and_audits(string $kind): void
    {
        $service = app(LocalProcurementImportService::class);
        [$first, $token] = $this->readyImport($kind, 1);
        $service->confirm($this->actor, $token, $kind);
        $service->commit($first->id);
        $this->assertSame('COMPLETED', $first->fresh()->status);
        $masters = [LocalPurchaseOrder::count(), LocalGoodsReceipt::count()];
        $audits = LocalFinanceAuditLog::whereIn('action', ['po_created', 'gr_created', 'po_import_confirmed', 'gr_import_confirmed'])->count();

        [$repeat, $repeatToken] = $service->start($this->actor, $this->workbook($kind, 1, distinctGr: true), $kind);
        $service->preview($repeat->id);
        $repeat->refresh();
        $key = strtolower($kind);
        $this->assertSame('VALIDATION_FAILED', $repeat->status);
        $this->assertSame(__('local_procurement.import.no_new_records'), $repeat->failure);
        $this->assertSame(0, $repeat->summary['new_'.$key]);
        $this->assertSame(1, $repeat->summary['existing_'.$key]);
        $this->assertSame(0, $repeat->summary['conflicts']);
        $this->assertSame(0, $repeat->summary['invalid']);
        $this->assertNotNull($repeat->finished_at);
        $this->assertSame(1, DB::table('local_procurement_import_records')->where('import_id', $repeat->id)->count());
        $this->actingAs($this->actor)->getJson(route('finance.local-procurement.imports.status', $repeat))
            ->assertOk()->assertJsonPath('token', null)->assertJsonPath('preview.rows.0.action', 'EXISTING');
        $this->postJson(route('finance.local-procurement.import.'.$key.'.confirm'), ['token' => $repeatToken])->assertStatus(409);

        // A replay of the completed batch remains idempotent.
        $service->confirm($this->actor, $token, $kind);
        $service->commit($first->id);
        $this->assertSame($masters, [LocalPurchaseOrder::count(), LocalGoodsReceipt::count()]);
        $this->assertSame($audits, LocalFinanceAuditLog::whereIn('action', ['po_created', 'gr_created', 'po_import_confirmed', 'gr_import_confirmed'])->count());
        $previewAudit = LocalFinanceAuditLog::where('action', $key.'_import_previewed')->latest('id')->firstOrFail();
        $this->assertFalse($previewAudit->metadata['valid']);

        // Rejected duplicate previews do not consume an active slot.
        [$next] = $service->start($this->actor, $this->workbook($kind, 1, distinctGr: true, offset: 10), $kind);
        $this->assertSame('QUEUED', $next->status);
    }

    #[DataProvider('importKinds')]
    public function test_legacy_ready_preview_without_new_data_or_valid_summary_never_dispatches(string $kind): void
    {
        $key = 'new_'.strtolower($kind);
        foreach (['finance', 'purchasing'] as $role) {
            $this->actor->update(['role' => $role]);
            [$import, $token] = $this->readyImport($kind, 1);
            foreach ([[$key => 0], null, [], [$key => '1'], [$key => -1]] as $summary) {
                $import->update(['summary' => $summary]);
                $response = $this->actingAs($this->actor)->postJson(route($role.'.local-procurement.import.'.strtolower($kind).'.confirm'), ['token' => $token]);
                if ($summary === [$key => 0]) {
                    $response->assertStatus(422)->assertJsonValidationErrors('import_file');
                    $this->assertSame(__('local_procurement.import.no_new_records'), $response->json('errors.import_file.0'));
                } else {
                    $response->assertStatus(409);
                }
                $this->assertSame('READY', $import->fresh()->status);
                Queue::assertNotPushed(ProcessLocalProcurementImport::class, fn ($job) => $job->importId === $import->id && $job->stage === 'confirm');
            }
            $import->update(['status' => 'CANCELLED']);
        }
    }

    #[DataProvider('importKinds')]
    public function test_worker_rejects_a_second_preview_when_all_records_became_existing(string $kind): void
    {
        $service = app(LocalProcurementImportService::class);
        [$first, $firstToken] = $this->readyImport($kind, 1);
        [$second, $secondToken] = $this->readyImport($kind, 1);
        $service->confirm($this->actor, $firstToken, $kind);
        $service->commit($first->id);
        $masters = [LocalPurchaseOrder::count(), LocalGoodsReceipt::count()];
        $audits = LocalFinanceAuditLog::count();
        $service->confirm($this->actor, $secondToken, $kind);
        $service->commit($second->id);
        $this->assertSame('VALIDATION_FAILED', $second->fresh()->status);
        $this->assertSame(__('local_procurement.import.no_new_records'), $second->fresh()->failure);
        $this->assertSame($masters, [LocalPurchaseOrder::count(), LocalGoodsReceipt::count()]);
        $this->assertSame($audits, LocalFinanceAuditLog::count());
        $this->assertSame(1, LocalFinanceAuditLog::where('action', strtolower($kind).'_import_confirmed')->count());
        $service->commit($second->id);
        $this->assertSame($audits, LocalFinanceAuditLog::count());
    }

    #[DataProvider('importKinds')]
    public function test_mixed_import_skips_existing_records_and_reports_actual_counts(string $kind): void
    {
        $service = app(LocalProcurementImportService::class);
        [$first, $firstToken] = $this->readyImport($kind, 1);
        $service->confirm($this->actor, $firstToken, $kind);
        $service->commit($first->id);
        [$mixed, $token] = $this->readyImport($kind, 2);
        $this->assertSame(1, $mixed->fresh()->summary['new_'.strtolower($kind)]);
        $this->assertSame(1, $mixed->fresh()->summary['existing_'.strtolower($kind)]);
        $service->confirm($this->actor, $token, $kind);
        $service->commit($mixed->id);
        $this->assertCompletedCounts($mixed, $kind, 1, 1);
    }

    #[DataProvider('importKinds')]
    public function test_worker_reconciles_preview_counts_after_another_import_commits(string $kind): void
    {
        $service = app(LocalProcurementImportService::class);
        [$first, $firstToken] = $this->readyImport($kind, 1);
        [$mixed, $mixedToken] = $this->readyImport($kind, 2);
        $this->assertSame(2, $mixed->fresh()->summary['new_'.strtolower($kind)]);
        $service->confirm($this->actor, $firstToken, $kind);
        $service->commit($first->id);
        $service->confirm($this->actor, $mixedToken, $kind);
        $service->commit($mixed->id);
        $this->assertCompletedCounts($mixed, $kind, 1, 1);
    }

    #[DataProvider('importKinds')]
    public function test_conflicting_existing_data_blocks_the_entire_file(string $kind): void
    {
        $service = app(LocalProcurementImportService::class);
        [$first, $token] = $this->readyImport($kind, 1);
        $service->confirm($this->actor, $token, $kind);
        $service->commit($first->id);
        if ($kind === 'PO') {
            LocalPurchaseOrder::where('po_number', 'PO-ROW-0')->update(['total_amount' => '101.00']);
        } else {
            LocalGoodsReceipt::where('gr_number', 'IMPORT-GR-0')->update(['qty' => '2.0000']);
        }
        $masters = [LocalPurchaseOrder::count(), LocalGoodsReceipt::count()];
        [$conflict, $token] = $service->start($this->actor, $this->workbook($kind, 2, distinctGr: true), $kind);
        $service->preview($conflict->id);
        $this->assertSame('VALIDATION_FAILED', $conflict->fresh()->status);
        $this->assertGreaterThan(0, $conflict->fresh()->summary['conflicts']);
        $this->assertNull($conflict->fresh()->failure);
        $this->actingAs($this->actor)->postJson(route('finance.local-procurement.import.'.strtolower($kind).'.confirm'), ['token' => $token])->assertStatus(409);
        $this->assertSame($masters, [LocalPurchaseOrder::count(), LocalGoodsReceipt::count()]);
    }

    #[DataProvider('importKinds')]
    public function test_existing_first_batch_does_not_block_new_records_in_later_batches(string $kind): void
    {
        $service = app(LocalProcurementImportService::class);
        [$first, $firstToken] = $this->readyImport($kind, 500);
        $service->confirm($this->actor, $firstToken, $kind);
        $service->commit($first->id);
        [$mixed, $mixedToken] = $this->readyImport($kind, 501, lastNumber: 'ZZZ-NEW');
        $actions = DB::table('local_procurement_import_records')->where('import_id', $mixed->id)
            ->orderBy('id')->pluck('values')->map(fn ($values) => json_decode($values, true)['action'])->all();
        $this->assertSame(array_fill(0, 500, 'EXISTING'), array_slice($actions, 0, 500));
        $this->assertSame('NEW', $actions[500]);
        $service->confirm($this->actor, $mixedToken, $kind);
        $service->commit($mixed->id);
        $this->assertCompletedCounts($mixed, $kind, 1, 500);
    }

    private function readyImport(string $kind, int $count, ?string $lastNumber = null): array
    {
        $service = app(LocalProcurementImportService::class);
        [$import, $token] = $service->start($this->actor, $this->workbook($kind, $count, distinctGr: true, lastNumber: $lastNumber), $kind);
        $service->preview($import->id);
        $this->assertSame('READY', $import->fresh()->status, $import->fresh()->failure ?? '');

        return [$import, $token];
    }

    private function assertCompletedCounts(LocalProcurementImport $import, string $kind, int $created, int $existing): void
    {
        $import->refresh();
        $this->assertSame('COMPLETED', $import->status, $import->failure ?? '');
        $this->assertSame($created, $import->summary['new_'.strtolower($kind)]);
        $this->assertSame($existing, $import->summary['existing_'.strtolower($kind)]);
        $this->assertSame($created, $import->summary['new'.ucfirst(strtolower($kind))]);
        $this->assertSame($existing, $import->summary['existing'.ucfirst(strtolower($kind))]);
    }

    private function workbook(string $kind, int $count, bool $mixed = false, bool $distinctGr = false, int $offset = 0, ?string $lastNumber = null): UploadedFile
    {
        $path = Storage::disk('private')->path('fixture-'.uniqid().'.xlsx');
        $writer = new Writer;
        $writer->openToFile($path);
        $header = array_fill(0, 21, '');
        $writer->addRow(Row::fromValues($header));
        for ($i = 0; $i < $count; $i++) {
            $row = array_fill(0, 21, null);
            if ($kind === 'PO') {
                $row[4] = 'PO-ROW-'.($offset + $i);
                $row[6] = 'PT Import Test';
                $row[9] = '2026-10-01';
                $row[10] = '100.00';
            } elseif ($kind === 'GR') {
                $row[8] = 'Long material description';
                $row[9] = $distinctGr ? 'IMPORT-GR-'.($offset + $i) : 'IMPORT-GR'.($offset ? '-'.$offset : '');
                $row[11] = 'IMPORT-PARENT';
                $row[15] = 1;
                $row[16] = $mixed && $i === 500 ? 'kg' : 'pcs';
                $row[19] = '2026-10-01';
            }
            if ($lastNumber !== null && $i === $count - 1) {
                $row[$kind === 'PO' ? 4 : 9] = $lastNumber;
            }
            $writer->addRow(Row::fromValues($row));
        }
        $writer->close();

        return new UploadedFile($path, 'fixture.xlsx', null, null, true);
    }
}
