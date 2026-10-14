<?php

use App\Models\LocalGoodsReceipt;
use App\Models\LocalInvoice;
use App\Models\LocalPurchaseOrder;
use App\Models\User;
use App\Services\LocalInvoice\InvoiceSubmissionService;
use App\Services\LocalInvoice\LocalProcurementMasterService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(['database.default' => 'mysql', 'database.connections.mysql.database' => 'adasi_portal_test',
    'database.connections.mysql.username' => 'root', 'database.connections.mysql.password' => '',
    'database.connections.mysql.url' => null, 'filesystems.disks.private.root' => $argv[5],
    'cache.default' => 'array', 'broadcasting.default' => 'null', 'queue.default' => 'sync']);
DB::purge('mysql');
if (DB::connection()->getDatabaseName() !== 'adasi_portal_test') {
    exit(3);
}
Notification::fake();
try {
    $actor = User::findOrFail($argv[1]);
    $target = $argv[2];
    while (microtime(true) < (float) $argv[4]) {
        usleep(1000);
    }
    $data = ['reason' => 'Accidental portal cancellation', 'business_document_valid' => '1'];
    $masters = app(LocalProcurementMasterService::class);
    if ($argv[3] === 'po') {
        $masters->restorePurchaseOrder($actor, LocalPurchaseOrder::findOrFail($target), $data);
    } elseif ($argv[3] === 'gr') {
        $masters->restoreGoodsReceipt($actor, LocalGoodsReceipt::findOrFail($target), $data);
    } elseif ($argv[3] === 'cancel-po') {
        $masters->cancelPurchaseOrder($actor, LocalPurchaseOrder::findOrFail($target));
    } else {
        $invoice = LocalInvoice::findOrFail($target);
        $gr = $invoice->localPurchaseOrder->goodsReceipts()->firstOrFail();
        $payload = ['invoice_number' => $invoice->invoice_number, 'invoice_date' => '2026-10-01',
            'local_purchase_order_id' => $gr->local_purchase_order_id, 'goods_receipt_ids' => [$gr->id],
            'invoice_amount' => '100.00', 'tax_amount' => '0.00', 'ppn_scheme' => '0%',
            'kept_document_ids' => $invoice->latestRevision->documents->modelKeys(),
            'recovery_reason' => $data['reason'], 'business_document_valid' => '1'];
        app(InvoiceSubmissionService::class)->resubmit($actor, $invoice, $payload, []);
    }
    echo 'accepted';
} catch (ValidationException $exception) {
    echo 'rejected';
} catch (Throwable $exception) {
    fwrite(STDERR, get_class($exception).': '.$exception->getMessage());
    exit(2);
}
