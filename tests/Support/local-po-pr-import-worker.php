<?php

use App\Services\LocalInvoice\LocalProcurementImportService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(['database.default' => 'mysql', 'database.connections.mysql.database' => 'adasi_portal_test',
    'database.connections.mysql.username' => 'root', 'database.connections.mysql.password' => '', 'database.connections.mysql.url' => null,
    'filesystems.disks.private.root' => $argv[3], 'cache.default' => 'array', 'broadcasting.default' => 'null', 'queue.default' => 'sync', 'session.driver' => 'array']);
DB::purge('mysql');
if (DB::connection()->getDatabaseName() !== 'adasi_portal_test') {
    exit(3);
}
Queue::fake();
while (microtime(true) < (float) $argv[2]) {
    usleep(1000);
}
try {
    app(LocalProcurementImportService::class)->commit((int) $argv[1]);
    echo 'finished';
} catch (Throwable $exception) {
    fwrite(STDERR, get_class($exception).': '.$exception->getMessage());
    exit(2);
}
