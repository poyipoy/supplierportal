<?php

use App\Models\LocalInvoice;
use App\Models\User;
use App\Services\LocalInvoice\LocalGrReservationService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config([
    'database.default' => 'mysql',
    'database.connections.mysql.database' => 'adasi_portal_test',
    'database.connections.mysql.username' => 'root',
    'database.connections.mysql.password' => '',
    'database.connections.mysql.url' => null,
    'cache.default' => 'array',
    'broadcasting.default' => 'null',
    'queue.default' => 'sync',
]);
DB::purge('mysql');

if (DB::connection()->getDatabaseName() !== 'adasi_portal_test') {
    exit(3);
}

try {
    $supplier = User::findOrFail($argv[1]);
    $invoice = LocalInvoice::findOrFail($argv[2]);
    while (microtime(true) < (float) $argv[5]) {
        usleep(1000);
    }

    app(LocalGrReservationService::class)->reserve(
        $supplier,
        $invoice,
        (int) $argv[3],
        [(int) $argv[4]],
    );

    echo 'accepted';
} catch (ValidationException) {
    echo 'rejected';
} catch (Throwable $exception) {
    fwrite(STDERR, get_class($exception).': '.$exception->getMessage());
    exit(2);
}
