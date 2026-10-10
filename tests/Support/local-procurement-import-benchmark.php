<?php

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
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\Process\Process;

// Never run this capacity probe against application data. An explicit isolated
// schema is available when another task is using the repository's shared test DB.
$benchmarkDatabase = getenv('LOCAL_IMPORT_BENCH_DB') ?: 'adasi_portal_test';
if (! in_array($benchmarkDatabase, ['adasi_portal_test', 'adasi_import_formats_test'], true)) {
    throw new RuntimeException('Unsafe benchmark database name.');
}
foreach (['APP_ENV' => 'testing', 'DB_DATABASE' => $benchmarkDatabase, 'DB_CONNECTION' => 'mysql', 'DB_URL' => '', 'QUEUE_CONNECTION' => 'database', 'BROADCAST_CONNECTION' => 'null', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'MAIL_MAILER' => 'array'] as $key => $value) {
    putenv($key.'='.$value);
    $_ENV[$key] = $_SERVER[$key] = $value;
}
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || DB::selectOne('SELECT DATABASE() AS db')->db !== $benchmarkDatabase) {
    throw new RuntimeException('Unsafe benchmark database.');
}
$kind = $argv[1] ?? 'GR';
$count = (int) ($argv[2] ?? 70000);
$historyCount = (int) ($argv[3] ?? 500000);
$commitBudget = (int) ($argv[4] ?? 60);
$scenario = $argv[5] ?? 'new';
$format = $argv[6] ?? 'xlsx';
if (! in_array($scenario, ['new', 'existing'], true)) {
    throw new RuntimeException('Invalid benchmark scenario.');
}
if ($commitBudget < 1 || $commitBudget > 300) {
    throw new RuntimeException('Invalid profiling budget.');
}
config(['local_procurement_imports.commit_seconds' => $commitBudget]);
if (! in_array($kind, ['PO', 'GR'], true) || ! in_array($format, ['xlsx', 'csv', 'xls'], true) || $count < 1 || $count > ($format === 'xls' ? 65535 : 70001) || $historyCount < 0 || $historyCount > 500000) {
    throw new RuntimeException('Invalid benchmark arguments.');
}
Storage::fake('private');
$actor = User::factory()->create(['role' => 'finance', 'is_active' => true]);
$supplier = User::factory()->create(['role' => 'supplier', 'is_active' => true]);
SupplierScope::create(['supplier_id' => $supplier->id, 'scope' => 'local']);
Supplier::create(['user_id' => $supplier->id, 'company_name' => 'PT Capacity '.$supplier->id, 'category' => 'Parts', 'is_pkp' => false, 'payment_term_days' => 30]);
$prefix = 'CAP-'.$actor->id.'-';
$parent = LocalPurchaseOrder::create(['po_number' => $prefix.'PARENT', 'supplier_id' => $supplier->id, 'po_date' => '2026-10-01', 'total_amount' => '100000000.00', 'currency' => 'IDR', 'status' => 'OPEN', 'source' => 'MANUAL']);
$metrics = ['kind' => $kind, 'format' => $format, 'rows' => $count, 'existing_gr' => $historyCount, 'database' => $benchmarkDatabase, 'commit_budget_seconds' => $commitBudget, 'scenario' => $scenario];
$phase = 'prepare';
$sql = [];
$plans = [];
$processStage = function (LocalProcurementImport $import, string $stage): array {
    $started = microtime(true);
    $deliveries = [];
    $seenAttempts = 0;
    do {
        $pending = DB::table('jobs')->where('queue', 'imports')->where('payload', 'like', '%'.$import->fresh()->owner_job_key.'%')->first(['attempts', 'available_at']);
        if ($pending && $pending->available_at <= time()) {
            $seenAttempts++;
            $attemptStarted = microtime(true);
            Artisan::call('queue:work', ['connection' => 'database', '--queue' => 'imports', '--once' => true, '--sleep' => 1, '--tries' => 3, '--timeout' => 300, '--memory' => 384]);
            $deliveries[] = ['attempt' => $seenAttempts, 'seconds' => round(microtime(true) - $attemptStarted, 3), 'status' => $import->fresh()->status];
        } else {
            usleep(200000);
        }
        $status = $import->fresh()->status;
        $active = $stage === 'preview' ? ['QUEUED', 'READING', 'VALIDATING'] : ['IMPORTING'];
        if (! in_array($status, $active, true)) {
            break;
        }
    } while (microtime(true) - $started < 600);

    return $deliveries;
};
DB::listen(function ($query) use (&$phase, &$sql, &$plans) {
    $sql[$phase]['queries'] = ($sql[$phase]['queries'] ?? 0) + 1;
    $sql[$phase]['sql_ms'] = ($sql[$phase]['sql_ms'] ?? 0) + $query->time;
    if (str_contains($query->sql, 'local_purchase_orders') && str_contains($query->sql, 'force index')) {
        $sql[$phase]['hinted_po_lookups'] = ($sql[$phase]['hinted_po_lookups'] ?? 0) + 1;
    }
    if (preg_match('/^(insert into|update|delete from|select.*?from)\s+`([a-z_]+)`/i', $query->sql, $shape)) {
        $operation = str_starts_with(strtolower($shape[1]), 'select') ? 'select' : strtolower($shape[1]);
        $key = $operation.' '.$shape[2];
        $sql[$phase]['query_shapes'][$key]['count'] = ($sql[$phase]['query_shapes'][$key]['count'] ?? 0) + 1;
        $sql[$phase]['query_shapes'][$key]['sql_ms'] = ($sql[$phase]['query_shapes'][$key]['sql_ms'] ?? 0) + $query->time;
        $sql[$phase]['query_shapes'][$key]['max_ms'] = max($sql[$phase]['query_shapes'][$key]['max_ms'] ?? 0, $query->time);
    }
    if ($phase === 'confirm' && $plans === [] && $query->time > 100 && count($query->bindings) >= 200 && ! str_contains($query->sql, 'force index')
        && str_starts_with($query->sql, 'select') && str_contains($query->sql, 'from `local_purchase_orders`') && str_contains($query->sql, '`po_number` in')) {
        $plans = ['captured' => true];
        $plans['ordinary'] = $query->connection->select('EXPLAIN '.$query->sql, $query->bindings);
        $forced = str_replace('from `local_purchase_orders`', 'from `local_purchase_orders` force index (`local_purchase_orders_po_number_unique`)', $query->sql);
        $plans['forced'] = $query->connection->select('EXPLAIN '.$forced, $query->bindings);
    }
});
try {
    echo json_encode(['phase' => $phase, 'rows' => $count, 'kind' => $kind]).PHP_EOL;
    // Fixture preparation is outside measured import timing. One transaction
    // avoids thousands of unrelated fixture fsyncs on the local test disk.
    DB::beginTransaction();
    for ($offset = 0; $offset < $historyCount; $offset += 500) {
        $batch = [];
        for ($i = $offset; $i < min($offset + 500, $historyCount); $i++) {
            $batch[] = ['gr_number' => $prefix.'HISTORY-'.$i, 'local_purchase_order_id' => $parent->id, 'gr_date' => '2026-09-30', 'qty' => '1.0000', 'uom' => 'pcs', 'status' => 'AVAILABLE', 'source' => 'IMPORT'];
        }
        LocalGoodsReceipt::insert($batch);
    }
    if ($scenario === 'existing') {
        for ($offset = 0; $offset < $count; $offset += 500) {
            $batch = [];
            for ($i = $offset; $i < min($offset + 500, $count); $i++) {
                $batch[] = $kind === 'GR'
                    ? ['gr_number' => $prefix.'NEW-GR-'.$i, 'local_purchase_order_id' => $parent->id, 'gr_date' => '2026-10-01', 'qty' => '10.1250', 'uom' => 'kg', 'status' => 'AVAILABLE', 'source' => 'IMPORT']
                    : ['po_number' => $prefix.'NEW-PO-'.$i, 'supplier_id' => $supplier->id, 'po_date' => '2026-10-01', 'total_amount' => '999999999999.99', 'currency' => 'IDR', 'status' => 'OPEN', 'source' => 'IMPORT', 'description' => 'Imported via Infor ERP Purchase Order'];
            }
            if ($kind === 'GR') {
                LocalGoodsReceipt::insert($batch);
            } else {
                LocalPurchaseOrder::insert($batch);
            }
        }
    }
    DB::commit();
    $fixture = Storage::disk('private')->path('capacity-fixture.'.$format);
    $writer = $format === 'xlsx' ? new Writer : null;
    $stream = $format === 'csv' ? fopen($fixture, 'wb') : null;
    $writer?->openToFile($fixture);
    $writeRow = function (array $values) use ($writer, $stream) {
        if ($writer) {
            $writer->addRow(Row::fromValues($values));
        } elseif ($stream) {
            fputcsv($stream, $values, ',', '"', '');
        }
    };
    $writeRow(array_fill(0, 21, ''));
    for ($i = 0; $format !== 'xls' && $i < $count; $i++) {
        $row = array_fill(0, 21, null);
        if ($kind === 'GR') {
            $row[8] = 'Material specification with unique text '.$i;
            $row[9] = $prefix.'NEW-GR-'.$i;
            $row[11] = $parent->po_number;
            $row[15] = 10.125;
            $row[16] = 'KG';
            $row[19] = '2026-10-01';
        } else {
            $row[4] = $prefix.'NEW-PO-'.$i;
            $row[6] = 'PT Capacity '.$supplier->id;
            $row[9] = '2026-10-01';
            $row[10] = '999999999999.99';
        }
        $writeRow($row);
    }
    $writer?->close();
    if ($stream) {
        fclose($stream);
    }
    if ($format === 'xls') {
        $metadataPath = Storage::disk('private')->path('capacity-fixture.json');
        file_put_contents($metadataPath, json_encode(['output' => $fixture, 'kind' => $kind, 'count' => $count,
            'prefix' => $prefix, 'supplier_name' => 'PT Capacity '.$supplier->id, 'parent' => $parent->po_number], JSON_THROW_ON_ERROR));
        $preparer = new Process([PHP_BINARY, '-d', 'memory_limit=1024M', __DIR__.'/local-procurement-import-fixture.php', $metadataPath]);
        $preparer->setTimeout(300);
        $preparer->mustRun();
    }
    unset($writer, $writeRow, $preparer);
    gc_collect_cycles();
    $metrics['file_bytes'] = filesize($fixture);
    $service = app(LocalProcurementImportService::class);
    $phase = 'dispatch';
    $start = microtime(true);
    [$import, $token] = $service->start($actor, new UploadedFile($fixture, 'capacity.'.$format, null, null, true), $kind);
    $metrics['accept_seconds'] = round(microtime(true) - $start, 3);
    $metrics['payload_bytes'] = strlen(DB::table('jobs')->where('queue', 'imports')->latest('id')->value('payload') ?? '');
    $phase = 'preview';
    $start = microtime(true);
    $metrics['preview_deliveries'] = $processStage($import, 'preview');
    $metrics['preview_seconds'] = round(microtime(true) - $start, 3);
    echo json_encode(['phase' => $phase, 'seconds' => $metrics['preview_seconds'], 'status' => $import->fresh()->status]).PHP_EOL;
    if ($import->fresh()->status === 'READY') {
        $service->confirm($actor, $token, $kind);
        $phase = 'confirm';
        $start = microtime(true);
        $metrics['confirm_deliveries'] = $processStage($import, 'confirm');
        $metrics['confirm_seconds'] = round(microtime(true) - $start, 3);
    }
    $metrics['final_status'] = $import->fresh()->status;
    $metrics['failure'] = $import->fresh()->failure;
    $metrics['summary'] = $import->fresh()->summary;
    $metrics['peak_php_mb'] = round(memory_get_peak_usage(true) / 1048576, 2);
    $metrics['phases'] = $sql;
    $metrics['slow_po_lookup_plans'] = $plans;
    $metrics['created_gr'] = LocalGoodsReceipt::where('created_by', $actor->id)->count();
    $metrics['created_po'] = LocalPurchaseOrder::where('created_by', $actor->id)->count();
    $metrics['creation_audits'] = LocalFinanceAuditLog::where('actor_id', $actor->id)->whereIn('action', ['po_created', 'gr_created'])->count();
    echo json_encode($metrics, JSON_PRETTY_PRINT).PHP_EOL;
} finally {
    if (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
    foreach (LocalProcurementImport::where('user_id', $actor->id)->get() as $record) {
        // Remove only this probe's jobs, never another test's queue work.
        DB::table('jobs')->where('queue', 'imports')->where('payload', 'like', '%'.$record->owner_job_key.'%')->delete();
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
    Storage::disk('private')->delete('capacity-fixture.'.$format);
    Storage::disk('private')->delete('capacity-fixture.json');
}
exit(($metrics['final_status'] ?? '') === 'COMPLETED' || ($count === 70001 && ($metrics['final_status'] ?? '') === 'VALIDATION_FAILED') ? 0 : 1);
