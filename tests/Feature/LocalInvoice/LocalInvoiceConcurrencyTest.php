<?php

namespace Tests\Feature\LocalInvoice;

use App\Models\LocalInvoice;
use App\Models\LocalInvoiceReceipt;
use App\Models\Supplier;
use App\Models\User;
use App\Services\LocalInvoice\InvoiceSubmissionService;
use App\Services\LocalInvoice\InvoiceWorkflowService;
use App\Services\LocalInvoice\LocalProcurementMasterService;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Tests\Support\NativeFileFixtures;
use Tests\TestCase;

class LocalInvoiceConcurrencyTest extends TestCase
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

    public function test_resubmit_racing_a_submit_cannot_exceed_the_po_ceiling(): void
    {
        Storage::fake('private');
        Notification::fake();
        $supplier = User::factory()->create();
        $supplier->supplierScopes()->create(['scope' => 'local']);
        Supplier::create(['user_id' => $supplier->id, 'company_name' => 'Ceiling Race', 'address' => 'Test', 'phone' => '1', 'npwp' => '1', 'category' => 'Test', 'payment_term_days' => 30]);
        $operator = User::factory()->create(['role' => 'finance']);
        $masters = app(LocalProcurementMasterService::class);
        $po = $masters->createPurchaseOrder($operator, ['supplier_id' => $supplier->id, 'po_number' => 'PO-CEIL-1', 'po_date' => '2026-09-01', 'total_amount' => '1000.00']);
        $grs = [];
        foreach ([1, 2] as $i) {
            $grs[$i] = $masters->createGoodsReceipt($operator, $po, ['gr_number' => 'GR-CEIL-'.$i, 'gr_date' => '2026-09-02', 'uom' => 'pcs', 'qty' => 1.0]);
        }
        $files = fn () => ['invoice' => NativeFileFixtures::upload('invoice.pdf'), 'tax_invoice' => NativeFileFixtures::upload('tax.pdf')];
        $invoice = app(InvoiceSubmissionService::class)->submit($supplier, ['invoice_number' => 'CEIL-X', 'invoice_date' => '2026-09-10', 'local_purchase_order_id' => $po->id, 'goods_receipt_ids' => [$grs[1]->id], 'invoice_amount' => '100.00', 'tax_amount' => '0.00', 'ppn_scheme' => '0%'], $files());
        $invoice->update(['status' => LocalInvoice::STATUS_NEED_REVISION]);
        $invoice->statusHistories()->create(['from_status' => LocalInvoice::STATUS_WAITING_PHYSICAL_DOCUMENT, 'to_status' => LocalInvoice::STATUS_NEED_REVISION, 'actor_id' => $operator->id, 'event' => 'revision_requested', 'notes' => 'Revise', 'created_at' => now()]);

        // Raise X to 900 while a new invoice Y claims 900 of the same 1000 ceiling.
        $start = microtime(true) + 3;
        $jobs = [
            [(string) $invoice->id, 'resubmit-po', $grs[1]->id],
            ['CEIL-Y', 'submit-po', $grs[2]->id],
        ];
        $processes = array_map(fn ($job) => new Process([PHP_BINARY, base_path('tests/Support/local-invoice-concurrency-worker.php'), (string) $supplier->id, $job[0], $job[1], Storage::disk('private')->path(''), (string) $start, (string) $po->id, (string) $job[2], '900.00'], base_path(), ['APP_ENV' => 'testing']), $jobs);
        foreach ($processes as $process) {
            $process->start();
        }
        foreach ($processes as $process) {
            $process->wait();
            $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
        }

        $this->assertEqualsCanonicalizing(['accepted', 'rejected'], array_map(fn ($process) => trim($process->getOutput()), $processes));
        $active = LocalInvoice::where('local_purchase_order_id', $po->id)
            ->whereNotIn('status', [LocalInvoice::STATUS_REJECTED, LocalInvoice::STATUS_CANCELLED])
            ->sum('invoice_amount');
        $this->assertLessThanOrEqual(0, bccomp((string) $active, '1000.00', 2), "Active DPP {$active} exceeds the 1000.00 PO ceiling.");
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
            // Each submission needs its own authoritative PO and available whole GR.
            $masters = app(LocalProcurementMasterService::class);
            $po = $masters->createPurchaseOrder($operator, ['supplier_id' => $supplier->id, 'po_number' => 'PO-RACE-'.$iteration, 'po_date' => '2026-09-01', 'total_amount' => '1000.00']);
            $gr = $masters->createGoodsReceipt($operator, $po, ['gr_number' => 'GR-RACE-'.$iteration, 'gr_date' => '2026-09-02', 'uom' => 'pcs', 'qty' => 1.0]);
            $invoice = app(InvoiceSubmissionService::class)->submit($supplier, ['invoice_number' => 'RACE-'.$iteration, 'invoice_date' => today()->format('Y-m-d'), 'local_purchase_order_id' => $po->id, 'goods_receipt_ids' => [$gr->id], 'invoice_amount' => '1.00', 'tax_amount' => '0.00', 'ppn_scheme' => '0%'], ['invoice' => NativeFileFixtures::upload('invoice.pdf'), 'tax_invoice' => NativeFileFixtures::upload('tax.pdf')]);
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
