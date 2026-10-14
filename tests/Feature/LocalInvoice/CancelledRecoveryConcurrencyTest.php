<?php

namespace Tests\Feature\LocalInvoice;

use App\Models\LocalFinanceAuditLog;
use App\Models\LocalGoodsReceipt;
use App\Models\LocalInvoiceGoodsReceipt;
use App\Models\Supplier;
use App\Models\User;
use App\Services\LocalInvoice\InvoiceSubmissionService;
use App\Services\LocalInvoice\LocalProcurementMasterService;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Support\NativeFileFixtures;
use Tests\TestCase;

class CancelledRecoveryConcurrencyTest extends TestCase
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

    public static function types(): array
    {
        return [['po'], ['gr'], ['invoice']];
    }

    #[DataProvider('types')]
    public function test_double_recovery_has_one_winner_and_one_history_change(string $kind): void
    {
        [$supplier, $finance, $po, $gr] = $this->fixtures();
        $actor = $finance;
        $target = $kind === 'po' ? $po : $gr;
        if ($kind === 'po') {
            $po->update(['status' => 'CANCELLED']);
        } elseif ($kind === 'gr') {
            $gr->update(['status' => 'CANCELLED']);
        } else {
            $actor = $supplier;
            $service = app(InvoiceSubmissionService::class);
            $target = $service->submit($supplier, ['invoice_number' => 'INV-RACE-RESTORE', 'invoice_date' => '2026-10-01',
                'local_purchase_order_id' => $po->id, 'goods_receipt_ids' => [$gr->id],
                'invoice_amount' => '100.00', 'tax_amount' => '0.00', 'ppn_scheme' => '0%'],
                ['invoice' => NativeFileFixtures::upload('invoice.pdf')]);
            $service->cancel($supplier, $target);
        }
        $this->assertEqualsCanonicalizing(['accepted', 'rejected'], $this->race($actor, [[$target->id, $kind], [$target->id, $kind]]));
        if ($kind === 'invoice') {
            $this->assertSame(2, $target->revisions()->count());
            $this->assertSame(1, $target->statusHistories()->where('event', 'resubmitted')->count());
            $this->assertSame(1, LocalInvoiceGoodsReceipt::where('local_invoice_id', $target->id)->where('state', 'RESERVED')->count());
        } else {
            $this->assertSame(1, LocalFinanceAuditLog::where('action', $kind.'_restored')->count());
        }
    }

    public function test_po_cancel_racing_gr_restore_never_leaves_available_gr_under_cancelled_po(): void
    {
        [, $finance, $po, $gr] = $this->fixtures();
        $gr->update(['status' => 'CANCELLED']);
        $outcomes = $this->race($finance, [[$po->id, 'cancel-po'], [$gr->id, 'gr']]);
        $this->assertContains('accepted', $outcomes);
        $this->assertSame('CANCELLED', $po->fresh()->status);
        $this->assertSame('CANCELLED', $gr->fresh()->status);
        $this->assertSame(0, LocalGoodsReceipt::where('local_purchase_order_id', $po->id)->where('status', 'AVAILABLE')->count());
    }

    private function fixtures(): array
    {
        Storage::fake('private');
        Notification::fake();
        $supplier = User::factory()->create();
        $supplier->supplierScopes()->create(['scope' => 'local']);
        Supplier::create(['user_id' => $supplier->id, 'company_name' => 'Recovery Race', 'address' => 'Jakarta',
            'phone' => '1', 'npwp' => '1', 'category' => 'Parts', 'is_pkp' => false, 'payment_term_days' => 30]);
        $finance = User::factory()->create(['role' => 'finance']);
        $masters = app(LocalProcurementMasterService::class);
        $po = $masters->createPurchaseOrder($finance, ['supplier_id' => $supplier->id, 'po_number' => 'PO-RACE-RESTORE',
            'po_date' => '2026-10-01', 'total_amount' => '1000.00']);
        $gr = $masters->createGoodsReceipt($finance, $po, ['gr_number' => 'GR-RACE-RESTORE', 'gr_date' => '2026-10-01', 'qty' => '1', 'uom' => 'pcs']);

        return [$supplier, $finance, $po, $gr];
    }

    private function race(User $actor, array $jobs): array
    {
        $start = microtime(true) + 2;
        $processes = array_map(fn ($job) => new Process([PHP_BINARY, base_path('tests/Support/cancelled-recovery-worker.php'),
            (string) $actor->id, (string) $job[0], $job[1], (string) $start, Storage::disk('private')->path('')], base_path(), ['APP_ENV' => 'testing']), $jobs);
        foreach ($processes as $process) {
            $process->start();
        }
        foreach ($processes as $process) {
            $process->wait();
            $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
        }

        return array_map(fn ($process) => trim($process->getOutput()), $processes);
    }
}
