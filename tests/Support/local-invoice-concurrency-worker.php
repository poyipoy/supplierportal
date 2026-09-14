<?php

use App\Models\LocalInvoice;
use App\Models\User;
use App\Services\LocalInvoice\InvoiceSubmissionService;
use App\Services\LocalInvoice\InvoiceWorkflowService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

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
    if ($argv[3] === 'submit') {
        app(InvoiceSubmissionService::class)->submit($actor, ['invoice_number' => $argv[2], 'invoice_date' => today()->format('Y-m-d'), 'po_source' => 'MANUAL', 'manual_po_number' => 'Concurrent manual PO', 'manual_gr_reference' => 'GR-CONCURRENT-001', 'po_number' => 'Concurrent manual PO', 'invoice_amount' => '1.00', 'tax_amount' => '0.00'], ['invoice' => UploadedFile::fake()->image('invoice.png'), 'tax_invoice' => UploadedFile::fake()->image('tax.png')]);
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
