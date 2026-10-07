<?php

namespace Tests\Feature;

use App\Events\ExportProgressUpdated;
use App\Models\ExportJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdvancedExportPerformanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_fifty_thousand_invoice_rows_run_query_chunked_with_measured_resources(): void
    {
        $this->assertSame('adasi_portal_test', DB::selectOne('SELECT DATABASE() AS db')->db);
        config(['queue.default' => 'sync']);
        Storage::fake('private');
        Event::fake([ExportProgressUpdated::class]);
        $finance = User::factory()->create(['role' => 'finance']);
        $supplier = User::factory()->create(['role' => 'supplier']);
        for ($batch = 0; $batch < 100; $batch++) {
            $rows = [];
            for ($i = 0; $i < 500; $i++) {
                $number = $batch * 500 + $i;
                $rows[] = ['supplier_id' => $supplier->id, 'submission_number' => 'LSI-PERF-'.$number, 'invoice_number' => 'INV-PERF-'.$number, 'invoice_date' => '2026-08-15', 'po_number' => 'PERF-PO', 'currency' => 'IDR', 'invoice_amount' => '100000.25', 'tax_amount' => '11000.03', 'status' => 'READY_TO_PAY', 'submitted_at' => '2026-08-15 18:30:00'];
            }
            DB::table('local_invoices')->insert($rows);
        }
        unset($rows);
        gc_collect_cycles();
        memory_reset_peak_usage();
        $started = microtime(true);
        $this->actingAs($finance)->postJson(route('finance.master-invoices.export'), ['options' => ['columns' => ['submission', 'invoice', 'invoice_amount'], 'format' => 'csv']])->assertAccepted();
        $seconds = round(microtime(true) - $started, 2);
        $peak = memory_get_peak_usage(true);
        $job = ExportJob::sole();
        $this->assertSame('completed', $job->status);
        $this->assertSame(50000, $job->total_rows);
        $this->assertSame(50000, $job->processed_rows);
        $this->assertCount(100, $job->processed_chunks);
        $csv = Storage::disk('private')->get($job->file_path);
        $this->assertSame(1, substr_count($csv, "\xEF\xBB\xBF"));
        $this->assertSame(50001, substr_count($csv, "\n"));
        $this->assertStringContainsString('LSI-PERF-49999', $csv);
        $this->assertLessThan(512 * 1024 * 1024, $peak, 'Measured peak exceeds the 512 MiB benchmark budget.');
        file_put_contents(storage_path('logs/advance-export-performance.json'), json_encode(['rows' => 50000, 'columns' => 3, 'format' => 'csv', 'chunks' => count($job->processed_chunks), 'seconds' => $seconds, 'peak_bytes' => $peak, 'file_bytes' => strlen($csv), 'queue_driver' => 'sync'], JSON_PRETTY_PRINT));
    }
}
