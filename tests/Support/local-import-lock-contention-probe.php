<?php

/*
 * Lock-contention probe: a large PO/GR import confirm against a concurrent
 * supplier invoice submission on an overlapping PO. Test database only.
 *
 *   php tests/Support/local-import-lock-contention-probe.php GR <rows>
 *   php tests/Support/local-import-lock-contention-probe.php PO <rows>
 *
 * The invoice side runs in a child process and replays the lock order of
 * InvoiceSubmissionService::submit(): users(supplier) FOR UPDATE ->
 * suppliers(user_id) FOR UPDATE -> local_purchase_orders(id) FOR UPDATE.
 * It does not create an invoice. The probe reports the wait and any lock
 * error on both sides, then removes its own fixture rows.
 */

use App\Models\LocalFinanceAuditLog;
use App\Models\LocalGoodsReceipt;
use App\Models\LocalProcurementImport;
use App\Models\LocalPurchaseOrder;
use App\Models\Supplier;
use App\Models\SupplierScope;
use App\Models\User;
use App\Services\LocalInvoice\LocalProcurementImportService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\Process\Process;

$benchmarkDatabase = getenv('LOCAL_IMPORT_BENCH_DB') ?: 'adasi_portal_test';
if (! in_array($benchmarkDatabase, ['adasi_portal_test', 'adasi_import_formats_test'], true)) {
    throw new RuntimeException('Unsafe benchmark database name.');
}
foreach (['APP_ENV' => 'testing', 'DB_DATABASE' => $benchmarkDatabase, 'DB_CONNECTION' => 'mysql', 'DB_URL' => '', 'QUEUE_CONNECTION' => 'sync', 'BROADCAST_CONNECTION' => 'null', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'MAIL_MAILER' => 'array'] as $key => $value) {
    putenv($key.'='.$value);
    $_ENV[$key] = $_SERVER[$key] = $value;
}
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || DB::selectOne('SELECT DATABASE() AS db')->db !== $benchmarkDatabase) {
    throw new RuntimeException('Unsafe benchmark database.');
}

// ---- Child: invoice-side lock sequence -------------------------------------
if (($argv[1] ?? '') === 'invoice-side') {
    [, , $supplierId, $poId, $marker, $resultPath, $holdUserFirst] = $argv;
    $deadline = microtime(true) + 120;
    while (! is_file($marker) && microtime(true) < $deadline) {
        usleep(20000);
    }
    $result = ['started_after_marker' => is_file($marker)];
    $started = microtime(true);
    try {
        DB::transaction(function () use ($supplierId, $poId, $holdUserFirst, &$result, $started) {
            User::whereKey($supplierId)->lockForUpdate()->firstOrFail();
            $result['user_lock_ms'] = round((microtime(true) - $started) * 1000);
            if ($holdUserFirst === '1') {
                usleep(500000); // let the import reach its supplier shared lock
            }
            Supplier::where('user_id', $supplierId)->lockForUpdate()->first();
            LocalPurchaseOrder::whereKey($poId)->lockForUpdate()->firstOrFail();
            $result['po_lock_ms'] = round((microtime(true) - $started) * 1000);
        });
        $result['outcome'] = 'acquired';
    } catch (Throwable $e) {
        $result['outcome'] = 'error';
        $result['error_code'] = (string) (($e->errorInfo ?? [])[1] ?? $e->getCode());
        $result['message'] = mb_substr($e->getMessage(), 0, 160);
    }
    $result['total_ms'] = round((microtime(true) - $started) * 1000);
    file_put_contents($resultPath, json_encode($result));
    exit(0);
}

