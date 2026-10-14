<?php

use App\Models\LocalInvoice;
use App\Models\User;
use App\Services\LocalInvoice\InvoiceSubmissionService;
use App\Services\LocalInvoice\InvoiceWorkflowService;
use App\Services\LocalInvoice\LocalProcurementMasterService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Tests\Support\NativeFileFixtures;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(['database.default' => 'mysql', 'database.connections.mysql.database' => 'adasi_portal_test', 'database.connections.mysql.username' => 'root', 'database.connections.mysql.password' => '', 'database.connections.mysql.url' => null, 'filesystems.disks.private.root' => $argv[4], 'cache.default' => 'array', 'broadcasting.default' => 'null', 'queue.default' => 'sync']);
DB::purge('mysql');
if (DB::connection()->getDatabaseName() !== 'adasi_portal_test') {
    exit(3);
}
Notification::fake();
try {
    $actor = User::findOrFail($argv[1]);
    // Both workers wait for a shared launch time, exercising separate connections.
    while (microtime(true) < (float) $argv[5]) {
        usleep(1000);
    }
    if ($argv[3] === 'submit-po' || $argv[3] === 'resubmit-po') {
        // Ceiling race: argv[6] = PO id, argv[7] = GR id, argv[8] = DPP.
        $payload = ['invoice_date' => '2026-09-10', 'local_purchase_order_id' => (int) $argv[6], 'goods_receipt_ids' => [(int) $argv[7]], 'invoice_amount' => $argv[8], 'tax_amount' => '0.00', 'ppn_scheme' => '0%'];
        $files = ['invoice' => NativeFileFixtures::upload('invoice.pdf'), 'tax_invoice' => NativeFileFixtures::upload('tax.pdf')];
        if ($argv[3] === 'submit-po') {
            app(InvoiceSubmissionService::class)->submit($actor, ['invoice_number' => $argv[2]] + $payload, $files);
        } else {
            $invoice = LocalInvoice::findOrFail($argv[2]);
            app(InvoiceSubmissionService::class)->resubmit($actor, $invoice, ['invoice_number' => $invoice->invoice_number] + $payload, $files);
        }
    } elseif ($argv[3] === 'submit') {
        // A new invoice must bind to an authoritative Local PO plus a whole GR.
        // Namespace the numbers per supplier so parallel workers never collide.
        $concurrentOperator = User::factory()->create(['role' => 'finance']);
        $concurrentMasters = app(LocalProcurementMasterService::class);
        $concurrentPo = $concurrentMasters->createPurchaseOrder($concurrentOperator, ['supplier_id' => $actor->id, 'po_number' => 'PO-CONC-'.$actor->id, 'po_date' => '2026-09-01', 'total_amount' => '1000.00']);
        $concurrentGr = $concurrentMasters->createGoodsReceipt($concurrentOperator, $concurrentPo, ['gr_number' => 'GR-CONC-'.$actor->id, 'gr_date' => '2026-09-02', 'uom' => 'pcs', 'qty' => 1.0]);
        app(InvoiceSubmissionService::class)->submit($actor, ['invoice_number' => $argv[2], 'invoice_date' => today()->format('Y-m-d'), 'local_purchase_order_id' => $concurrentPo->id, 'goods_receipt_ids' => [$concurrentGr->id], 'invoice_amount' => '1.00', 'tax_amount' => '0.00', 'ppn_scheme' => '0%'], ['invoice' => NativeFileFixtures::upload('invoice.pdf'), 'tax_invoice' => NativeFileFixtures::upload('tax.pdf')]);
    } else {
        $invoice = LocalInvoice::findOrFail($argv[2]);
        app(InvoiceWorkflowService::class)->act($actor, $invoice, $argv[3], ['notes' => 'Concurrent action']);
    }
    echo 'accepted';
} catch (ValidationException $exception) {
    echo 'rejected';
} catch (Throwable $exception) {
    fwrite(STDERR, get_class($exception).': '.$exception->getMessage());
    exit(2);
}
