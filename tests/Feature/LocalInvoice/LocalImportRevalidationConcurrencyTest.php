<?php

namespace Tests\Feature\LocalInvoice;

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

class LocalImportRevalidationConcurrencyTest extends TestCase
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

    public static function actions(): array
    {
        return [['revalidate'], ['confirm'], ['cancel'], ['cleanup']];
    }

    #[DataProvider('actions')]
    public function test_simultaneous_actions_are_fenced_and_never_write_duplicate_master_records(string $otherAction): void
    {
        Storage::fake('private');
        Queue::fake();
        $actor = User::factory()->create(['role' => 'finance']);
        $supplier = User::factory()->create();
        $supplier->supplierScopes()->create(['scope' => 'local']);
        Supplier::create(['user_id' => $supplier->id, 'company_name' => 'Recheck Race', 'category' => 'Parts', 'is_pkp' => false, 'payment_term_days' => 30]);
        LocalPurchaseOrder::create(['po_number' => 'RECHECK-RACE-PO', 'supplier_id' => $supplier->id, 'po_date' => '2026-10-01', 'total_amount' => '1000.00', 'currency' => 'IDR', 'status' => 'OPEN']);
        $path = Storage::disk('private')->path('race.xlsx');
        $writer = new Writer;
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues(array_fill(0, 21, '')));
        $row = array_fill(0, 21, null);
        $row[9] = 'RECHECK-RACE-GR';
        $row[11] = 'RECHECK-RACE-PO';
        $row[15] = '1';
        $row[16] = 'pcs';
        $row[19] = '2026-10-01';
        $writer->addRow(Row::fromValues($row));
        $writer->close();
        $service = app(LocalProcurementImportService::class);
        [$import,$token] = $service->start($actor, new UploadedFile($path, 'race.xlsx', null, null, true), 'GR');
        $service->preview($import->id);
        $import->refresh();
        $this->assertSame('READY', $import->status);
        if ($otherAction === 'cleanup') {
            $import->update(['status' => 'VALIDATION_FAILED', 'finished_at' => now()->subDays(4)]);
        }
        $oldOwner = $import->owner_job_key;
        $start = microtime(true) + 2;
        $processes = array_map(fn ($action) => new Process([PHP_BINARY, base_path('tests/Support/local-import-revalidation-worker.php'),
            (string) $actor->id, (string) $import->id, $action, (string) $start, Storage::disk('private')->path(''), (string) $import->attempt, $token], base_path(), ['APP_ENV' => 'testing']), ['revalidate', $otherAction]);
        foreach ($processes as $process) {
            $process->start();
        }
        foreach ($processes as $process) {
            $process->wait();
            $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
        }
        $outcomes = array_map(fn ($process) => trim($process->getOutput()), $processes);
        $import->refresh();
        if ($otherAction === 'cleanup') {
            $this->assertContains('accepted', $outcomes);
            $this->assertDatabaseCount('local_goods_receipts', 0);
            if ($import->status === 'QUEUED') {
                $this->assertNull($import->cleaned_at);
                $this->assertTrue(Storage::disk('private')->exists($import->attachments()->sole()->file_path));
            } else {
                $this->assertNotNull($import->cleaned_at);
                $this->assertSame(0, $import->attachments()->count());
                $this->assertContains('rejected', $outcomes);
            }
        } elseif ($otherAction === 'cancel') {
            $this->assertContains('accepted', $outcomes);
            $this->assertSame('CANCELLED', $import->status);
            $service->preview($import->id, $oldOwner);
            $this->assertSame('CANCELLED', $import->fresh()->status);
            $this->assertDatabaseCount('local_goods_receipts', 0);
        } else {
            $this->assertEqualsCanonicalizing(['accepted', 'rejected'], $outcomes);
            if ($import->status === 'QUEUED') {
                $service->preview($import->id, $oldOwner);
                $this->assertSame('QUEUED', $import->fresh()->status);
                $service->preview($import->id, $import->owner_job_key);
                $import->refresh();
                $service->confirm($actor, $service->tokenFor($import), 'GR');
            }
            $service->commit($import->id);
            $this->assertSame('COMPLETED', $import->fresh()->status);
            $this->assertDatabaseCount('local_goods_receipts', 1);
        }
    }
}
