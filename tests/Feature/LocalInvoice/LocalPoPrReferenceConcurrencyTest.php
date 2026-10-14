<?php

namespace Tests\Feature\LocalInvoice;

use App\Models\LocalFinanceAuditLog;
use App\Models\LocalProcurementImport;
use App\Models\LocalPurchaseOrder;
use App\Models\Supplier;
use App\Models\User;
use App\Services\LocalInvoice\LocalProcurementImportService;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class LocalPoPrReferenceConcurrencyTest extends TestCase
{
    use DatabaseTruncation;

    // Retain the migration-owned singleton used to serialize PO ZIP admission.
    protected $exceptTables = ['local_po_document_capacity'];

    protected function beforeTruncatingDatabase(): void
    {
        $this->assertSame('adasi_portal_test', DB::connection()->getDatabaseName());
    }

    protected function tearDown(): void
    {
        $this->truncateTablesForAllConnections();
        parent::tearDown();
    }

    public static function concurrentPrValues(): array
    {
        return ['same reference' => ['pr-race', false], 'different reference' => ['PR-OTHER', true]];
    }

    #[DataProvider('concurrentPrValues')]
    public function test_two_import_workers_fill_existing_po_once_and_roll_back_conflicting_file(string $secondPr, bool $conflicts): void
    {
        Storage::fake('private');
        Queue::fake();
        $supplier = User::factory()->create();
        $supplier->supplierScopes()->create(['scope' => 'local']);
        Supplier::create(['user_id' => $supplier->id, 'company_name' => 'PR Race Vendor', 'category' => 'Parts', 'is_pkp' => false, 'payment_term_days' => 30]);
        $po = LocalPurchaseOrder::create(['po_number' => 'A-PR-RACE', 'supplier_id' => $supplier->id, 'po_date' => '2026-10-01', 'total_amount' => '1000.00', 'currency' => 'IDR', 'status' => 'OPEN', 'source' => 'MANUAL']);
        $service = app(LocalProcurementImportService::class);
        $imports = [];
        foreach (['PR-RACE', $secondPr] as $index => $pr) {
            // Distinct actors avoid serializing both workers on the same user lock.
            $actor = User::factory()->create(['role' => 'finance']);
            $path = Storage::disk('private')->path('pr-race-'.$index.'.xlsx');
            $writer = new Writer;
            $writer->openToFile($path);
            $header = array_fill(0, 17, '');
            $header[16] = 'PR';
            $writer->addRow(Row::fromValues($header));
            foreach (['A-PR-RACE', 'Z-PR-RACE-NEW-'.$index] as $number) {
                $row = array_fill(0, 17, '');
                $row[4] = $number;
                $row[6] = 'PR Race Vendor';
                $row[9] = '2026-10-01';
                $row[10] = '1000.00';
                $row[16] = $pr;
                $writer->addRow(Row::fromValues($row));
            }
            $writer->close();
            [$import, $token] = $service->start($actor, new UploadedFile($path, 'race.xlsx', null, null, true), 'PO');
            $service->preview($import->id);
            $this->assertSame('READY', $import->fresh()->status);
            $service->confirm($actor, $token, 'PO');
            $imports[] = $import;
        }
        $start = microtime(true) + 2;
        $processes = array_map(fn ($import) => new Process([PHP_BINARY, base_path('tests/Support/local-po-pr-import-worker.php'),
            (string) $import->id, (string) $start, Storage::disk('private')->path('')], base_path(), ['APP_ENV' => 'testing']), $imports);
        try {
            foreach ($processes as $process) {
                $process->start();
            }
            foreach ($processes as $process) {
                $process->wait();
                $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
            }
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop();
                }
            }
        }
        $imports = LocalProcurementImport::whereIn('id', array_column($imports, 'id'))->get();
        $completed = $imports->where('status', 'COMPLETED');
        $this->assertSame($conflicts ? 1 : 2, $completed->count());
        $this->assertSame($conflicts ? 1 : 0, $imports->where('status', 'VALIDATION_FAILED')->count());
        $this->assertSame(1, $completed->sum(fn ($import) => $import->summary['filledPoPr']));
        $this->assertSame($conflicts ? 2 : 3, LocalPurchaseOrder::count());
        $this->assertContains(mb_strtolower($po->fresh()->pr_number), ['pr-race', mb_strtolower($secondPr)]);
        $this->assertSame($conflicts ? 1 : 2, LocalFinanceAuditLog::where('action', 'po_import_confirmed')->count());
        foreach ($completed as $import) {
            $this->assertSame(1, $import->summary['newPo']);
            $this->assertSame($import->summary['filledPoPr'], $import->summary['po_pr_filled']);
        }
    }
}
