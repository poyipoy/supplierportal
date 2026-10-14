<?php

namespace Tests\Feature\LocalInvoice;

use App\Models\LocalFinanceAuditLog;
use App\Models\LocalPurchaseOrder;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ManualPoPrConcurrencyTest extends TestCase
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

    public function test_concurrent_manual_edits_cannot_replace_the_winning_pr(): void
    {
        $vendor = User::factory()->create(['role' => 'supplier']);
        $vendor->supplierScopes()->create(['scope' => 'local']);
        Supplier::create(['user_id' => $vendor->id, 'company_name' => 'Concurrent Manual Vendor', 'category' => 'Parts', 'payment_term_days' => 30]);
        $po = LocalPurchaseOrder::create(['po_number' => 'MANUAL-RACE', 'supplier_id' => $vendor->id, 'po_date' => '2026-10-14', 'total_amount' => '1000.00', 'currency' => 'IDR', 'status' => 'OPEN']);
        $start = microtime(true) + 2;
        $workers = [];
        foreach (['PR-FIRST', 'PR-SECOND'] as $pr) {
            $actor = User::factory()->create(['role' => 'finance']);
            $workers[] = new Process([PHP_BINARY, base_path('tests/Support/manual-po-pr-worker.php'), (string) $po->id, (string) $actor->id, $pr, (string) $start], base_path(), ['APP_ENV' => 'testing']);
        }
        try {
            foreach ($workers as $worker) {
                $worker->start();
            }
            foreach ($workers as $worker) {
                $worker->wait();
                $this->assertTrue($worker->isSuccessful(), $worker->getErrorOutput());
            }
            $results = array_map(fn ($worker) => trim($worker->getOutput()), $workers);
            sort($results);
            $this->assertSame(['accepted', 'conflict'], $results);
            $this->assertContains($po->fresh()->pr_number, ['PR-FIRST', 'PR-SECOND']);
            $this->assertSame(1, LocalFinanceAuditLog::where('action', 'po_pr_filled')->count());
            $this->assertSame(1, LocalFinanceAuditLog::where('action', 'po_updated')->count());
        } finally {
            foreach ($workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop();
                }
            }
        }
    }
}
