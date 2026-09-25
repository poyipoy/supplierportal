<?php

namespace Tests\Feature\LocalInvoice;

use App\Models\LocalGoodsReceipt;
use App\Models\LocalInvoice;
use App\Models\Supplier;
use App\Models\SupplierScope;
use App\Models\User;
use App\Services\LocalInvoice\LocalProcurementMasterService;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class LocalGrReservationConcurrencyTest extends TestCase
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

    public function test_two_connections_cannot_reserve_the_same_whole_gr(): void
    {
        $supplier = User::factory()->create(['role' => 'supplier', 'is_active' => true]);
        SupplierScope::create(['supplier_id' => $supplier->id, 'scope' => 'local']);
        Supplier::create(['user_id' => $supplier->id, 'company_name' => 'Concurrent GR Supplier', 'category' => 'Parts', 'payment_term_days' => 30]);
        $finance = User::factory()->create(['role' => 'finance', 'is_active' => true]);
        $master = app(LocalProcurementMasterService::class);
        $po = $master->createPurchaseOrder($finance, ['supplier_id' => $supplier->id, 'po_number' => 'PO-CONCURRENT-GR', 'po_date' => '2026-09-15', 'total_amount' => '100.00']);
        $gr = $master->createGoodsReceipt($finance, $po, ['gr_number' => 'GR-CONCURRENT-GR', 'gr_date' => '2026-09-15', 'qty' => 10.0]);

        $makeInvoice = function (string $number) use ($supplier, $po): LocalInvoice {
            return LocalInvoice::create([
                'submission_number' => 'SUB-'.$number,
                'supplier_id' => $supplier->id,
                'invoice_number' => $number,
                'invoice_date' => '2026-09-15',
                'po_number' => $po->po_number,
                'currency' => 'IDR',
                'invoice_amount' => '100.00',
                'tax_amount' => '0.00',
                'payment_term_days_snapshot' => 30,
                'revision_number' => 1,
                'status' => LocalInvoice::STATUS_WAITING_PHYSICAL_DOCUMENT,
                'submitted_at' => now(),
            ]);
        };
        $first = $makeInvoice('INV-CONCURRENT-1');
        $second = $makeInvoice('INV-CONCURRENT-2');

        $start = microtime(true) + 2;
        $processes = [$first, $second];
        $processes = array_map(fn (LocalInvoice $invoice) => new Process([
            PHP_BINARY,
            base_path('tests/Support/local-gr-reservation-worker.php'),
            (string) $supplier->id,
            (string) $invoice->id,
            (string) $po->id,
            (string) $gr->id,
            (string) $start,
        ], base_path(), ['APP_ENV' => 'testing']), $processes);
        foreach ($processes as $process) {
            $process->start();
        }
        foreach ($processes as $process) {
            $process->wait();
            $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
        }

        $this->assertEqualsCanonicalizing(['accepted', 'rejected'], array_map(fn (Process $process) => trim($process->getOutput()), $processes));
        $this->assertSame(1, LocalGoodsReceipt::where('status', LocalGoodsReceipt::STATUS_RESERVED)->count());
        $this->assertSame(1, LocalGoodsReceipt::whereNotNull('current_invoice_id')->count());
        $this->assertSame(1, DB::table('local_invoice_goods_receipts')->where('state', 'RESERVED')->count());
    }
}
