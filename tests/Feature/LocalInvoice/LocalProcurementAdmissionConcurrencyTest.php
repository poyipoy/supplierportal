<?php

namespace Tests\Feature\LocalInvoice;

use App\Models\LocalProcurementImport;
use App\Models\Supplier;
use App\Models\SupplierScope;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class LocalProcurementAdmissionConcurrencyTest extends TestCase
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

    public static function admissionThresholds(): array
    {
        return [
            'fast path' => [10, 'READY', 0],
            'worker queued' => [0, 'QUEUED', 1],
        ];
    }

    #[DataProvider('admissionThresholds')]
    public function test_parallel_start_calls_respect_active_per_user_under_user_lock(
        int $fastPathMaxRows,
        string $expectedStatus,
        int $expectedJobs,
    ): void {
        Storage::fake('private');
        $actor = User::factory()->create(['role' => 'finance', 'is_active' => true]);
        $supplier = User::factory()->create(['role' => 'supplier', 'is_active' => true]);
        SupplierScope::create(['supplier_id' => $supplier->id, 'scope' => 'local']);
        Supplier::create([
            'user_id' => $supplier->id,
            'company_name' => 'PT Admission Race',
            'category' => 'Parts',
            'is_pkp' => false,
            'payment_term_days' => 30,
        ]);

        $fileOne = $this->workbook('RACE-PO-1');
        $fileTwo = $this->workbook('RACE-PO-2');
        try {
            $start = microtime(true) + 2;
            $privateRoot = Storage::disk('private')->path('');
            $processes = [
                new Process([PHP_BINARY, base_path('tests/Support/local-import-admission-worker.php'), (string) $actor->id, $fileOne, $privateRoot, (string) $fastPathMaxRows, (string) $start], base_path(), ['APP_ENV' => 'testing']),
                new Process([PHP_BINARY, base_path('tests/Support/local-import-admission-worker.php'), (string) $actor->id, $fileTwo, $privateRoot, (string) $fastPathMaxRows, (string) $start], base_path(), ['APP_ENV' => 'testing']),
            ];
            foreach ($processes as $process) {
                $process->start();
            }
            foreach ($processes as $process) {
                $process->wait();
                $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
            }

            $outcomes = array_map(fn (Process $process) => trim($process->getOutput()), $processes);
            $this->assertEqualsCanonicalizing(['accepted', 'rejected'], $outcomes);

            $import = LocalProcurementImport::sole();
            $this->assertSame($expectedStatus, $import->status);
            $this->assertSame($expectedJobs, DB::table('jobs')->count());
        } finally {
            @unlink($fileOne);
            @unlink($fileTwo);
        }
    }

    private function workbook(string $poNumber): string
    {
        $path = sys_get_temp_dir().'/admission-race-'.uniqid().'.xlsx';
        $header = array_fill(0, 21, '');
        $header[4] = 'Order';
        $header[6] = 'Supplier Name';
        $header[9] = 'Order Date';
        $header[10] = 'Order Amount';
        $header[16] = 'PR';

        $row = array_fill(0, 21, '');
        $row[4] = $poNumber;
        $row[6] = 'PT Admission Race';
        $row[9] = '2026-10-01';
        $row[10] = '1000.00';

        $writer = new Writer;
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues($header));
        $writer->addRow(Row::fromValues($row));
        $writer->close();

        return $path;
    }
}