// ---- Parent: import confirm -------------------------------------------------
$kind = $argv[1] ?? 'GR';
$count = (int) ($argv[2] ?? 70000);
if (! in_array($kind, ['PO', 'GR'], true) || $count < 1 || $count > 70000) {
    throw new RuntimeException('Usage: GR|PO <rows 1..70000>');
}
Storage::fake('private');
// Run preview and commit explicitly; a sync queue would commit inside confirm().
Queue::fake();
$actor = User::factory()->create(['role' => 'finance', 'is_active' => true]);
$supplier = User::factory()->create(['role' => 'supplier', 'is_active' => true]);
SupplierScope::create(['supplier_id' => $supplier->id, 'scope' => 'local']);
Supplier::create(['user_id' => $supplier->id, 'company_name' => 'PT Contention '.$supplier->id, 'category' => 'Parts', 'is_pkp' => false, 'payment_term_days' => 30]);
$prefix = 'LCP-'.$actor->id.'-';
$parent = LocalPurchaseOrder::create(['po_number' => $prefix.'PARENT', 'supplier_id' => $supplier->id, 'po_date' => '2026-10-01', 'total_amount' => '100000000.00', 'currency' => 'IDR', 'status' => 'OPEN', 'source' => 'MANUAL']);
$marker = storage_path('framework/testing/lock-probe-'.$actor->id.'.marker');
$resultPath = storage_path('framework/testing/lock-probe-'.$actor->id.'.json');
@unlink($marker);
@unlink($resultPath);
$report = ['kind' => $kind, 'rows' => $count, 'database' => $benchmarkDatabase, 'lock_wait_timeout_s' => (int) DB::selectOne('select @@innodb_lock_wait_timeout t')->t];
$child = null;
try {
    $fixture = Storage::disk('private')->path('lock-probe.xlsx');
    $writer = new Writer;
    $writer->openToFile($fixture);
    $writer->addRow(Row::fromValues(array_fill(0, 21, '')));
    for ($i = 0; $i < $count; $i++) {
        $row = array_fill(0, 21, null);
        if ($kind === 'GR') {
            $row[8] = 'Probe material '.$i;
            $row[9] = $prefix.'GR-'.$i;
            $row[11] = $parent->po_number;
            $row[15] = 1;
            $row[16] = 'KG';
            $row[19] = '2026-10-01';
        } else {
            // The first row repeats the registered parent PO, so the import
            // locks it before creating the new POs of the same supplier.
            $row[4] = $i === 0 ? $parent->po_number : $prefix.'PO-'.$i;
            $row[6] = 'PT Contention '.$supplier->id;
            $row[9] = '2026-10-01';
            $row[10] = $i === 0 ? '100000000.00' : '1000.00';
        }
        $writer->addRow(Row::fromValues($row));
    }
    $writer->close();

    $service = app(LocalProcurementImportService::class);
    [$import, $token] = $service->start($actor, new UploadedFile($fixture, 'probe.xlsx', null, null, true), $kind);
    $service->preview($import->id);
    if ($import->fresh()->status !== 'READY') {
        throw new RuntimeException('Preview did not reach READY: '.$import->fresh()->failure);
    }
    $service->confirm($actor, $token, $kind);

    // Signal the child as soon as the commit has locked the parent PO, and
    // count how often the commit transaction starts (deadlock restarts).
    $transactionStarts = 0;
    DB::listen(function ($query) use ($marker, $parent, &$transactionStarts) {
        if (str_contains($query->sql, 'from `users`') && str_contains($query->sql, 'for update')) {
            $transactionStarts++;
        }
        if (! is_file($marker) && str_contains($query->sql, 'for update') && str_contains($query->sql, 'local_purchase_orders')
            && in_array($parent->id, $query->bindings, false)) {
            file_put_contents($marker, (string) microtime(true));
        }
    });
    $child = new Process([PHP_BINARY, __FILE__, 'invoice-side', (string) $supplier->id, (string) $parent->id, $marker, $resultPath, $kind === 'PO' ? '1' : '0']);
    $child->setTimeout(180);
    $child->start();
    usleep(1500000); // child bootstrap
    $started = microtime(true);
    try {
        $service->commit($import->id);
        $report['import_outcome'] = $import->fresh()->status;
    } catch (Throwable $e) {
        $report['import_outcome'] = 'exception';
        $report['import_error'] = mb_substr($e->getMessage(), 0, 160);
    }
    $report['import_commit_ms'] = round((microtime(true) - $started) * 1000);
    $report['import_transaction_starts'] = $transactionStarts;
    $child->wait();
    $report['invoice_side'] = json_decode((string) @file_get_contents($resultPath), true);
    $report['child_stderr'] = mb_substr($child->getErrorOutput(), 0, 300);
    $report['created'] = ['po' => LocalPurchaseOrder::where('created_by', $actor->id)->count(), 'gr' => LocalGoodsReceipt::where('created_by', $actor->id)->count(),
        'audits' => LocalFinanceAuditLog::where('actor_id', $actor->id)->whereIn('action', ['po_created', 'gr_created'])->count()];
    echo json_encode($report, JSON_PRETTY_PRINT).PHP_EOL;
} finally {
    if ($child?->isRunning()) {
        $child->stop(5);
    }
    foreach (LocalProcurementImport::where('user_id', $actor->id)->get() as $record) {
        foreach ($record->attachments as $attachment) {
            Storage::disk('private')->delete($attachment->file_path);
            $attachment->delete();
        }
        $record->delete();
    }
    LocalFinanceAuditLog::where('actor_id', $actor->id)->delete();
    LocalGoodsReceipt::whereIn('local_purchase_order_id', LocalPurchaseOrder::where('supplier_id', $supplier->id)->select('id'))->delete();
    LocalPurchaseOrder::where('supplier_id', $supplier->id)->delete();
    Supplier::where('user_id', $supplier->id)->delete();
    SupplierScope::where('supplier_id', $supplier->id)->delete();
    $supplier->delete();
    $actor->delete();
    @unlink($marker);
    @unlink($resultPath);
}
