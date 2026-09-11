<?php

namespace Tests\Feature\LocalInvoice;

use App\Models\LocalInvoice;
use App\Models\LocalInvoiceReceipt;
use App\Models\Supplier;
use App\Models\User;
use App\Services\LocalInvoice\InvoiceSubmissionService;
use App\Services\LocalInvoice\InvoiceWorkflowService;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class LocalInvoiceConcurrencyTest extends TestCase
{
    use DatabaseTruncation;

    protected function beforeTruncatingDatabase(): void
    {
        $this->assertSame('adasi_portal_test', DB::connection()->getDatabaseName());
    }

    protected function tearDown(): void
    {
        $this->truncateTablesForAllConnections();
        parent::tearDown();
    }

    public function test_simultaneous_submissions_generate_distinct_submission_and_receipt_numbers(): void
    {
        Notification::fake();
        Storage::fake('private');
        $suppliers = [];
        for ($i = 0; $i < 2; $i++) {
            $supplier = User::factory()->create();
            $supplier->supplierScopes()->create(['scope' => 'local']);
            Supplier::create(['user_id' => $supplier->id, 'company_name' => 'Sequence QA', 'address' => 'Test', 'phone' => '1', 'npwp' => '1', 'category' => 'Test', 'payment_term_days' => 30]);
            $suppliers[] = $supplier;
        }
        $start = microtime(true) + 3;
        $processes = array_map(fn ($supplier) => new Process([PHP_BINARY, base_path('tests/Support/local-invoice-concurrency-worker.php'), (string) $supplier->id, 'SAME-INVOICE-NUMBER', 'submit', Storage::disk('private')->path(''), (string) $start], base_path(), ['APP_ENV' => 'testing']), $suppliers);
        foreach ($processes as $process) {
            $process->start();
        }
        foreach ($processes as $process) {
            $process->wait();
            $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
            $this->assertSame('accepted', trim($process->getOutput()));
        }
        $this->assertSame(2, LocalInvoice::distinct()->count('submission_number'));
        $this->assertSame(2, LocalInvoiceReceipt::distinct()->count('receipt_number'));
    }

    public function test_conflicting_transitions_from_two_connections_have_one_winner(): void
    {
        $this->assertSame('adasi_portal_test', DB::connection()->getDatabaseName());
        Storage::fake('private');
        Notification::fake();
        $supplier = User::factory()->create();
        $supplier->supplierScopes()->create(['scope' => 'local']);
        Supplier::create(['user_id' => $supplier->id, 'company_name' => 'Concurrency', 'address' => 'Test', 'phone' => '1', 'npwp' => '1', 'category' => 'Test', 'payment_term_days' => 30]);
        $operator = User::factory()->create(['role' => 'finance']);
        foreach ([['approve', 'approve'], ['approve', 'requestRevision']] as $iteration => $actions) {
            $invoice = app(InvoiceSubmissionService::class)->submit($supplier, ['invoice_number' => 'RACE-'.$iteration, 'invoice_date' => today()->format('Y-m-d'), 'po_number' => 'Manual', 'invoice_amount' => '1.00', 'tax_amount' => '0.00'], ['invoice' => UploadedFile::fake()->image('invoice.png'), 'tax_invoice' => UploadedFile::fake()->image('tax.png')]);
            app(InvoiceWorkflowService::class)->act($operator, $invoice, 'verifyPhysical');
            $start = microtime(true) + 3;
            $processes = array_map(fn ($action) => new Process([PHP_BINARY, base_path('tests/Support/local-invoice-concurrency-worker.php'), (string) $operator->id, (string) $invoice->id, $action, Storage::disk('private')->path(''), (string) $start], base_path(), ['APP_ENV' => 'testing']), $actions);
            foreach ($processes as $process) {
                $process->start();
            }
            foreach ($processes as $process) {
                $process->wait();
                $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
            }
            $this->assertEqualsCanonicalizing(['accepted', 'rejected'], array_map(fn ($process) => trim($process->getOutput()), $processes));
            $this->assertSame(3, $invoice->statusHistories()->count());
        }
    }
}
