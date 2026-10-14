<?php

namespace Tests\Feature\LocalInvoice;

use App\Jobs\ProcessLocalProcurementImport;
use App\Models\LocalFinanceAuditLog;
use App\Models\LocalProcurementImport;
use App\Models\LocalPurchaseOrder;
use App\Models\Supplier;
use App\Models\SupplierScope;
use App\Models\User;
use App\Services\LocalInvoice\LocalProcurementImportProgress;
use App\Services\LocalInvoice\LocalProcurementImportService;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class LocalImportProgressTest extends TestCase
{
    use DatabaseTruncation;

    private string $root;

    private User $actor;

    private LocalProcurementImport $import;

    protected function beforeTruncatingDatabase(): void
    {
        $this->assertSame('adasi_portal_test', DB::connection()->getDatabaseName());
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = storage_path('framework/testing/import-progress-'.Str::uuid());
        File::ensureDirectoryExists($this->root);
        config(['cache.stores.file.path' => $this->root]);
        Cache::purge('file');
        $this->actor = User::factory()->create(['role' => 'finance', 'is_active' => true]);
        $this->import = LocalProcurementImport::create(['user_id' => $this->actor->id, 'kind' => 'PO', 'locale' => 'en',
            'status' => 'IMPORTING', 'token_hash' => hash('sha256', Str::random(40)), 'checksum' => str_repeat('a', 64),
            'original_filename' => 'progress.xlsx', 'expires_at' => now()->addHour(), 'attempt' => 1,
            'source_rows' => 501, 'processed_rows' => 501, 'owner_job_key' => (string) Str::uuid(),
            'summary' => ['unique_pos' => 501, 'new_po' => 501, 'existing_po' => 0]]);
    }

    protected function tearDown(): void
    {
        $this->truncateTablesForAllConnections();
        File::deleteDirectory($this->root);
        parent::tearDown();
    }

    public function test_status_and_list_report_telemetry_without_loading_preview_and_reject_other_owners(): void
    {
        $progress = app(LocalProcurementImportProgress::class);
        $progress->write($this->import, 'SAVING', 500, 501, 'execution-a');
        $url = route('finance.local-procurement.imports.status', $this->import).'?progress_only=1';
        $response = $this->actingAs($this->actor)->getJson($url)->assertOk()
            ->assertJsonPath('progress.current', 500)->assertJsonPath('progress.total', 501)->assertJsonPath('progress.percent', 99);
        $this->assertArrayNotHasKey('preview', $response->json());
        $this->getJson(route('finance.local-procurement.imports.index').'?active=1')->assertOk()->assertJsonPath('0.progress.current', 500);
        $other = User::factory()->create(['role' => 'finance', 'is_active' => true]);
        $this->actingAs($other)->getJson($url)->assertForbidden();
        $this->import->update(['owner_job_key' => (string) Str::uuid()]);
        $this->assertNull($progress->read($this->import)['percent']);
        $this->assertSame('AWAITING_PROGRESS', $progress->read($this->import)['phase']);
    }

    public function test_finalizing_missing_expired_and_terminal_progress_never_publish_false_success(): void
    {
        $progress = app(LocalProcurementImportProgress::class);
        $progress->write($this->import, 'FINALIZING', 501, 501, 'execution-a');
        $this->assertNull($progress->read($this->import)['percent']);
        $this->travel(61)->seconds();
        $this->assertTrue($progress->read($this->import)['stale']);
        $this->travel(3600)->seconds();
        $this->assertSame('AWAITING_PROGRESS', $progress->read($this->import)['phase']);
        $this->import->update(['status' => 'FAILED']);
        $this->assertSame('none', $progress->read($this->import)['mode']);
        $this->import->update(['status' => 'COMPLETED', 'summary' => ['newPo' => 501, 'existingPo' => 0]]);
        $this->assertSame(100, $progress->read($this->import)['percent']);
        $this->assertSame(501, $progress->read($this->import)['current']);
    }

    public function test_failed_job_cleans_its_snapshot_without_affecting_a_new_owner(): void
    {
        $progress = app(LocalProcurementImportProgress::class);
        $progress->write($this->import, 'SAVING', 500, 501, 'execution-a');
        $job = new ProcessLocalProcurementImport($this->import->id, 'confirm');
        $job->runKey = 'old-owner';
        $job->failed(new \RuntimeException('Older delivery.'));
        $this->assertSame('IMPORTING', $this->import->fresh()->status);
        $this->assertSame(500, $progress->read($this->import)['current']);
        $job->runKey = $this->import->owner_job_key;
        $job->failed(new \RuntimeException('Worker failure.'));
        $this->assertSame('FAILED', $this->import->fresh()->status);
        $this->import->update(['status' => 'IMPORTING']);
        $this->assertSame('AWAITING_PROGRESS', $progress->read($this->import)['phase']);
    }

    public function test_cache_and_diagnostic_failures_do_not_abort_the_save(): void
    {
        $supplier = User::factory()->create(['role' => 'supplier', 'is_active' => true]);
        SupplierScope::create(['supplier_id' => $supplier->id, 'scope' => 'local']);
        Supplier::create(['user_id' => $supplier->id, 'company_name' => 'Telemetry Supplier', 'category' => 'Parts']);
        DB::table('local_procurement_import_records')->insert(['import_id' => $this->import->id, 'attempt' => 1, 'kind' => 'PO',
            'document_key' => 'telemetry-po', 'source_row' => 1, 'source_count' => 1, 'errors' => '[]',
            'values' => json_encode(['_row' => 1, 'po_number' => 'TELEMETRY-PO', 'supplier_name' => 'Telemetry Supplier',
                'supplier_id' => $supplier->id, 'po_amount' => '1000.00', 'po_date' => '2026-10-01', 'action' => 'NEW'])]);
        Cache::shouldReceive('store')->with('file')->andThrow(new \RuntimeException('Injected telemetry failure.'));
        Log::shouldReceive('warning')->andThrow(new \RuntimeException('Injected diagnostic failure.'));
        app(LocalProcurementImportService::class)->commit($this->import->id, $this->import->owner_job_key);
        $this->assertSame('COMPLETED', $this->import->fresh()->status);
        $this->assertSame(1, LocalPurchaseOrder::count());
        $this->assertSame(1, LocalFinanceAuditLog::where('action', 'po_created')->count());
    }

    public static function outcomes(): array
    {
        return [['complete'], ['fail'], ['retry']];
    }

    #[DataProvider('outcomes')]
    public function test_http_observes_live_worker_progress_before_commit_and_final_outcome(string $mode): void
    {
        $supplier = User::factory()->create(['role' => 'supplier', 'is_active' => true]);
        SupplierScope::create(['supplier_id' => $supplier->id, 'scope' => 'local']);
        Supplier::create(['user_id' => $supplier->id, 'company_name' => 'Progress Supplier', 'category' => 'Parts']);
        $rows = [];
        foreach (range(1, 501) as $number) {
            $rows[] = ['import_id' => $this->import->id, 'attempt' => 1, 'kind' => 'PO', 'document_key' => 'progress-po-'.$number,
                'source_row' => $number, 'source_count' => 1, 'errors' => '[]', 'values' => json_encode(['_row' => $number,
                    'po_number' => 'PROGRESS-PO-'.$number, 'supplier_name' => 'Progress Supplier', 'supplier_id' => $supplier->id,
                    'po_amount' => '1000.00', 'po_date' => '2026-10-01', 'action' => 'NEW'])];
        }
        DB::table('local_procurement_import_records')->insert($rows);
        $process = new Process([PHP_BINARY, base_path('tests/Support/local-import-progress-worker.php'), (string) $this->import->id, $this->root, $mode], base_path(), ['APP_ENV' => 'testing']);
        $process->setTimeout(45);
        $process->start();
        try {
            $this->waitFor($process, 'ready');
            $url = route('finance.local-procurement.imports.status', $this->import).'?progress_only=1';
            $before = $this->actingAs($this->actor)->getJson($url)->assertOk()->assertJsonPath('status', 'IMPORTING')
                ->assertJsonPath('progress.current', 500)->assertJsonPath('progress.total', 501);
            $this->assertSame(0, LocalPurchaseOrder::count());
            $this->assertSame(0, LocalFinanceAuditLog::count());
            file_put_contents($this->root.'/release', 'release');
            if ($mode === 'retry') {
                $this->waitFor($process, 'ready.retry');
                $again = $this->getJson($url)->assertOk()->assertJsonPath('progress.current', 0);
                $this->assertNotSame($before->json('progress.execution'), $again->json('progress.execution'));
                file_put_contents($this->root.'/release.retry', 'release');
            }
            $process->wait();
            $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
            $this->getJson($url)->assertOk()->assertJsonPath('status', $mode === 'fail' ? 'FAILED' : 'COMPLETED')
                ->assertJsonPath('progress.percent', $mode === 'fail' ? null : 100);
            $this->assertSame($mode === 'fail' ? 0 : 501, LocalPurchaseOrder::count());
            $this->assertSame($mode === 'fail' ? 0 : 501, LocalFinanceAuditLog::where('action', 'po_created')->count());
        } finally {
            if ($process->isRunning()) {
                $process->stop();
            }
        }
    }

    private function waitFor(Process $process, string $marker): void
    {
        $deadline = microtime(true) + 20;
        while (! is_file($this->root.'/'.$marker)) {
            $this->assertTrue($process->isRunning(), $process->getErrorOutput().$process->getOutput());
            if (microtime(true) > $deadline) {
                $this->fail('Worker did not reach the progress barrier.');
            }
            usleep(20000);
        }
    }
}
