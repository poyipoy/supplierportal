<?php

use App\Jobs\ProcessLocalProcurementImport;
use App\Models\LocalProcurementImport;
use App\Services\LocalInvoice\LocalProcurementImportService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! app()->environment('testing')) {
    exit(3);
}
config(['database.default' => 'mysql', 'database.connections.mysql.database' => 'adasi_portal_test',
    'database.connections.mysql.username' => 'root', 'database.connections.mysql.password' => '',
    'database.connections.mysql.url' => null, 'cache.stores.file.path' => $argv[2], 'broadcasting.default' => 'null']);
DB::purge('mysql');
if (DB::connection()->getDatabaseName() !== 'adasi_portal_test') {
    exit(3);
}
$root = realpath($argv[2]);
$allowed = realpath(storage_path('framework/testing')).DIRECTORY_SEPARATOR;
if (! $root || ! str_starts_with($root.DIRECTORY_SEPARATOR, $allowed)) {
    exit(4);
}
$mode = $argv[3];
$reads = 0;
DB::listen(function ($query) use (&$reads, $root, $mode) {
    if (! str_starts_with($query->sql, 'select * from `local_procurement_import_records`')) {
        return;
    }
    $reads++;
    if ($reads !== 2 && ! ($mode === 'retry' && $reads === 3)) {
        return;
    }
    $suffix = $reads === 3 ? '.retry' : '';
    file_put_contents($root.'/ready'.$suffix, 'ready');
    $deadline = microtime(true) + 20;
    while (! is_file($root.'/release'.$suffix)) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Progress test barrier timed out.');
        }
        usleep(20000);
    }
    if ($mode === 'fail') {
        throw new RuntimeException('Injected save failure.');
    }
    if ($mode === 'retry' && $reads === 2) {
        throw new PDOException('Deadlock found when trying to get lock; try restarting transaction');
    }
});
$import = LocalProcurementImport::findOrFail((int) $argv[1]);
try {
    app(LocalProcurementImportService::class)->commit($import->id, $import->owner_job_key);
} catch (Throwable $exception) {
    $job = new ProcessLocalProcurementImport($import->id, 'confirm');
    $job->runKey = $import->owner_job_key;
    $job->failed($exception);
    if ($mode !== 'fail') {
        throw $exception;
    }
}
echo $import->fresh()->status;
